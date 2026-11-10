<?php

namespace App\Domains\Suppliers\Services;

use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Resolves and applies the margin (markup) for one priced item.
 *
 * Four rule types (Database Design Document §8.3): percentage of net fare,
 * a flat amount once per booking ("per PNR"), a flat amount per passenger,
 * and a flat amount that only applies on a matching origin/destination.
 * Types are never stacked — exactly one rule is applied.
 *
 * Which rule applies:
 *  1. Every active rule in the acting tenant's ancestor chain (self, parent,
 *     …, Master) that matches this booking is a candidate. A rule matches
 *     when its service (module or 'all'), audience (applies_to_role), user
 *     target, account target, route, domestic/international scope and
 *     supplier all fit.
 *  2. The NEAREST tenant in the chain with any candidate wins.
 *  3. Within that tenant the MOST SPECIFIC candidate wins: a rule for one
 *     chosen user beats one for a chosen account, which beats one for a
 *     single service, which beats an audience-specific rule, then a
 *     supplier-specific one, then a domestic/international one.
 *  4. Ceiling (§8.3, "a child can never out-configure its parent"): the
 *     value is capped at the most specific same-type rule of every tenant
 *     ABOVE the winning rule's owner.
 *
 * Audiences: rules for the acting user's own audience (see audienceFor())
 * and 'all' are used first. Only when the chain has none of those does it
 * fall back to the legacy role string the booking controllers pass in
 * ('agent'/'sub_agent'/'b2c'), so every rule that existed before the
 * white_label/api_partner/staff/admin audiences were added keeps pricing
 * those users exactly as before until a rule for their audience is created.
 */
class MarkupService
{
    public function __construct(protected TenantService $tenantService) {}

    /**
     * @param  array{passenger_count?: int, origin?: string, destination?: string, is_international?: bool, supplier_code?: string, user?: ?User}  $context
     * @return array{gross_amount: string, markup_amount: string}
     */
    public function applyMarkup(Tenant $tenant, string $module, string $role, string $netAmount, array $context = []): array
    {
        $resolved = $this->resolve($tenant, $module, $role, $netAmount, $context);

        return [
            'gross_amount' => $resolved['gross_amount'],
            'markup_amount' => $resolved['markup_amount'],
        ];
    }

    /**
     * Same as applyMarkup() but also reports which rule won and whether a
     * parent's ceiling capped it — used by the margin preview endpoint.
     *
     * @return array{gross_amount: string, markup_amount: string, rule: ?MarkupRule, audience: string, value: ?string, capped: bool}
     */
    public function resolve(Tenant $tenant, string $module, string $role, string $netAmount, array $context = []): array
    {
        $net = BigDecimal::of($netAmount);
        $passengerCount = max(1, (int) ($context['passenger_count'] ?? 1));
        $user = array_key_exists('user', $context) ? $context['user'] : $this->actingUser();
        $audience = $this->audienceFor($user, $role);

        $ancestorIds = $this->tenantService->getAncestorIds($tenant); // near -> far
        $candidates = $this->candidates($ancestorIds, $tenant, $module, $role, $audience, $user, $context);

        $rule = null;
        $ownerIndex = null;

        foreach ($ancestorIds as $index => $id) {
            $best = $this->mostSpecific($candidates->where('tenant_id', $id), $audience);
            if ($best) {
                $rule = $best;
                $ownerIndex = $index;
                break;
            }
        }

        if ($rule === null) {
            return [
                'gross_amount' => (string) $net->toScale(2, RoundingMode::HALF_UP),
                'markup_amount' => '0.00',
                'rule' => null,
                'audience' => $audience,
                'value' => null,
                'capped' => false,
            ];
        }

        $value = BigDecimal::of($rule->value());
        $capped = false;

        // Ceiling from every tenant strictly above the winning rule's owner.
        foreach (array_slice($ancestorIds, $ownerIndex + 1) as $id) {
            $sameType = $candidates->where('tenant_id', $id)->where('rule_type', $rule->rule_type);
            $cap = $this->mostSpecific($sameType, $audience);

            if ($cap && BigDecimal::of($cap->value())->isLessThan($value)) {
                $value = BigDecimal::of($cap->value());
                $capped = true;
            }
        }

        $markup = match ($rule->rule_type) {
            MarkupRule::TYPE_PERCENTAGE => $net->multipliedBy($value)->dividedBy(100, 4, RoundingMode::HALF_UP),
            MarkupRule::TYPE_FLAT_PER_PNR => $value,
            MarkupRule::TYPE_FLAT_PER_PAX => $value->multipliedBy($passengerCount),
            MarkupRule::TYPE_FLAT_PER_DESTINATION => $value,
            default => BigDecimal::of('0.00'),
        };

        return [
            'gross_amount' => (string) $net->plus($markup)->toScale(2, RoundingMode::HALF_UP),
            'markup_amount' => (string) $markup->toScale(2, RoundingMode::HALF_UP),
            'rule' => $rule,
            'audience' => $audience,
            'value' => (string) $value,
            'capped' => $capped,
        ];
    }

    /**
     * The fine-grained audience a user prices as. $legacyRole is the
     * controller's own agent/sub_agent/b2c classification, used for guests.
     */
    public function audienceFor(?User $user, string $legacyRole = MarkupRule::ROLE_B2C): string
    {
        if (! $user) {
            return in_array($legacyRole, MarkupRule::ROLES, true) ? $legacyRole : MarkupRule::ROLE_B2C;
        }

        return match ($user->role) {
            User::ROLE_SUPER_ADMIN, User::ROLE_STAFF => MarkupRule::ROLE_ADMIN,
            User::ROLE_PARTNER_ADMIN => MarkupRule::ROLE_WHITE_LABEL,
            User::ROLE_AGENT => MarkupRule::ROLE_AGENT,
            User::ROLE_SUB_AGENT => MarkupRule::ROLE_SUB_AGENT,
            User::ROLE_STAFF_USER => MarkupRule::ROLE_STAFF,
            User::ROLE_API_USER => MarkupRule::ROLE_API_PARTNER,
            default => MarkupRule::ROLE_B2C,
        };
    }

    /** The legacy role string the booking controllers pass for a user. */
    public function legacyRoleFor(?User $user): string
    {
        return match ($user?->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => MarkupRule::ROLE_AGENT,
            User::ROLE_SUB_AGENT => MarkupRule::ROLE_SUB_AGENT,
            default => MarkupRule::ROLE_B2C,
        };
    }

    protected function actingUser(): ?User
    {
        if (! app()->bound('request')) {
            return null;
        }

        try {
            $user = request()->user() ?? auth('sanctum')->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /** @return Collection<int, MarkupRule> */
    protected function candidates(array $ancestorIds, Tenant $tenant, string $module, string $role, string $audience, ?User $user, array $context): Collection
    {
        $roles = array_values(array_unique([$audience, $role, MarkupRule::ROLE_ALL]));

        $matching = MarkupRule::withoutTenantScope()
            ->whereIn('tenant_id', $ancestorIds)
            ->whereIn('module', [$module, MarkupRule::MODULE_ALL])
            ->whereIn('applies_to_role', $roles)
            ->where('is_active', true)
            ->get()
            ->filter(fn (MarkupRule $r) => $this->matchesTarget($r, $tenant, $user)
                && $this->matchesDestination($r, $context)
                && $this->matchesScope($r, $context))
            ->values();

        if ($role === $audience) {
            return $matching;
        }

        // Rules written for this user's own audience (or 'all') take over
        // completely once any exist in the chain; the legacy agent/b2c rules
        // are only a fallback so pre-existing setups keep pricing the same.
        // Without this, e.g. an old "b2c · flights 6%" rule would beat a new
        // "Admins · all services 1%" rule just for naming a single service.
        $own = $matching->filter(fn (MarkupRule $r) => $r->applies_to_role !== $role)->values();

        return $own->isNotEmpty() ? $own : $matching;
    }

    protected function mostSpecific(Collection $rules, string $audience): ?MarkupRule
    {
        $best = null;
        $bestScore = -1;

        foreach ($rules as $rule) {
            $score = $this->specificity($rule, $audience);
            // Ties: the most recently created rule wins.
            if ($score > $bestScore || ($score === $bestScore && $rule->id > $best->id)) {
                $best = $rule;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * How narrowly a rule is scoped — picks between several matching rules
     * of the same tenant: user > account > service > audience > supplier >
     * domestic/international.
     */
    public function specificity(MarkupRule $rule, ?string $audience = null): int
    {
        $roleScore = match (true) {
            $rule->applies_to_role === MarkupRule::ROLE_ALL => 0,
            $audience !== null && $rule->applies_to_role === $audience => 8,
            default => 4, // matched through the legacy agent/sub_agent/b2c role
        };

        return ($rule->user_id !== null ? 64 : 0)
            + ($rule->target_tenant_id !== null ? 32 : 0)
            + ($rule->module !== MarkupRule::MODULE_ALL ? 16 : 0)
            + $roleScore
            + ($rule->supplier_code !== null ? 2 : 0)
            + ($rule->booking_scope !== MarkupRule::SCOPE_BOTH ? 1 : 0);
    }

    protected function matchesTarget(MarkupRule $rule, Tenant $tenant, ?User $user): bool
    {
        if ($rule->user_id !== null && (int) $rule->user_id !== (int) $user?->id) {
            return false;
        }

        if ($rule->target_tenant_id !== null && (int) $rule->target_tenant_id !== (int) $tenant->id) {
            return false;
        }

        return true;
    }

    /**
     * Domestic/international + specific-supplier matching — applies to
     * every rule type. A rule left at booking_scope=both / no supplier_code
     * matches anything on that dimension.
     */
    protected function matchesScope(MarkupRule $rule, array $context): bool
    {
        if ($rule->booking_scope === MarkupRule::SCOPE_DOMESTIC && ! empty($context['is_international'])) {
            return false;
        }

        if ($rule->booking_scope === MarkupRule::SCOPE_INTERNATIONAL && empty($context['is_international'])) {
            return false;
        }

        if ($rule->supplier_code !== null) {
            $contextSupplier = strtoupper((string) ($context['supplier_code'] ?? ''));

            if ($contextSupplier === '' || strtoupper($rule->supplier_code) !== $contextSupplier) {
                return false;
            }
        }

        return true;
    }

    protected function matchesDestination(MarkupRule $rule, array $context): bool
    {
        if ($rule->rule_type !== MarkupRule::TYPE_FLAT_PER_DESTINATION) {
            return true;
        }

        $destination = strtoupper((string) ($context['destination'] ?? ''));
        $origin = strtoupper((string) ($context['origin'] ?? ''));

        if ($rule->destination && strtoupper($rule->destination) !== $destination) {
            return false;
        }

        if ($rule->origin && strtoupper($rule->origin) !== $origin) {
            return false;
        }

        // A flat_per_destination rule with neither origin nor destination
        // set never matches — it would otherwise silently behave like
        // flat_per_pnr, which is a distinct, explicitly-chosen type.
        return (bool) ($rule->destination || $rule->origin);
    }
}
