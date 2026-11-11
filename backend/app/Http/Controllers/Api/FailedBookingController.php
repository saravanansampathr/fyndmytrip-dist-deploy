<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\RefundTransaction;
use App\Domains\Booking\Services\BookingLifecycle;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Booking\Services\RefundService;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bookings where the customer paid but the booking didn't complete:
 *  - failed: the supplier refused after payment;
 *  - stuck: paid and confirmed, but not ticketed after 30 minutes;
 *  - abandoned: the gateway says it was paid, but the customer's browser
 *    never came back to finish (closed tab, lost network).
 * Staff issue the booking by hand (ticket/PNR numbers from the supplier's
 * own site) or refund the customer.
 */
class FailedBookingController extends Controller
{
    public function __construct(protected BookingStatusService $status, protected RefundService $refunds)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeStaff($request->user());

        $rows = collect();

        Booking::withoutTenantScope()->with('tenant:id,name')
            ->where('status', Booking::STATUS_FAILED)->where('updated_at', '>=', now()->subDays(60))
            ->latest('updated_at')->limit(200)->get()
            ->filter(fn ($b) => BookingLifecycle::wasPaid($b))
            ->each(fn ($b) => $rows->push($this->row($b, 'failed')));

        Booking::withoutTenantScope()->with('tenant:id,name')
            ->where('status', Booking::STATUS_CONFIRMED)->whereNotIn('module', BookingLifecycle::CONFIRM_ONLY_MODULES)
            ->where('updated_at', '<=', now()->subMinutes(30))->latest('updated_at')->limit(200)->get()
            ->each(fn ($b) => $rows->push($this->row($b, 'stuck')));

        $abandoned = PaymentTransaction::withoutTenantScope()->where('status', PaymentTransaction::STATUS_INITIATED)
            ->whereNotNull('gateway_payment_id')->where('updated_at', '<=', now()->subMinutes(15))->pluck('booking_id');
        Booking::withoutTenantScope()->with('tenant:id,name')->whereIn('id', $abandoned)
            ->where('status', Booking::STATUS_HELD)->get()
            ->each(fn ($b) => $rows->push($this->row($b, 'abandoned')));

        return response()->json(['data' => $rows->sortByDesc('updated_at')->values()]);
    }

    /** Issue by hand: record the ticket/PNR numbers got from the supplier and confirm the booking. */
    public function issue(Request $request, int $booking)
    {
        $user = $request->user();
        $this->authorizeStaff($user);
        $data = $request->validate([
            'ticket_numbers' => ['required', 'array', 'min:1'],
            'ticket_numbers.*' => ['required', 'string', 'max:100'],
            'supplier_booking_ref' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $model = Booking::withoutTenantScope()->findOrFail($booking);

        DB::transaction(function () use ($model, $data, $user) {
            $this->settleAbandonedPayment($model);
            $model->refresh();

            $model->update([
                'supplier_booking_ref' => $data['supplier_booking_ref'] ?? $model->supplier_booking_ref,
                'metadata' => array_merge($model->metadata ?? [], ['ticket_numbers' => $data['ticket_numbers'], 'issued_manually' => true]),
                'total_amount' => (float) $model->total_amount > 0 ? $model->total_amount : $model->recalculateTotal(),
            ]);

            $note = 'Issued by hand by staff'.(! empty($data['note']) ? ': '.$data['note'] : '.');
            if (in_array($model->status, [Booking::STATUS_HELD, Booking::STATUS_FAILED], true)) {
                $this->status->transition($model, Booking::STATUS_CONFIRMED, $user, $note);
                $model->refresh();
            }
            if (! in_array($model->module, BookingLifecycle::CONFIRM_ONLY_MODULES, true) && $model->status === Booking::STATUS_CONFIRMED) {
                $this->status->transition($model, Booking::STATUS_TICKETED, $user, $note);
            }
            BookingEvent::log($model->id, BookingEvent::TYPE_MANUAL_NOTE, $note, $user);
        });

        return response()->json(['data' => $this->row($model->fresh(), 'resolved')]);
    }

    /** Refund the customer in full (or the amount given) to where they paid from. */
    public function refund(Request $request, int $booking)
    {
        $user = $request->user();
        $this->authorizeStaff($user);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:1'], 'note' => ['nullable', 'string', 'max:1000']]);
        $model = Booking::withoutTenantScope()->findOrFail($booking);

        $refund = DB::transaction(function () use ($model, $data, $user) {
            $this->settleAbandonedPayment($model);
            $model->refresh();
            if ((float) $model->total_amount <= 0) {
                $model->update(['total_amount' => $model->recalculateTotal()]);
            }
            if ($model->status === Booking::STATUS_HELD) {
                $this->status->transition($model, Booking::STATUS_FAILED, $user, 'Paid but not booked; refunding.');
                $model->refresh();
            }
            if ($model->status === Booking::STATUS_CONFIRMED) {
                $this->status->transition($model, Booking::STATUS_CANCELLED, $user, 'Could not be issued; refunding.');
                $model->refresh();
            }

            $txn = PaymentTransaction::withoutTenantScope()->where('booking_id', $model->id)->where('status', PaymentTransaction::STATUS_SUCCESS)->latest('id')->first();
            $method = $txn && $txn->gateway_payment_id ? RefundTransaction::METHOD_GATEWAY : RefundTransaction::METHOD_WALLET;

            return $this->refunds->initiate($model, $user, $method, (string) ($data['amount'] ?? $model->total_amount), ['notes' => $data['note'] ?? 'Refund for a booking that could not be completed.']);
        });

        return response()->json(['data' => ['refund_id' => $refund->id, 'status' => $refund->status, 'method' => $refund->method]]);
    }

    /** The gateway took the money but the browser never finished: record it as paid. */
    protected function settleAbandonedPayment(Booking $booking): void
    {
        $txn = PaymentTransaction::withoutTenantScope()->where('booking_id', $booking->id)
            ->where('status', PaymentTransaction::STATUS_INITIATED)->whereNotNull('gateway_payment_id')->latest('id')->first();
        if ($txn) {
            $txn->update(['status' => PaymentTransaction::STATUS_SUCCESS, 'method' => $txn->method ?? 'card']);
        }
    }

    protected function row(Booking $b, string $kind): array
    {
        $txn = PaymentTransaction::withoutTenantScope()->where('booking_id', $b->id)->latest('id')->first();
        $reason = $b->statusHistory()->latest('id')->value('reason');

        return [
            'id' => $b->id,
            'kind' => $kind,
            'booking_code' => $b->booking_code ?? $b->booking_reference,
            'module' => $b->module,
            'status' => $b->status,
            'account' => $b->tenant?->name,
            'contact_email' => $b->contact_email,
            'contact_phone' => $b->contact_phone,
            'amount' => (float) ($b->total_amount > 0 ? $b->total_amount : $b->recalculateTotal()),
            'currency' => $b->currency,
            'paid_via' => $txn ? trim(($txn->gateway ?? '').' '.($txn->gateway_payment_id ?? '')) : 'wallet',
            'reason' => $reason,
            'travel_date' => $b->travel_date_start?->toDateString(),
            'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }

    protected function authorizeStaff(User $user): void
    {
        abort_unless($user->isPlatformStaff(), 403, 'Only platform staff can work on failed bookings.');
    }
}
