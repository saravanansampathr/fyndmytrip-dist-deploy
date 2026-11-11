<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Reporting\Models\FraudFlag;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin settings → Fraud checks: flagged paid bookings for staff to review. */
class FraudController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isPlatformStaff(), 403);
        $status = $request->query('status', FraudFlag::STATUS_OPEN);
        $flags = FraudFlag::query()->where('subject_type', FraudFlag::SUBJECT_BOOKING)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(50);
        $bookings = Booking::withoutTenantScope()->with('tenant:id,name')->whereIn('id', $flags->pluck('subject_id'))->get()->keyBy('id');

        return response()->json([
            'data' => $flags->getCollection()->map(function (FraudFlag $f) use ($bookings) {
                $b = $bookings[$f->subject_id] ?? null;

                return [
                    'id' => $f->id, 'score' => (float) $f->risk_score, 'reason' => $f->reason, 'status' => $f->status, 'created_at' => $f->created_at?->toIso8601String(),
                    'booking' => $b ? ['id' => $b->id, 'booking_code' => $b->booking_code, 'module' => $b->module, 'status' => $b->status, 'amount' => $b->total_amount,
                        'currency' => $b->currency, 'contact_email' => $b->contact_email, 'contact_phone' => $b->contact_phone,
                        'travel_date' => $b->travel_date_start?->toDateString(), 'account' => $b->tenant?->name] : null,
                ];
            }),
            'meta' => ['total' => $flags->total(), 'page' => $flags->currentPage(), 'last_page' => $flags->lastPage()],
        ]);
    }

    public function update(Request $request, FraudFlag $flag)
    {
        abort_unless($request->user()->isPlatformStaff(), 403);
        $d = $request->validate(['status' => ['required', Rule::in([FraudFlag::STATUS_REVIEWED, FraudFlag::STATUS_CLEARED, FraudFlag::STATUS_CONFIRMED_FRAUD, FraudFlag::STATUS_OPEN])]]);
        $flag->update(['status' => $d['status'], 'reviewed_by' => $request->user()->id]);

        return response()->json(['message' => $d['status'] === FraudFlag::STATUS_CONFIRMED_FRAUD
            ? 'Marked as fraud. Cancel or refund the booking from Bookings if it is still open.'
            : 'Saved.']);
    }
}
