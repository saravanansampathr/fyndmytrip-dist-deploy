<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payments\Models\OfflinePaymentAttachment;
use App\Domains\Payments\Models\OfflinePaymentRequest;
use App\Domains\Payments\Services\OfflinePaymentService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfflinePaymentRequestResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * "Capture offline payment request from partners, Agents, API, Whitelable
 * ... After making payment they will request in their login with mode of
 * payment, Amount, Date of transaction, Transaction ref num and remarks,
 * Attachments, So it will come as pending in our account in crm we will
 * verify and approve or reject." See OfflinePaymentRequest/Service.
 */
class OfflinePaymentController extends Controller
{
    public function __construct(
        protected OfflinePaymentService $offlinePaymentService,
        protected TenantService $tenantService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $canReview = $user->hasPermission('payments.offline.review');
        $tenantIds = $canReview ? $this->resolveTenantIds($request, $user) : null;

        // withoutTenantScope() because OfflinePaymentRequest carries
        // BelongsToTenant — a reviewer who isn't platform staff (now
        // includes Partner Admin) is NOT bypassed by ResolveTenant
        // middleware, so the model's own global scope would silently pin
        // every query to their exact tenant_id and the whereIn() below
        // could never widen it back out to their descendant tenants.
        // Mirrors MyBookingsController::index()'s identical fix for Booking,
        // which carries the same trait.
        $query = OfflinePaymentRequest::withoutTenantScope()
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            // A submitter without review rights only ever sees their own
            // claims — reviewers (staff, and now partner_admin within their
            // own hierarchy) see the tenant scope resolved above, same
            // visibility model as SupportTicketController.
            ->when(! $canReview, fn ($q) => $q->where('requested_by_user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_mode'), fn ($q) => $q->where('payment_mode', $request->string('payment_mode')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('transaction_ref_number', 'like', $term);
            })
            ->with(['requestedBy:id,name,email', 'reviewedBy:id,name', 'attachments'])
            ->orderByDesc('created_at');

        $requests = $query->paginate($request->integer('per_page', 25));

        return OfflinePaymentRequestResource::collection($requests);
    }

    public function show(Request $request, int $offlinePayment)
    {
        $offlinePaymentModel = $this->resolveForAccess($request, $request->user(), $offlinePayment);

        return new OfflinePaymentRequestResource($offlinePaymentModel->load(['requestedBy:id,name,email', 'reviewedBy:id,name', 'attachments']));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('payments.offline.submit'), 403, 'Missing required permission: payments.offline.submit');

        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(OfflinePaymentRequest::MODES)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'transaction_ref_number' => ['required', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // 5MB each
        ]);

        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        $offlinePayment = $this->offlinePaymentService->submit($user, $wallet, $data, $request->file('attachments', []));

        return new OfflinePaymentRequestResource($offlinePayment);
    }

    public function approve(Request $request, int $offlinePayment)
    {
        $offlinePaymentModel = $this->resolveForReview($request, $request->user(), $offlinePayment);

        $offlinePaymentModel = $this->offlinePaymentService->approve($offlinePaymentModel, $request->user());

        return new OfflinePaymentRequestResource($offlinePaymentModel->load(['requestedBy:id,name,email', 'reviewedBy:id,name']));
    }

    public function reject(Request $request, int $offlinePayment)
    {
        $offlinePaymentModel = $this->resolveForReview($request, $request->user(), $offlinePayment);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $offlinePaymentModel = $this->offlinePaymentService->reject($offlinePaymentModel, $request->user(), $data['reason']);

        return new OfflinePaymentRequestResource($offlinePaymentModel->load(['requestedBy:id,name,email', 'reviewedBy:id,name']));
    }

    public function downloadAttachment(Request $request, int $offlinePayment, int $attachment)
    {
        $offlinePaymentModel = $this->resolveForAccess($request, $request->user(), $offlinePayment);

        $attachmentModel = OfflinePaymentAttachment::query()->findOrFail($attachment);
        abort_unless($attachmentModel->offline_payment_request_id === $offlinePaymentModel->id, 404);

        abort_unless(Storage::disk('local')->exists($attachmentModel->disk_path), 404, 'Attachment file is missing.');

        return Storage::disk('local')->download($attachmentModel->disk_path, $attachmentModel->original_filename);
    }

    protected function authorizeView(User $user): void
    {
        abort_unless(
            $user->hasPermission('payments.offline.submit') || $user->hasPermission('payments.offline.review'),
            403,
            'Missing required permission: payments.offline.submit or payments.offline.review',
        );
    }

    /**
     * Resolves a single request for read access: the submitter can always
     * see their own; anyone else needs payments.offline.review AND the
     * request's tenant to fall inside their own hierarchy (unrestricted for
     * platform staff — see resolveTenantIds()).
     */
    protected function resolveForAccess(Request $request, User $user, int $id): OfflinePaymentRequest
    {
        $offlinePayment = OfflinePaymentRequest::withoutTenantScope()->findOrFail($id);

        if ($offlinePayment->requested_by_user_id === $user->id) {
            return $offlinePayment;
        }

        abort_unless($user->hasPermission('payments.offline.review'), 403, 'Missing required permission: payments.offline.review');
        $this->assertWithinReviewScope($request, $user, $offlinePayment);

        return $offlinePayment;
    }

    /** Resolves a single request for approve/reject: review permission is mandatory, no submitter-owns-it exception. */
    protected function resolveForReview(Request $request, User $user, int $id): OfflinePaymentRequest
    {
        abort_unless($user->hasPermission('payments.offline.review'), 403, 'Missing required permission: payments.offline.review');

        $offlinePayment = OfflinePaymentRequest::withoutTenantScope()->findOrFail($id);
        $this->assertWithinReviewScope($request, $user, $offlinePayment);

        return $offlinePayment;
    }

    /**
     * "Approving claims from their own Agents/Sub-Agents" — a reviewer who
     * isn't platform staff (e.g. Partner Admin) may only act on requests
     * whose tenant sits inside their own tenant's descendant hierarchy,
     * never another Whitelabel's.
     */
    protected function assertWithinReviewScope(Request $request, User $user, OfflinePaymentRequest $offlinePayment): void
    {
        $tenantIds = $this->resolveTenantIds($request, $user);

        abort_unless($tenantIds === null || in_array($offlinePayment->tenant_id, $tenantIds, true), 403, 'That request is outside your scope.');
    }

    /** Same hierarchy-scoping pattern as SupportTicketController::resolveTenantIds(). */
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
