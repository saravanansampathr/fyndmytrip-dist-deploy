<?php

namespace App\Domains\Corporate\Services;

use App\Domains\Corporate\Models\TripRequest;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Company travel policy, who approves, and the policy check on a trip request. */
class CorporateService
{
    public const DEFAULT_POLICY = [
        'flight_max_amount' => 15000,
        'hotel_max_per_night' => 6000,
        'allowed_cabins' => ['economy'],
        'min_advance_days' => 7,
        'auto_approve_below' => 0,
    ];

    public function isCompany(?Tenant $t): bool
    {
        return (bool) data_get($t?->config, 'corporate.enabled');
    }

    public function policy(Tenant $t): array
    {
        return array_merge(self::DEFAULT_POLICY, (array) data_get($t->config, 'corporate.policy', []));
    }

    /** Approvers: the company's named approvers, else its account owners (agent role). */
    public function approvers(Tenant $t)
    {
        $ids = (array) data_get($t->config, 'corporate.approver_ids', []);
        $q = User::query()->where('tenant_id', $t->id)->where('status', User::STATUS_ACTIVE);

        return $ids ? $q->whereIn('id', $ids)->get() : $q->whereIn('role', [User::ROLE_AGENT, User::ROLE_SUB_AGENT])->get();
    }

    public function canApprove(User $u, Tenant $t): bool
    {
        if ($u->isPlatformStaff()) {
            return true;
        }

        return $u->tenant_id === $t->id && $this->approvers($t)->contains('id', $u->id);
    }

    /** @return array<int, string> plain-language policy breaches */
    public function check(Tenant $t, array $r): array
    {
        $p = $this->policy($t);
        $issues = [];
        $amount = (float) $r['estimated_amount'];
        if ($r['module'] === 'flight') {
            if ((float) $p['flight_max_amount'] > 0 && $amount > (float) $p['flight_max_amount']) {
                $issues[] = 'Fare is above the flight limit of '.number_format((float) $p['flight_max_amount']).'.';
            }
            $cabin = $r['cabin_class'] ?? 'economy';
            if ($p['allowed_cabins'] && ! in_array($cabin, (array) $p['allowed_cabins'], true)) {
                $issues[] = ucfirst(str_replace('_', ' ', $cabin)).' class is not allowed by the policy.';
            }
        }
        if ($r['module'] === 'hotel' && (float) $p['hotel_max_per_night'] > 0) {
            $nights = max(1, (int) ($r['nights'] ?? 1));
            if ($amount / $nights > (float) $p['hotel_max_per_night']) {
                $issues[] = 'Hotel costs more per night than the limit of '.number_format((float) $p['hotel_max_per_night']).'.';
            }
        }
        $days = now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($r['depart_date'])->startOfDay(), false);
        if ((int) $p['min_advance_days'] > 0 && $days < (int) $p['min_advance_days']) {
            $issues[] = 'Booked '.max(0, (int) $days).' days ahead; the policy asks for '.(int) $p['min_advance_days'].'.';
        }

        return $issues;
    }

    public function mail(iterable $users, string $subject, string $body): void
    {
        foreach ($users as $u) {
            if ($u && filter_var($u->email, FILTER_VALIDATE_EMAIL)) {
                try {
                    Mail::raw($body, fn ($m) => $m->to($u->email)->subject($subject));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    public function summary(TripRequest $r): string
    {
        return ucfirst($r->module).': '.trim(($r->origin ? $r->origin.' → ' : '').$r->destination).' on '.$r->depart_date->format('d M Y')
            .' for '.$r->travellers.' (about '.number_format((float) $r->estimated_amount).'). Purpose: '.$r->purpose;
    }
}
