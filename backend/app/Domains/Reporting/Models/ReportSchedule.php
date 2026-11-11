<?php

namespace App\Domains\Reporting\Models;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "Anything missed... scheduled/emailed reports" — one row per "email me
 * the Overview report on a cadence" configuration. tenant_id null means
 * platform-wide (staff only — see ReportScheduleController::authorizeTenant());
 * everyone else always has a concrete tenant_id (their own).
 *
 * Deliberately NOT using App\Domains\Tenancy\Concerns\BelongsToTenant —
 * that trait's global scope assumes "belongs to the CURRENT acting
 * tenant", which breaks for two things this model specifically needs: a
 * platform-wide row (tenant_id null) staff created, and the console
 * command (SendScheduledReports) reading every tenant's rows in one pass
 * with no "current tenant" request context to scope from at all.
 * ReportScheduleController applies the exact same hierarchy-based
 * visibility ReportsController uses instead.
 */
class ReportSchedule extends Model
{
    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCIES = [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY];

    public const FORMAT_PDF = 'pdf';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMATS = [self::FORMAT_PDF, self::FORMAT_XLSX];

    public const REPORT_TYPE_SUMMARY = 'summary';

    protected $fillable = [
        'tenant_id',
        'created_by_user_id',
        'report_type',
        'frequency',
        'format',
        'recipients',
        'is_active',
        'last_sent_at',
    ];

    protected $casts = [
        'recipients' => 'array',
        'is_active' => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Whether "today" (server local date) is a send day for this
     * schedule's frequency — weekly fires every Monday, monthly fires on
     * the 1st. Kept deliberately fixed (no per-schedule day-of-week/day-
     * of-month picker) so the feature ships with one clear, predictable
     * rule rather than a calendar-configuration UI nobody asked for.
     */
    public function isDueToday(\Illuminate\Support\Carbon $today): bool
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => true,
            self::FREQUENCY_WEEKLY => $today->isMonday(),
            self::FREQUENCY_MONTHLY => $today->day === 1,
            default => false,
        };
    }

    /**
     * The report period this send covers — always the most recently
     * completed full period, never a partial "so far today" slice.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    public function periodFor(\Illuminate\Support\Carbon $today): array
    {
        return match ($this->frequency) {
            self::FREQUENCY_WEEKLY => [
                $today->copy()->subWeek()->startOfWeek(),
                $today->copy()->subWeek()->endOfWeek(),
            ],
            self::FREQUENCY_MONTHLY => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [
                $today->copy()->subDay()->startOfDay(),
                $today->copy()->subDay()->endOfDay(),
            ],
        };
    }
}
