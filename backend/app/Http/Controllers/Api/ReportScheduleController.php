<?php

namespace App\Http\Controllers\Api;

use App\Domains\Reporting\Models\ReportSchedule;
use App\Domains\Reporting\Services\ReportScheduleMailer;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * "Anything missed... scheduled/emailed reports" — Settings for
 * SendScheduledReports: who gets the Overview report emailed, how often,
 * and in what format. Same tenant-hierarchy visibility and wallet.view
 * gate as the rest of the Reports module (ReportsController) — whoever
 * can already pull this report manually can schedule it for themselves,
 * nothing new to grant.
 */
class ReportScheduleController extends Controller
{
    public function __construct(protected TenantService $tenantService) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $tenantIds = $this->resolveTenantIds($request, $user);

        $query = ReportSchedule::query()->with('tenant:id,name')->orderByDesc('id');

        if ($tenantIds !== null) {
            $query->whereIn('tenant_id', $tenantIds);
        }

        return response()->json(['data' => $query->get()->map(fn ($s) => $this->present($s))->values()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $data = $request->validate([
            'frequency' => ['required', 'string', 'in:'.implode(',', ReportSchedule::FREQUENCIES)],
            'format' => ['required', 'string', 'in:'.implode(',', ReportSchedule::FORMATS)],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email'],
            // Platform staff only — see resolveScheduleTenantId(). A non-
            // staff caller may send this, but it's silently ignored: the
            // server always decides their own tenant, never the client.
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $tenantId = $this->resolveScheduleTenantId($user, $data['tenant_id'] ?? null);

        $schedule = ReportSchedule::create([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $user->id,
            'report_type' => ReportSchedule::REPORT_TYPE_SUMMARY,
            'frequency' => $data['frequency'],
            'format' => $data['format'],
            'recipients' => $data['recipients'],
            'is_active' => true,
        ]);

        return response()->json(['data' => $this->present($schedule->load('tenant:id,name'))], 201);
    }

    public function update(Request $request, int $reportSchedule)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $schedule = ReportSchedule::query()->with('tenant:id,name')->findOrFail($reportSchedule);
        $this->authorizeSchedule($request, $user, $schedule);

        $data = $request->validate([
            'frequency' => ['sometimes', 'string', 'in:'.implode(',', ReportSchedule::FREQUENCIES)],
            'format' => ['sometimes', 'string', 'in:'.implode(',', ReportSchedule::FORMATS)],
            'recipients' => ['sometimes', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['email'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $schedule->update($data);

        return response()->json(['data' => $this->present($schedule)]);
    }

    public function destroy(Request $request, int $reportSchedule)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $schedule = ReportSchedule::query()->findOrFail($reportSchedule);
        $this->authorizeSchedule($request, $user, $schedule);

        $schedule->delete();

        return response()->json(['message' => 'Report schedule removed.']);
    }

    /**
     * "Send me a test now" — the exact same send a user would otherwise
     * wait for the 2am cron to do (ReportScheduleMailer::send(), shared
     * with SendScheduledReports), fired on demand so a user can confirm
     * their recipients/format actually work without waiting a day.
     */
    public function sendNow(Request $request, int $reportSchedule, ReportScheduleMailer $mailer)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $schedule = ReportSchedule::query()->findOrFail($reportSchedule);
        $this->authorizeSchedule($request, $user, $schedule);

        try {
            $mailer->send($schedule);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not send that report: '.$e->getMessage()], 422);
        }

        $schedule->forceFill(['last_sent_at' => now()])->save();

        return response()->json(['message' => 'Report sent.']);
    }

    protected function present(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'tenant_id' => $schedule->tenant_id,
            'tenant_name' => $schedule->tenant_id === null
                ? 'Platform-wide'
                : ($schedule->tenant?->name ?? "Tenant #{$schedule->tenant_id}"),
            'report_type' => $schedule->report_type,
            'frequency' => $schedule->frequency,
            'format' => $schedule->format,
            'recipients' => $schedule->recipients,
            'is_active' => $schedule->is_active,
            'last_sent_at' => $schedule->last_sent_at,
            'created_at' => $schedule->created_at,
        ];
    }

    /** Platform staff may target any tenant (or leave it platform-wide); everyone else always gets their own tenant, regardless of what they sent. */
    protected function resolveScheduleTenantId(User $user, ?int $requestedTenantId): ?int
    {
        if ($user->isPlatformStaff()) {
            if ($requestedTenantId === null) {
                return null;
            }

            return Tenant::query()->findOrFail($requestedTenantId)->id;
        }

        return $user->tenant_id;
    }

    protected function authorizeSchedule(Request $request, User $user, ReportSchedule $schedule): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        $tenantIds = $this->resolveTenantIds($request, $user);

        abort_unless(
            $schedule->tenant_id !== null && in_array($schedule->tenant_id, $tenantIds ?? [], true),
            403,
            'That report schedule is outside your own hierarchy.',
        );
    }

    /**
     * Same rule as ReportsController::resolveTenantIds() — null means
     * "platform-wide" (staff only); everyone else is narrowed to their own
     * tenant plus whatever sits below it.
     *
     * @return array<int>|null
     */
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
        $ownScope = $this->tenantService->getDescendantIdsRobust($ownTenant);

        if ($requestedTenantId === null) {
            return $ownScope;
        }

        abort_unless(in_array($requestedTenantId, $ownScope, true), 403, 'That tenant is outside your own hierarchy.');

        $tenant = Tenant::query()->find($requestedTenantId);

        return $tenant ? $this->tenantService->getDescendantIdsRobust($tenant) : $ownScope;
    }

    protected function authorizeView(User $user): void
    {
        abort_unless($user->hasPermission('wallet.view'), 403, 'Missing required permission: wallet.view');
    }
}
