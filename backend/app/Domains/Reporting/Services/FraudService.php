<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingPassenger;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Reporting\Models\FraudFlag;
use App\Domains\Settings\Services\SettingsService;

/**
 * Scores a booking once it is paid, using the rules in Admin settings →
 * Fraud checks. A score at or above the review level opens a flag on the
 * Fraud checks list and emails the alert list, so staff can look before
 * the travel date. It never blocks or cancels a booking by itself.
 */
class FraudService
{
    /** @return array{score: int, reasons: array<int, string>} */
    public function score(Booking $booking): array
    {
        $c = app(SettingsService::class)->get('fraud');
        $score = 0;
        $reasons = [];
        $since = now()->subDay();
        $amount = (float) $booking->total_amount;
        $high = (float) ($c['high_value_amount'] ?? 100000);

        $by = $booking->createdBy;
        $isNew = ! $by || $by->is_guest || ($by->role === \App\Models\User::ROLE_CUSTOMER && $by->created_at && $by->created_at->gt(now()->subHours((int) ($c['new_account_hours'] ?? 24))));
        if ($high > 0 && $amount >= $high && $isNew) {
            $score += 40;
            $reasons[] = 'High value ('.$booking->currency.' '.number_format($amount).') from a new or guest account';
        }

        $email = strtolower((string) $booking->contact_email);
        $domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';
        if ($domain && in_array($domain, array_map('strtolower', (array) ($c['blocked_email_domains'] ?? [])), true)) {
            $score += 25;
            $reasons[] = "Throw-away email address ({$domain})";
        }

        $sameContact = Booking::withoutTenantScope()->where('id', '!=', $booking->id)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->when($email, fn ($q) => $q->orWhere('contact_email', $booking->contact_email))
                ->when($booking->contact_phone, fn ($q) => $q->orWhere('contact_phone', $booking->contact_phone)))
            ->when(! $email && ! $booking->contact_phone, fn ($q) => $q->whereRaw('1 = 0'));
        $contactIds = (clone $sameContact)->pluck('id')->push($booking->id);
        $maxContact = (int) ($c['max_bookings_per_contact_day'] ?? 5);
        if ($contactIds->count() > $maxContact) {
            $score += 30;
            $reasons[] = $contactIds->count().' bookings from the same email or phone in 24 hours';
        }

        $failed = PaymentTransaction::withoutTenantScope()->whereIn('booking_id', $contactIds)->where('status', PaymentTransaction::STATUS_FAILED)
            ->where('created_at', '>=', $since)->count();
        if ($failed >= (int) ($c['max_failed_payments_day'] ?? 3)) {
            $score += 30;
            $reasons[] = "{$failed} failed payment attempts in 24 hours";
        }

        $names = BookingPassenger::query()->where('booking_id', $booking->id)->pluck('name')->filter()->map(fn ($n) => strtolower(trim($n)))->unique();
        if ($names->isNotEmpty()) {
            $recent = Booking::withoutTenantScope()->where('created_at', '>=', $since)->where('id', '!=', $booking->id)
                ->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED, Booking::STATUS_HELD])->pluck('id');
            $repeat = BookingPassenger::query()->whereIn('booking_id', $recent)->whereIn(\Illuminate\Support\Facades\DB::raw('lower(name)'), $names->all())
                ->distinct()->count('booking_id');
            if ($repeat >= (int) ($c['max_bookings_per_traveller_day'] ?? 3)) {
                $score += 20;
                $reasons[] = 'The same traveller is on '.($repeat + 1).' bookings in 24 hours';
            }
        }

        if ($booking->is_international && $booking->travel_date_start && $booking->travel_date_start->lte(now()->addDay()) && $high > 0 && $amount >= $high / 2) {
            $score += 20;
            $reasons[] = 'Expensive international trip leaving within a day';
        }

        return ['score' => min(100, $score), 'reasons' => $reasons];
    }

    /** Runs once per booking when it is paid (confirmed or ticketed). */
    public function assess(Booking $booking): ?FraudFlag
    {
        $c = app(SettingsService::class)->get('fraud');
        if (empty($c['enabled']) || ! empty(($booking->metadata ?? [])['fraud_checked_at'])) {
            return null;
        }
        $r = $this->score($booking);
        $meta = $booking->metadata ?? [];
        $meta['fraud_checked_at'] = now()->toIso8601String();
        $meta['fraud_score'] = $r['score'];
        $booking->forceFill(['metadata' => $meta])->saveQuietly();

        if ($r['score'] < (int) ($c['review_score'] ?? 50)) {
            return null;
        }
        $flag = FraudFlag::query()->create([
            'subject_type' => FraudFlag::SUBJECT_BOOKING, 'subject_id' => $booking->id,
            'risk_score' => $r['score'], 'reason' => mb_substr(implode('; ', $r['reasons']), 0, 255),
        ]);
        try {
            $notify = app(NotificationService::class);
            $notify->alertStaff('staff_alert', $notify->bookingVars($booking, [
                'reason' => 'Possible fraud (score '.$r['score'].'): '.implode('; ', $r['reasons']).'. Check it in Admin settings → Fraud checks before travel.',
            ]), $booking->id);
        } catch (\Throwable) {
        }

        return $flag;
    }
}
