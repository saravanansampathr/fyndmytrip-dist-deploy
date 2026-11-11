<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\HotelGroupBookingRequest;
use App\Domains\Booking\Services\HotelGroupBookingService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\HotelGroupBookingRequestResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Hotel counterpart to GroupBookingController — same staff-quoted flow,
 * B2B-only. See HotelGroupBookingRequest/Service for the state machine;
 * this controller is purely permission/scope-checking plus request
 * validation.
 *
 * Tenant scoping follows the exact pattern GroupBookingController/
 * OfflinePaymentController use, for the identical reason:
 * HotelGroupBookingRequest carries BelongsToTenant, so a manager who isn't
 * platform staff is NOT bypassed by ResolveTenant middleware and needs
 * withoutTenantScope() + an explicit hierarchy check rather than relying on
 * the model's own global scope.
 */
class HotelGroupBookingController extends Controller
{
    public function __construct(
        protected HotelGroupBookingService $hotelGroupBookingService,
        protected TenantService $tenantService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $canManage = $user->hasPermission('hotel_group_booking.manage');
        $tenantIds = $canManage ? $this->resolveTenantIds($request, $user) : null;

        $query = HotelGroupBookingRequest::withoutTenantScope()
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->when(! $canManage, fn ($q) => $q->where('requested_by_user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['requestedBy:id,name,email', 'quotedBy:id,name', 'finalizedBy:id,name'])
            ->orderByDesc('created_at');

        $requests = $query->paginate($request->integer('per_page', 25));

        return HotelGroupBookingRequestResource::collection($requests);
    }

    public function show(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveForAccess($request, $request->user(), $hotelGroupBooking);

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel->load(['requestedBy:id,name,email', 'quotedBy:id,name', 'finalizedBy:id,name', 'guests']));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('hotel_group_booking.request'), 403, 'Missing required permission: hotel_group_booking.request');

        $data = $request->validate([
            'city' => ['required', 'string', 'max:100'],
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'rooms_requested' => ['required', 'integer', 'min:'.HotelGroupBookingRequest::MINIMUM_ROOMS_COUNT],
            'guest_count' => ['required', 'integer', 'min:1'],
            'room_type_preference' => ['nullable', 'string', 'max:100'],
            'meal_plan_preference' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_email' => ['required', 'email', 'max:191'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $hotelGroupBooking = $this->hotelGroupBookingService->submit($user, $data);

        return new HotelGroupBookingRequestResource($hotelGroupBooking);
    }

    public function quote(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveForManage($request, $request->user(), $hotelGroupBooking);

        $data = $request->validate([
            'quoted_rate_per_room' => ['required', 'numeric', 'gt:0'],
            'quoted_total_fare' => ['required', 'numeric', 'gt:0'],
            'deposit_amount' => ['required', 'numeric', 'gt:0', 'lte:quoted_total_fare'],
            'rate_hold_expires_at' => ['required', 'date', 'after_or_equal:today'],
            'rooming_list_deadline' => ['required', 'date', 'after_or_equal:rate_hold_expires_at'],
            'quote_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $hotelGroupBookingModel = $this->hotelGroupBookingService->quote($hotelGroupBookingModel, $request->user(), $data);

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel);
    }

    public function acceptQuote(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveOwnRequest($request->user(), $hotelGroupBooking);

        try {
            $hotelGroupBookingModel = $this->hotelGroupBookingService->acceptQuote($hotelGroupBookingModel, $request->user());
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => 'Insufficient wallet/credit balance to pay this deposit. Please top up your wallet or contact billing.'], 422);
        }

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel);
    }

    public function cancel(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveOwnRequest($request->user(), $hotelGroupBooking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $hotelGroupBookingModel = $this->hotelGroupBookingService->cancel($hotelGroupBookingModel, $data['reason']);

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel);
    }

    public function submitRoomingList(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveOwnRequest($request->user(), $hotelGroupBooking);

        $data = $request->validate([
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.room_number' => ['nullable', 'integer', 'min:1'],
            'guests.*.title' => ['nullable', 'string', 'in:Mr,Mrs,Ms,Miss,Master,Dr'],
            'guests.*.first_name' => ['required', 'string', 'max:100'],
            'guests.*.last_name' => ['required', 'string', 'max:100'],
            'guests.*.guest_type' => ['nullable', Rule::in(['adult', 'child'])],
            'guests.*.dob' => ['nullable', 'date'],
            'guests.*.gender' => ['nullable', 'string', 'max:15'],
            'guests.*.id_proof_type' => ['nullable', 'string', 'max:30'],
            'guests.*.id_proof_number' => ['nullable', 'string', 'max:60'],
        ]);

        $hotelGroupBookingModel = $this->hotelGroupBookingService->submitRoomingList($hotelGroupBookingModel, $data['guests']);

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel->load('guests'));
    }

    public function reject(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveForManage($request, $request->user(), $hotelGroupBooking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $hotelGroupBookingModel = $this->hotelGroupBookingService->reject($hotelGroupBookingModel, $request->user(), $data['reason']);

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel);
    }

    public function markExpired(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveForManage($request, $request->user(), $hotelGroupBooking);

        $hotelGroupBookingModel = $this->hotelGroupBookingService->markExpired($hotelGroupBookingModel);

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel);
    }

    public function finalize(Request $request, int $hotelGroupBooking)
    {
        $hotelGroupBookingModel = $this->resolveForManage($request, $request->user(), $hotelGroupBooking);

        $data = $request->validate(['final_booking_reference' => ['required', 'string', 'max:60']]);

        try {
            $hotelGroupBookingModel = $this->hotelGroupBookingService->finalize($hotelGroupBookingModel, $request->user(), $data['final_booking_reference']);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => "The requester's wallet/credit balance is insufficient for the remaining balance due. Ask them to top up first."], 422);
        }

        return new HotelGroupBookingRequestResource($hotelGroupBookingModel);
    }

    protected function authorizeView(User $user): void
    {
        abort_unless(
            $user->hasPermission('hotel_group_booking.request') || $user->hasPermission('hotel_group_booking.manage'),
            403,
            'Missing required permission: hotel_group_booking.request or hotel_group_booking.manage',
        );
    }

    /** Read access: the requester can always see their own; a manager needs hotel_group_booking.manage AND the request's tenant inside their own hierarchy. */
    protected function resolveForAccess(Request $request, User $user, int $id): HotelGroupBookingRequest
    {
        $hotelGroupBooking = HotelGroupBookingRequest::withoutTenantScope()->findOrFail($id);

        if ($hotelGroupBooking->requested_by_user_id === $user->id) {
            return $hotelGroupBooking;
        }

        abort_unless($user->hasPermission('hotel_group_booking.manage'), 403, 'Missing required permission: hotel_group_booking.manage');
        $this->assertWithinManageScope($request, $user, $hotelGroupBooking);

        return $hotelGroupBooking;
    }

    /** Manage actions (quote/reject/markExpired/finalize): hotel_group_booking.manage is mandatory, no owns-it exception. */
    protected function resolveForManage(Request $request, User $user, int $id): HotelGroupBookingRequest
    {
        abort_unless($user->hasPermission('hotel_group_booking.manage'), 403, 'Missing required permission: hotel_group_booking.manage');

        $hotelGroupBooking = HotelGroupBookingRequest::withoutTenantScope()->findOrFail($id);
        $this->assertWithinManageScope($request, $user, $hotelGroupBooking);

        return $hotelGroupBooking;
    }

    /** Requester-only actions (acceptQuote/cancel/submitRoomingList): must be the request's own submitter — never delegated to a manager, since it's their own wallet being debited. */
    protected function resolveOwnRequest(User $user, int $id): HotelGroupBookingRequest
    {
        $hotelGroupBooking = HotelGroupBookingRequest::withoutTenantScope()->findOrFail($id);

        abort_unless($hotelGroupBooking->requested_by_user_id === $user->id, 403, 'This is not your group booking request.');

        return $hotelGroupBooking;
    }

    protected function assertWithinManageScope(Request $request, User $user, HotelGroupBookingRequest $hotelGroupBooking): void
    {
        $tenantIds = $this->resolveTenantIds($request, $user);

        abort_unless($tenantIds === null || in_array($hotelGroupBooking->tenant_id, $tenantIds, true), 403, 'That request is outside your scope.');
    }

    /** Same hierarchy-scoping pattern as GroupBookingController::resolveTenantIds()/OfflinePaymentController::resolveTenantIds(). */
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
