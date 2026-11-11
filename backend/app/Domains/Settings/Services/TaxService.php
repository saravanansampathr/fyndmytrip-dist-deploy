<?php

namespace App\Domains\Settings\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Settings\Models\InvoiceDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tax rules read from admin settings (Tax and invoices): GST rate and SAC
 * code per service, the invoice and credit-note number series, and TCS on
 * overseas tour packages.
 */
class TaxService
{
    public function __construct(protected SettingsService $settings)
    {
    }

    public function gstRate(string $module): float
    {
        return (float) ($this->settings->value('tax', "gst_rates.{$module}") ?? 18);
    }

    public function sac(string $module): string
    {
        return (string) ($this->settings->value('tax', "sac_codes.{$module}") ?? '998559');
    }

    public function homeState(): string
    {
        return str_pad((string) $this->settings->value('tax', 'home_state', '33'), 2, '0', STR_PAD_LEFT);
    }

    /** "2026-27" for any date from 1 April 2026 to 31 March 2027. */
    public static function financialYear(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /**
     * The booking's tax invoice, issued on first call with the next number
     * in this financial year's series (e.g. FMT2627-000042). Never more
     * than one per booking.
     */
    public function invoiceFor(Booking $booking, ?CarbonInterface $issuedAt = null): InvoiceDocument
    {
        $existing = InvoiceDocument::query()->where('booking_id', $booking->id)->where('type', InvoiceDocument::TYPE_INVOICE)->first();
        if ($existing) {
            return $existing;
        }

        $markup = (float) $booking->markup_amount;
        $rate = $this->gstRate($booking->module);
        $tax = $markup > 0 ? round($markup - $markup / (1 + $rate / 100), 2) : 0.0;

        return $this->issue($booking, InvoiceDocument::TYPE_INVOICE, (string) $this->settings->value('tax', 'invoice_prefix', 'FMT'), (float) $booking->total_amount, $tax, (float) ($booking->metadata['tcs_amount'] ?? 0), $issuedAt, null, [
            'gst_rate' => $rate,
            'sac' => $this->sac($booking->module),
        ]);
    }

    /**
     * A credit note against the booking's invoice for money given back
     * (cancellation refund). One per refund event; the tax on it is the
     * same share of the refund as on the original invoice.
     */
    public function creditNoteFor(Booking $booking, float $amount, string $reason): ?InvoiceDocument
    {
        $invoice = InvoiceDocument::query()->where('booking_id', $booking->id)->where('type', InvoiceDocument::TYPE_INVOICE)->first();
        if (! $invoice || $amount <= 0) {
            return null;
        }

        $alreadyCredited = (float) InvoiceDocument::query()->where('original_invoice_id', $invoice->id)->sum('amount');
        $amount = min($amount, max(0, (float) $invoice->amount - $alreadyCredited));
        if ($amount <= 0) {
            return null;
        }

        $share = (float) $invoice->amount > 0 ? $amount / (float) $invoice->amount : 0;

        return $this->issue($booking, InvoiceDocument::TYPE_CREDIT_NOTE, (string) $this->settings->value('tax', 'credit_note_prefix', 'FCN'), $amount, round((float) $invoice->tax_amount * $share, 2), round((float) $invoice->tcs_amount * $share, 2), null, $invoice->id, [
            'reason' => $reason,
            'gst_rate' => $invoice->details['gst_rate'] ?? $this->gstRate($booking->module),
            'sac' => $invoice->details['sac'] ?? $this->sac($booking->module),
        ]);
    }

    protected function issue(Booking $booking, string $type, string $prefix, float $amount, float $tax, float $tcs, ?CarbonInterface $issuedAt, ?int $originalId, array $details): InvoiceDocument
    {
        $issuedAt ??= now();
        $fy = self::financialYear($issuedAt);
        $prefix = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($prefix)) ?: ($type === InvoiceDocument::TYPE_INVOICE ? 'FMT' : 'FCN'), 0, 5);

        return DB::transaction(function () use ($booking, $type, $prefix, $amount, $tax, $tcs, $issuedAt, $originalId, $details, $fy) {
            // Lock the series so two bookings paid at once can't take the
            // same number (GST requires an unbroken, unique series).
            $last = InvoiceDocument::query()->where('type', $type)->where('financial_year', $fy)->lockForUpdate()->max('sequence');
            $seq = ((int) $last) + 1;
            $fyShort = substr($fy, 2, 2).substr($fy, -2);

            return InvoiceDocument::query()->create([
                'booking_id' => $booking->id,
                'type' => $type,
                'financial_year' => $fy,
                'sequence' => $seq,
                'number' => $prefix.$fyShort.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                'original_invoice_id' => $originalId,
                'amount' => round($amount, 2),
                'tax_amount' => $tax,
                'tcs_amount' => $tcs,
                'details' => $details,
                'issued_at' => $issuedAt,
            ]);
        });
    }

    /**
     * TCS to collect on an overseas tour package (s.206C(1G)), as set in
     * admin. $alreadyThisYear is what this customer has already paid for
     * overseas packages this financial year: the lower rate applies up to
     * the threshold, the higher rate above it.
     *
     * @return array{enabled: bool, rate: float, amount: float}
     */
    public function tcsFor(float $amount, float $alreadyThisYear = 0.0): array
    {
        $tcs = $this->settings->get('tax')['tcs'];
        if (empty($tcs['enabled']) || $amount <= 0) {
            return ['enabled' => false, 'rate' => 0.0, 'amount' => 0.0];
        }

        $threshold = (float) $tcs['threshold'];
        $below = max(0.0, min($amount, $threshold - $alreadyThisYear));
        $above = $amount - $below;
        $value = round($below * (float) $tcs['rate_below'] / 100 + $above * (float) $tcs['rate_above'] / 100, 2);

        return ['enabled' => true, 'rate' => $amount > 0 ? round($value / $amount * 100, 2) : 0.0, 'amount' => $value];
    }

    /** What this PAN (or, without one, this email) already paid for overseas packages this financial year. */
    public function overseasPackageSpendThisYear(?string $pan, ?string $email): float
    {
        if (! $pan && ! $email) {
            return 0.0;
        }
        $fy = self::financialYear(now());
        $from = substr($fy, 0, 4).'-04-01';

        return (float) Booking::withoutTenantScope()
            ->where('module', Booking::MODULE_HOLIDAY)
            ->where('is_international', true)
            ->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED])
            ->where('created_at', '>=', $from)
            ->when($pan, fn ($q) => $q->where('metadata->tcs_pan', strtoupper($pan)), fn ($q) => $q->where('contact_email', $email))
            ->sum('gross_amount');
    }
}
