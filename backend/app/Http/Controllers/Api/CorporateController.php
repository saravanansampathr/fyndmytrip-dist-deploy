<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Corporate\Models\TripRequest;
use App\Domains\Corporate\Services\CorporateService;
use App\Domains\Settings\Models\InvoiceDocument;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Corporate travel: switch it on for a company account, set its travel
 * policy and approvers, employees raise trip requests, approvers decide,
 * and a monthly statement of the company's bookings and invoices.
 */
class CorporateController extends Controller
{
    public function __construct(protected CorporateService $corp)
    {
    }

    /** Company settings: GET / PUT. Staff or the partner above it, or the company's own owner. */
    public function settings(Request $request, Tenant $tenant)
    {
        $this->manage($request->user(), $tenant);
        $users = User::query()->where('tenant_id', $tenant->id)->where('status', User::STATUS_ACTIVE)->get(['id', 'name', 'email', 'role']);

        return response()->json(['data' => [
            'enabled' => $this->corp->isCompany($tenant),
            'policy' => $this->corp->policy($tenant),
            'approver_ids' => (array) data_get($tenant->config, 'corporate.approver_ids', []),
            'monthly_billing' => (bool) data_get($tenant->config, 'corporate.monthly_billing', true),
            'users' => $users,
        ]]);
    }

    public function updateSettings(Request $request, Tenant $tenant)
    {
        $u = $request->user();
        $this->manage($u, $tenant);
        $d = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'monthly_billing' => ['sometimes', 'boolean'],
            'approver_ids' => ['sometimes', 'array'],
            'approver_ids.*' => ['integer', Rule::exists('users', 'id')->where('tenant_id', $tenant->id)],
            'policy' => ['sometimes', 'array'],
            'policy.flight_max_amount' => ['sometimes', 'numeric', 'min:0'],
            'policy.hotel_max_per_night' => ['sometimes', 'numeric', 'min:0'],
            'policy.allowed_cabins' => ['sometimes', 'array'],
            'policy.allowed_cabins.*' => ['in:economy,premium_economy,business,first'],
            'policy.min_advance_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'policy.auto_approve_below' => ['sometimes', 'numeric', 'min:0'],
        ]);
        abort_if(array_key_exists('enabled', $d) && ! $u->isPlatformStaff() && $u->role !== User::ROLE_PARTNER_ADMIN, 403, 'Ask us to switch corporate travel on or off.');
        abort_unless(in_array($tenant->type, [Tenant::TYPE_AGENT, Tenant::TYPE_SUB_AGENT], true), 422, 'Corporate travel is for company (agent-type) accounts.');
        $config = $tenant->config ?? [];
        $c = (array) ($config['corporate'] ?? []);
        foreach (['enabled', 'monthly_billing', 'approver_ids'] as $k) {
            if (array_key_exists($k, $d)) {
                $c[$k] = $d[$k];
            }
        }
        if (isset($d['policy'])) {
            $c['policy'] = array_merge((array) ($c['policy'] ?? []), $d['policy']);
        }
        $config['corporate'] = $c;
        $tenant->forceFill(['config' => $config])->save();

        return $this->settings($request, $tenant->fresh());
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $q = TripRequest::query()->with('requester:id,name,email')->orderByDesc('id');
        if (! $u->isPlatformStaff()) {
            $tenant = Tenant::query()->findOrFail($u->tenant_id);
            abort_unless($this->corp->isCompany($tenant), 403, 'Corporate travel is not switched on for your account.');
            $q->where('tenant_id', $tenant->id);
            if (! $this->corp->canApprove($u, $tenant)) {
                $q->where('requested_by', $u->id);
            }
        } elseif ($request->query('tenant_id')) {
            $q->where('tenant_id', $request->query('tenant_id'));
        }
        $q->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        return response()->json(['data' => $q->limit(200)->get(), 'can_approve' => $u->isPlatformStaff() || $this->corp->canApprove($u, Tenant::query()->findOrFail($u->tenant_id))]);
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $tenant = Tenant::query()->findOrFail($u->tenant_id);
        abort_unless($this->corp->isCompany($tenant), 403, 'Corporate travel is not switched on for your account.');
        $d = $request->validate([
            'travellers' => ['required', 'string', 'max:500'],
            'module' => ['required', Rule::in(Tenant::ALL_MODULES)],
            'origin' => ['nullable', 'string', 'max:100'],
            'destination' => ['required', 'string', 'max:100'],
            'depart_date' => ['required', 'date', 'after_or_equal:today'],
            'return_date' => ['nullable', 'date', 'after_or_equal:depart_date'],
            'cabin_class' => ['nullable', 'in:economy,premium_economy,business,first'],
            'estimated_amount' => ['required', 'numeric', 'min:0'],
            'nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'purpose' => ['required', 'string', 'max:500'],
            'cost_center' => ['nullable', 'string', 'max:60'],
        ]);
        $issues = $this->corp->check($tenant, $d);
        $auto = (float) $this->corp->policy($tenant)['auto_approve_below'];
        $autoOk = ! $issues && $auto > 0 && (float) $d['estimated_amount'] < $auto;
        $r = TripRequest::query()->create($d + [
            'tenant_id' => $tenant->id, 'requested_by' => $u->id, 'policy_issues' => $issues,
            'status' => $autoOk ? 'approved' : 'pending', 'decided_at' => $autoOk ? now() : null, 'decision_note' => $autoOk ? 'Approved automatically: within policy.' : null,
        ]);
        if (! $autoOk) {
            $this->corp->mail($this->corp->approvers($tenant)->where('id', '!=', $u->id), 'Trip request from '.$u->name.' needs your approval',
                "{$u->name} asked to travel.\n\n".$this->corp->summary($r)."\n\n".($issues ? "Outside policy:\n- ".implode("\n- ", $issues) : 'Within policy.')."\n\nApprove or reject it under Corporate travel → Trip requests.");
        }

        return response()->json(['data' => $r, 'message' => $autoOk ? 'Approved. You can book this trip now.' : ($issues ? 'Sent for approval. Some details are outside your company policy.' : 'Sent for approval.')], 201);
    }

    public function decide(Request $request, TripRequest $tripRequest, string $decision)
    {
        $u = $request->user();
        $tenant = Tenant::query()->findOrFail($tripRequest->tenant_id);
        abort_unless($this->corp->canApprove($u, $tenant), 403, 'Only your company approvers can decide.');
        abort_unless($tripRequest->status === 'pending', 422, 'This request has already been decided.');
        abort_if($tripRequest->requested_by === $u->id && ! $u->isPlatformStaff() && $this->corp->approvers($tenant)->count() > 1, 422, 'Another approver must decide your own request.');
        $d = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']]);
        $tripRequest->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]);
        $this->corp->mail([$tripRequest->requester], 'Your trip request was '.($decision === 'approve' ? 'approved' : 'not approved'),
            $this->corp->summary($tripRequest)."\n\n".($decision === 'approve' ? 'Approved by '.$u->name.'. You can book it now.' : 'Not approved by '.$u->name.'.').(($d['note'] ?? null) ? "\nNote: ".$d['note'] : ''));

        return response()->json(['data' => $tripRequest->fresh(), 'message' => $decision === 'approve' ? 'Approved.' : 'Rejected.']);
    }

    /** Link the booking made for an approved request. */
    public function link(Request $request, TripRequest $tripRequest)
    {
        $u = $request->user();
        abort_unless($u->tenant_id === $tripRequest->tenant_id || $u->isPlatformStaff(), 404);
        abort_unless($tripRequest->status === 'approved', 422, 'Only an approved request can be booked.');
        $d = $request->validate(['booking_code' => ['required', 'string']]);
        $b = Booking::withoutTenantScope()->where('tenant_id', $tripRequest->tenant_id)->where('booking_code', $d['booking_code'])->first();
        abort_unless($b, 422, 'No booking with that ID for this company.');
        $tripRequest->update(['status' => 'booked', 'booking_id' => $b->id]);
        $meta = $b->metadata ?? [];
        $meta['trip_request_id'] = $tripRequest->id;
        $meta['cost_center'] = $tripRequest->cost_center;
        $b->forceFill(['metadata' => $meta])->saveQuietly();

        return response()->json(['data' => $tripRequest->fresh(), 'message' => 'Linked.']);
    }

    /** Monthly statement of a company's bookings and invoices (?month=2026-11, ?format=csv). */
    public function statement(Request $request, Tenant $tenant)
    {
        $u = $request->user();
        abort_unless($u->tenant_id === $tenant->id && in_array($u->role, [User::ROLE_AGENT, User::ROLE_SUB_AGENT], true) || $this->canManage($u, $tenant), 403);
        $month = $request->query('month', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month), 422, 'Use month=YYYY-MM.');
        $from = \Illuminate\Support\Carbon::parse($month.'-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();
        $docs = InvoiceDocument::query()->with('booking')->whereBetween('issued_at', [$from, $to])
            ->whereHas('booking', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id))->orderBy('issued_at')->get();
        $requests = TripRequest::query()->whereIn('booking_id', $docs->pluck('booking_id'))->get()->keyBy('booking_id');
        $rows = $docs->map(function (InvoiceDocument $doc) use ($requests) {
            $b = $doc->booking;
            $sign = $doc->type === InvoiceDocument::TYPE_INVOICE ? 1 : -1;

            return [
                'date' => $doc->issued_at->toDateString(), 'document' => $doc->number, 'type' => $doc->type === InvoiceDocument::TYPE_INVOICE ? 'Invoice' : 'Credit note',
                'booking_code' => $b?->booking_code, 'service' => $b?->module, 'travel_date' => $b?->travel_date_start?->toDateString(),
                'traveller' => $b?->passengers()->value('name'), 'cost_center' => data_get($b?->metadata, 'cost_center') ?? $requests[$doc->booking_id]->cost_center ?? null,
                'amount' => round($sign * (float) $doc->amount, 2), 'gst' => round($sign * (float) $doc->tax_amount, 2),
            ];
        })->values();
        $total = round($rows->sum('amount'), 2);

        if ($request->query('format') === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['Date', 'Document', 'Type', 'Booking', 'Service', 'Travel date', 'Traveller', 'Cost centre', 'Amount', 'GST']);
            foreach ($rows as $r) {
                fputcsv($out, array_values($r));
            }
            fputcsv($out, ['', '', '', '', '', '', '', 'Total', $total, round($rows->sum('gst'), 2)]);
            rewind($out);

            return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="statement-'.$tenant->id.'-'.$month.'.csv"']);
        }

        return response()->json(['data' => ['company' => $tenant->name, 'month' => $month, 'rows' => $rows, 'total' => $total, 'gst' => round($rows->sum('gst'), 2)]]);
    }

    protected function canManage(User $u, Tenant $t): bool
    {
        if ($u->isPlatformStaff()) {
            return true;
        }

        return $u->role === User::ROLE_PARTNER_ADMIN && in_array($t->id, app(TenantService::class)->getDescendantIdsRobust($u->tenant), true);
    }

    protected function manage(User $u, Tenant $t): void
    {
        abort_unless($this->canManage($u, $t) || ($u->tenant_id === $t->id && in_array($u->role, [User::ROLE_AGENT, User::ROLE_SUB_AGENT], true)), 403);
    }
}
