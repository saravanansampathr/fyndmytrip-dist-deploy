<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Models\SupportTicketReply;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "Raise amendment like requesting any correction like name spell mistake,
 * or date change, or add the baggage or know the value on any product on
 * specific bookings so that it will assigned to concerned agent/staff to
 * look into it and give solution against each ticket... keep one module in
 * sidebar like amendment or support ticket with proper details on ticket
 * and query so that we can solve quickly." Every ticket is always tied to
 * one specific booking (never a free-floating ticket) — see
 * SupportTicket::CATEGORIES for the concrete request types named above.
 *
 * Scoped the same way ReportsController scopes bookings: platform staff see
 * every tenant, everyone else sees their own tenant plus whatever sits
 * below it in the hierarchy.
 */
class SupportTicketController extends Controller
{
    public function __construct(protected TenantService $tenantService) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $tenantIds = $this->resolveTenantIds($request, $user);

        $query = SupportTicket::query()
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('assigned_to_user_id'), fn ($q) => $q->where('assigned_to_user_id', $request->integer('assigned_to_user_id')))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assigned_to_user_id'))
            ->when($request->filled('booking_id'), fn ($q) => $q->where('booking_id', $request->integer('booking_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('subject', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhereHas('booking', fn ($b) => $b->where('booking_code', 'like', $term));
                });
            })
            ->orderByDesc('created_at');

        $tickets = $query
            ->with(['booking:id,booking_code,booking_reference,module,status', 'tenant:id,name', 'raisedBy:id,name', 'assignedTo:id,name'])
            ->paginate(min($request->integer('per_page', 25), 200));

        return SupportTicketResource::collection($tickets);
    }

    /** Raise a new ticket against a specific booking — "on specific bookings". */
    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorizeCreate($user);

        $data = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'category' => ['required', 'string', Rule::in(SupportTicket::CATEGORIES)],
            'subject' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        $booking = Booking::withoutTenantScope()->findOrFail($data['booking_id']);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($booking->tenant_id, $tenantIds, true), 403, 'That booking is outside your scope.');

        $ticket = SupportTicket::create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'category' => $data['category'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'normal',
            'status' => SupportTicket::STATUS_OPEN,
            'raised_by_user_id' => $user->id,
        ]);

        $ticket->load(['booking:id,booking_code,booking_reference,module,status', 'tenant:id,name', 'raisedBy:id,name', 'assignedTo:id,name', 'replies.user:id,name']);

        return (new SupportTicketResource($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $ticket)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $ticketModel = $this->scopedTicketOrFail($request, $user, $ticket);

        $ticketModel->load([
            'booking:id,booking_code,booking_reference,module,status,travel_date_start,contact_email,contact_phone',
            'tenant:id,name', 'raisedBy:id,name', 'assignedTo:id,name', 'replies.user:id,name',
        ]);

        return (new SupportTicketResource($ticketModel))->response();
    }

    /** Assign to a staff member and/or change status — the "look into it and give solution" step. */
    public function update(Request $request, int $ticket)
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $ticketModel = $this->scopedTicketOrFail($request, $user, $ticket);

        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(SupportTicket::STATUSES)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'string', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        if (array_key_exists('status', $data) && $data['status']) {
            $ticketModel->status = $data['status'];
            $ticketModel->resolved_at = in_array($data['status'], [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true)
                ? ($ticketModel->resolved_at ?? now())
                : null;
        }

        if (array_key_exists('assigned_to_user_id', $data)) {
            $ticketModel->assigned_to_user_id = $data['assigned_to_user_id'];
            // Assigning a still-open ticket is the natural "someone's on it now" signal.
            if ($data['assigned_to_user_id'] && $ticketModel->status === SupportTicket::STATUS_OPEN) {
                $ticketModel->status = SupportTicket::STATUS_IN_PROGRESS;
            }
        }

        if (array_key_exists('priority', $data) && $data['priority']) {
            $ticketModel->priority = $data['priority'];
        }

        $ticketModel->save();
        $ticketModel->load(['booking:id,booking_code,booking_reference,module,status', 'tenant:id,name', 'raisedBy:id,name', 'assignedTo:id,name', 'replies.user:id,name']);

        return (new SupportTicketResource($ticketModel))->response();
    }

    /** Add a message to the ticket's thread — either side of the conversation. */
    public function reply(Request $request, int $ticket)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $ticketModel = $this->scopedTicketOrFail($request, $user, $ticket);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($ticketModel, $user, $data) {
            SupportTicketReply::create([
                'ticket_id' => $ticketModel->id,
                'user_id' => $user->id,
                'message' => $data['message'],
                'created_at' => now(),
            ]);
        });

        $ticketModel->load(['booking:id,booking_code,booking_reference,module,status', 'tenant:id,name', 'raisedBy:id,name', 'assignedTo:id,name', 'replies.user:id,name']);

        return (new SupportTicketResource($ticketModel))->response();
    }

    /**
     * Who a ticket can be assigned to — platform staff (they can pick up
     * anything) plus whoever belongs to the ticket's own tenant (the
     * agent/staff_user who actually handles that tenant's bookings).
     */
    public function assignableUsers(Request $request)
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $tenantIds = $this->resolveTenantIds($request, $user);

        $users = User::query()
            ->where(function ($q) use ($tenantIds) {
                $q->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_STAFF]);
                if ($tenantIds !== null) {
                    $q->orWhereIn('tenant_id', $tenantIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json(['data' => $users]);
    }

    protected function scopedTicketOrFail(Request $request, User $user, int $ticket): SupportTicket
    {
        $ticketModel = SupportTicket::query()->findOrFail($ticket);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($ticketModel->tenant_id, $tenantIds, true), 403, 'That ticket is outside your scope.');

        return $ticketModel;
    }

    /** Mirrors ReportsController::resolveTenantIds() — platform staff see everything, everyone else their own hierarchy. */
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

    protected function authorizeView(User $user): void
    {
        abort_unless($user->hasPermission('ticket.view'), 403, 'Missing required permission: ticket.view');
    }

    protected function authorizeCreate(User $user): void
    {
        abort_unless($user->hasPermission('ticket.create'), 403, 'Missing required permission: ticket.create');
    }

    protected function authorizeManage(User $user): void
    {
        abort_unless($user->hasPermission('ticket.manage'), 403, 'Missing required permission: ticket.manage');
    }
}
