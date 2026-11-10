<?php

namespace App\Domains\Suppliers\Services;

use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Resolves a tenant's effective markup for a module + role and applies the
 * ceiling rule from Database Design Document §8.3: "a Sub-Agent's
 * markup_rules row can never resolve to an effective markup exceeding its
 * parent Agent's rule for the same module" — enforced here by walking the
 * tenant's ancestor chain and taking the most restrictive value found
 * anywhere above it, rather than trusting the tenant's own row alone.
 *
 * Four rule types (Database Design Document §8.3 / the whitelabel-markup
 * feature request): percentage of net fare, a flat amount once per
 * booking ("per PNR"), a flat amount per passenger, and a flat amount that
 * only applies on a matching origin/destination. Each tenant picks ONE
 * type per rule — types are never stacked — so the type actually applied
 * is whichever the nearest tenant in the chain (self first, then parent,
 * grandparent, …) has configured for this module + role; its magnitude is
 * then capped to the lowest value configured for that SAME type anywhere
 * in the chain, so a child can't out-configure its parent's cap.
 */
class MarkupService
{
    public function __construct(protected TenantService $tenantService) {}

    /**
     * @param  array{passenger_count?: int, origin?: string, destination?: string, is_international?: bool, supplier_code?: string}  $context
     * @return array{gross_amount: string, markup_amount: string}
     */
    public function applyMarkup(Tenant $tenant, string $module, string $role, string $netAmount, array $context = []): array
    {
        $net = BigDecimal::of($netAmount);
        $passengerCount = max(1, (int) ($context['passenger_count'] ?? 1));

        $rule = $this->nearestRule($tenant, $module, $role, $context);

        if ($rule === null) {
            return [
                'gross_amount' => (string) $net->toScale(2, RoundingMode::HALF_UP),
                'markup_amount' => '0.00',
            ];
        }

        $value = $this->ceilingValue($tenant, $module, $role, $rule->rule_type, $context);

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
        ];
    }

    /**
     * The nearest tenant in the chain (self, then parent, …) that has an
     * active rule for this module + role (+ a matching destination, for
     * flat_per_destination rules, and a matching domestic/international +
     * supplier scope) — its rule_type is what gets applied. A tenant can now
     * have more than one active rule for the same module + role (e.g. one
     * scoped to international, one to domestic, one to a specific airline),
     * so within each tenant the MOST SPECIFIC matching rule wins — see
     * specificity() — rather than assuming exactly one row per tenant.
     */
    protected function nearestRule(Tenant $tenant, string $module, string $role, array $context): ?MarkupRule
    {
        $ancestorIds = $this->tenantService->getAncestorIds($tenant); // tenant included, ordered near->far

        $rules = MarkupRule::withoutTenantScope()
            ->whereIn('tenant_id', $ancestorIds)
            ->where('module', $module)
            ->where('applies_to_role', $role)
            ->where('is_active', true)
            ->get()
            ->filter(fn (MarkupRule $r) => $this->matchesDestination($r, $context) && $this->matchesScope($r, $context));

        $mostSpecificByTenant = $rules->groupBy('tenant_id')
            ->map(fn ($group) => $group->sortByDesc(fn (MarkupRule $r) => $this->specificity($r))->first());

        foreach ($ancestorIds as $id) {
            if ($mostSpecificByTenant->has($id)) {
                return $mostSpecificByTenant->get($id);
            }
        }

        return null;
    }

    /**
     * How narrowly a rule is scoped — used to pick between several rules a
     * single tenant has for the same module + role (e.g. a general rule and
     * an airline-specific override): the one naming more dimensions wins.
     */
    protected function specificity(MarkupRule $rule): int
    {
        return ($rule->supplier_code !== null ? 2 : 0)
            + ($rule->booking_scope !== MarkupRule::SCOPE_BOTH ? 1 : 0);
    }

    /**
     * Domestic/international + specific-supplier matching — applies to
     * every rule type, unlike matchesDestination() which is
     * flat_per_destination-only. A rule left at booking_scope=both / no
     * supplier_code matches anything on that dimension, same as leaving
     * origin/destination empty already does.
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

    /**
     * Lowest value found across the tenant + every ancestor among rules of
     * this exact type (+ matching destination) for this module + role —
     * the safety ceiling a child tenant can never exceed. Falls back to
     * the nearest rule's own value if it's the only one of its type in the
     * chain (nothing above it to cap it against).
     */
    protected function ceilingValue(Tenant $tenant, string $module, string $role, string $ruleType, array $context): BigDecimal
    {
        $ancestorIds = $this->tenantService->getAncestorIds($tenant);

        $rules = MarkupRule::withoutTenantScope()
            ->whereIn('tenant_id', $ancestorIds)
            ->where('module', $module)
            ->where('applies_to_role', $role)
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->get()
            ->filter(fn (MarkupRule $r) => $this->matchesDestination($r, $context) && $this->matchesScope($r, $context));

        $lowest = null;

        foreach ($rules as $r) {
            $value = BigDecimal::of($r->value());

            if ($lowest === null || $value->isLessThan($lowest)) {
                $lowest = $value;
            }
        }

        return $lowest ?? BigDecimal::of('0.00');
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
