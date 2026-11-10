<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\GroupBookingRequest;
use App\Domains\Booking\Services\GroupBookingService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupBookingRequestResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "In flight bookings need group Bookings ... These group bookings only
 * shown to b2b not to b2c users" — staff-quoted flow. See
 * GroupBookingRequest/GroupBookingService for the state machine; this
 * controller is purely permission/scope-checking plus request validation.
 *
 * Tenant scoping follows the exact pattern OfflinePaymentController uses
 * (and for the identical reason: GroupBookingRequest carries BelongsToTenant,
 * so a manager who isn't platform staff — none today, but the scoping stays
 * correct regardless of future grants — is NOT bypassed by ResolveTenant
 * middleware and needs withoutTenantScope() + an explicit hierarchy check
 * rather than relying on the model's own global scope).
 */
class GroupBookingController extends Controller
{
    public function __construct(
        protected GroupBookingService $groupBookingService,
        protected TenantService $tenantService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $canManage = $user->hasPermission('group_booking.manage');
        $tenantIds = $canManage ? $this->resolveTenantIds($request, $user) : null;

        $query = GroupBookingRequest::withoutTenantScope()
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->when(! $canManage, fn ($q) => $q->where('requested_by_user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['requestedBy:id,name,email', 'quotedBy:id,name', 'finalizedBy:id,name'])
            ->orderByDesc('created_at');

        $requests = $query->paginate($request->integer('per_page', 25));

        return GroupBookingRequestResource::collection($requests);
    }

    public function show(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveForAccess($request, $request->user(), $groupBooking);

        return new GroupBookingRequestResource($groupBookingModel->load(['requestedBy:id,name,email', 'quotedBy:id,name', 'finalizedBy:id,name', 'passengers']));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('group_booking.request'), 403, 'Missing required permission: group_booking.request');

        $data = $request->validate([
            'trip_type' => ['required', Rule::in(GroupBookingRequest::TRIP_TYPES)],
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'depart_date' => ['required', 'date', 'after_or_equal:today'],
            'return_date' => ['nullable', 'date', 'after:depart_date', 'required_if:trip_type,round_trip'],
            'passenger_count' => ['required', 'integer', 'min:'.GroupBookingRequest::MINIMUM_PASSENGER_COUNT],
            'cabin_class' => ['nullable', Rule::in(GroupBookingRequest::CABIN_CLASSES)],
            'flexible_dates' => ['nullable', 'boolean'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_email' => ['required', 'email', 'max:191'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $groupBooking = $this->groupBookingService->submit($user, $data);

        return new GroupBookingRequestResource($groupBooking);
    }

    public function quote(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveForManage($request, $request->user(), $groupBooking);

        $data = $request->validate([
            'quoted_fare_per_pax' => ['required', 'numeric', 'gt:0'],
            'quoted_total_fare' => ['required', 'numeric', 'gt:0'],
            'deposit_amount' => ['required', 'numeric', 'gt:0', 'lte:quoted_total_fare'],
            'fare_hold_expires_at' => ['required', 'date', 'after_or_equal:today'],
            'name_list_deadline' => ['required', 'date', 'after_or_equal:fare_hold_expires_at'],
            'quote_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $groupBookingModel = $this->groupBookingService->quote($groupBookingModel, $request->user(), $data);

        return new GroupBookingRequestResource($groupBookingModel);
    }

    public function acceptQuote(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveOwnRequest($request->user(), $groupBooking);

        try {
            $groupBookingModel = $this->groupBookingService->acceptQuote($groupBookingModel, $request->user());
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => 'Insufficient wallet/credit balance to pay this deposit. Please top up your wallet or contact billing.'], 422);
        }

        return new GroupBookingRequestResource($groupBookingModel);
    }

    public function cancel(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveOwnRequest($request->user(), $groupBooking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $groupBookingModel = $this->groupBookingService->cancel($groupBookingModel, $data['reason']);

        return new GroupBookingRequestResource($groupBookingModel);
    }

    public function submitNameList(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveOwnRequest($request->user(), $groupBooking);

        $data = $request->validate([
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.title' => ['nullable', 'string', 'in:Mr,Mrs,Ms,Miss,Master,Dr'],
            'passengers.*.first_name' => ['required', 'string', 'max:100'],
            'passengers.*.last_name' => ['required', 'string', 'max:100'],
            'passengers.*.passenger_type' => ['nullable', Rule::in(['adult', 'child', 'infant'])],
            'passengers.*.dob' => ['nullable', 'date'],
            'passengers.*.gender' => ['nullable', 'string', 'max:15'],
            'passengers.*.passport_number' => ['nullable', 'string', 'max:60'],
            'passengers.*.passport_expiry_date' => ['nullable', 'date'],
        ]);

        $groupBookingModel = $this->groupBookingService->submitNameList($groupBookingModel, $data['passengers']);

        return new GroupBookingRequestResource($groupBookingModel->load('passengers'));
    }

    public function reject(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveForManage($request, $request->user(), $groupBooking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $groupBookingModel = $this->groupBookingService->reject($groupBookingModel, $request->user(), $data['reason']);

        return new GroupBookingRequestResource($groupBookingModel);
    }

    public function markExpired(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveForManage($request, $request->user(), $groupBooking);

        $groupBookingModel = $this->groupBookingService->markExpired($groupBookingModel);

        return new GroupBookingRequestResource($groupBookingModel);
    }

    public function finalize(Request $request, int $groupBooking)
    {
        $groupBookingModel = $this->resolveForManage($request, $request->user(), $groupBooking);

        $data = $request->validate(['final_booking_reference' => ['required', 'string', 'max:60']]);

        try {
            $groupBookingModel = $this->groupBookingService->finalize($groupBookingModel, $request->user(), $data['final_booking_reference']);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => "The requester's wallet/credit balance is insufficient for the remaining balance due. Ask them to top up first."], 422);
        }

        return new GroupBookingRequestResource($groupBookingModel);
    }

    protected function authorizeView(User $user): void
    {
        abort_unless(
            $user->hasPermission('group_booking.request') || $user->hasPermission('group_booking.manage'),
            403,
            'Missing required permission: group_booking.request or group_booking.manage',
        );
    }

    /** Read access: the requester can always see their own; a manager needs group_booking.manage AND the request's tenant inside their own hierarchy. */
    protected function resolveForAccess(Request $request, User $user, int $id): GroupBookingRequest
    {
        $groupBooking = GroupBookingRequest::withoutTenantScope()->findOrFail($id);

        if ($groupBooking->requested_by_user_id === $user->id) {
            return $groupBooking;
        }

        abort_unless($user->hasPermission('group_booking.manage'), 403, 'Missing required permission: group_booking.manage');
        $this->assertWithinManageScope($request, $user, $groupBooking);

        return $groupBooking;
    }

    /** Manage actions (quote/reject/markExpired/finalize): group_booking.manage is mandatory, no owns-it exception. */
    protected function resolveForManage(Request $request, User $user, int $id): GroupBookingRequest
    {
        abort_unless($user->hasPermission('group_booking.manage'), 403, 'Missing required permission: group_booking.manage');

        $groupBooking = GroupBookingRequest::withoutTenantScope()->findOrFail($id);
        $this->assertWithinManageScope($request, $user, $groupBooking);

        return $groupBooking;
    }

    /** Requester-only actions (acceptQuote/cancel/submitNameList): must be the request's own submitter — never delegated to a manager, since it's their own wallet being debited. */
    protected function resolveOwnRequest(User $user, int $id): GroupBookingRequest
    {
        $groupBooking = GroupBookingRequest::withoutTenantScope()->findOrFail($id);

        abort_unless($groupBooking->requested_by_user_id === $user->id, 403, 'This is not your group booking request.');

        return $groupBooking;
    }

    protected function assertWithinManageScope(Request $request, User $user, GroupBookingRequest $groupBooking): void
    {
        $tenantIds = $this->resolveTenantIds($request, $user);

        abort_unless($tenantIds === null || in_array($groupBooking->tenant_id, $tenantIds, true), 403, 'That request is outside your scope.');
    }

    /** Same hierarchy-scoping pattern as OfflinePaymentController::resolveTenantIds(). */
    protected function resolveTenantIds(Request $request, User $user): ?array
    {
        $requestedTenantId = $request->filled('tenant_id') ? $request->integer('tenant_id') : null;

        if ($user->isPlatformStaff()) {
            if ($requestedTenantId === null) {
                return null;
            }

            $tenant = Tenant::query()->findOrFail($requestedTenantId);

            return $this->tenantService->getDescendantIdsRobust($tenant);
        }

        $ownTenant = Tenant::query()->findOrFail($user->tenant_id);

        return $this->tenantService->getDescendantIdsRobust($ownTenant);
    }
}
