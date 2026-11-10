<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Exceptions\MaxHierarchyDepthExceededException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantHierarchyClosure;
use Illuminate\Support\Facades\DB;

/**
 * Creates and re-parents tenants while keeping tenant_hierarchy_closure
 * consistent, and enforces the recursive max-depth rule described in the
 * Master Brief §3 and Database Design Document §3.1/§3.5.
 */
class TenantService
{
    /**
     * Create a new tenant beneath (or, when $parent is null, directly under
     * the conceptual Master root) an existing tenant, maintaining the
     * closure table and enforcing both the platform-wide and the
     * per-ancestor max_sub_tenant_depth ceilings.
     *
     * @throws MaxHierarchyDepthExceededException
     */
    public function createTenant(array $attributes, ?Tenant $parent = null): Tenant
    {
        return DB::transaction(function () use ($attributes, $parent) {
            if ($parent) {
                $this->assertWithinDepthLimits($parent);
            }

            $tenant = Tenant::create(array_merge($attributes, [
                'parent_tenant_id' => $parent?->id,
            ]));

            // Self-referencing depth-0 row.
            TenantHierarchyClosure::query()->create([
                'ancestor_tenant_id' => $tenant->id,
                'descendant_tenant_id' => $tenant->id,
                'depth' => 0,
            ]);

            if ($parent) {
                // One row per ancestor of the parent (parent included at
                // depth 0), each pointing at the new tenant with depth+1.
                $ancestorRows = TenantHierarchyClosure::query()
                    ->where('descendant_tenant_id', $parent->id)
                    ->get(['ancestor_tenant_id', 'depth']);

                $rows = $ancestorRows->map(fn ($row) => [
                    'ancestor_tenant_id' => $row->ancestor_tenant_id,
                    'descendant_tenant_id' => $tenant->id,
                    'depth' => $row->depth + 1,
                ])->all();

                TenantHierarchyClosure::query()->insert($rows);
            }

            return $tenant;
        });
    }

    /**
     * @throws MaxHierarchyDepthExceededException
     */
    protected function assertWithinDepthLimits(Tenant $parent): void
    {
        $platformCeiling = (int) config('tenancy.platform_max_sub_tenant_depth');

        $ancestorRows = TenantHierarchyClosure::query()
            ->where('descendant_tenant_id', $parent->id)
            ->with('ancestor')
            ->get();

        foreach ($ancestorRows as $row) {
            $newChildDepthFromAncestor = $row->depth + 1;

            if ($newChildDepthFromAncestor > $platformCeiling) {
                throw MaxHierarchyDepthExceededException::forPlatform($platformCeiling);
            }

            $ancestor = $row->ancestor;

            if ($ancestor && $newChildDepthFromAncestor > $ancestor->max_sub_tenant_depth) {
                throw MaxHierarchyDepthExceededException::forTenant($ancestor->id, $ancestor->max_sub_tenant_depth);
            }
        }
    }

    /**
     * All tenant IDs at or below $tenant in the hierarchy, $tenant included.
     *
     * @return array<int>
     */
    public function getDescendantIds(Tenant $tenant): array
    {
        return TenantHierarchyClosure::query()
            ->where('ancestor_tenant_id', $tenant->id)
            ->pluck('descendant_tenant_id')
            ->all();
    }

    /**
     * All tenant IDs at or above $tenant in the hierarchy, $tenant included,
     * ordered from $tenant up to the top-level root.
     *
     * @return array<int>
     */
    public function getAncestorIds(Tenant $tenant): array
    {
        return TenantHierarchyClosure::query()
            ->where('descendant_tenant_id', $tenant->id)
            ->orderBy('depth')
            ->pluck('ancestor_tenant_id')
            ->all();
    }

    /**
     * All tenant IDs at or below $tenant, $tenant included — robust to the
     * closure-table gap documented on
     * TenantUserController::isWithinHierarchy(): a tenant seeded directly
     * (rather than through createTenant()) has no closure rows at all, which
     * would make getDescendantIds() alone under-report for reporting/
     * analytics scoping. Unions the closure-table result with a direct
     * parent_tenant_id BFS so cross-tenant reports are correct regardless of
     * how any tenant in the subtree was created.
     *
     * @return array<int>
     */
    public function getDescendantIdsRobust(Tenant $tenant): array
    {
        $ids = $this->getDescendantIds($tenant);

        // BFS down parent_tenant_id starting from $tenant, in case some
        // descendants have no closure rows at all.
        $frontier = [$tenant->id];
        $visited = array_flip(array_merge($ids, $frontier));

        while (! empty($frontier)) {
            $children = Tenant::query()
                ->whereIn('parent_tenant_id', $frontier)
                ->pluck('id')
                ->all();

            $frontier = [];

            foreach ($children as $childId) {
                if (! isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $frontier[] = $childId;
                }
            }
        }

        return array_values(array_unique(array_merge($ids, array_keys($visited))));
    }

    /**
     * Move an existing subtree under a new parent, rewriting every closure
     * row that crosses the subtree boundary. Rows fully inside the subtree
     * (both ancestor and descendant already part of it) are untouched.
     *
     * @throws MaxHierarchyDepthExceededException
     */
    public function reparentTenant(Tenant $tenant, Tenant $newParent): Tenant
    {
        return DB::transaction(function () use ($tenant, $newParent) {
            $this->assertWithinDepthLimits($newParent);

            $subtreeIds = $this->getDescendantIds($tenant);

            // Drop every closure row that links an outside ancestor to a
            // node inside the subtree — these no longer apply once the
            // subtree hangs off a different parent.
            TenantHierarchyClosure::query()
                ->whereIn('descendant_tenant_id', $subtreeIds)
                ->whereNotIn('ancestor_tenant_id', $subtreeIds)
                ->delete();

            $newAncestorRows = TenantHierarchyClosure::query()
                ->where('descendant_tenant_id', $newParent->id)
                ->get(['ancestor_tenant_id', 'depth']);

            $subtreeRows = TenantHierarchyClosure::query()
                ->whereIn('ancestor_tenant_id', $subtreeIds)
                ->whereIn('descendant_tenant_id', $subtreeIds)
                ->get(['ancestor_tenant_id', 'descendant_tenant_id', 'depth']);

            $inserts = [];

            foreach ($newAncestorRows as $newAncestorRow) {
                foreach ($subtreeRows as $subtreeRow) {
                    if ($subtreeRow->ancestor_tenant_id !== $tenant->id) {
                        continue;
                    }

                    $inserts[] = [
                        'ancestor_tenant_id' => $newAncestorRow->ancestor_tenant_id,
                        'descendant_tenant_id' => $subtreeRow->descendant_tenant_id,
                        'depth' => $newAncestorRow->depth + 1 + $subtreeRow->depth,
                    ];
                }
            }

            if ($inserts !== []) {
                TenantHierarchyClosure::query()->insert($inserts);
            }

            $tenant->update(['parent_tenant_id' => $newParent->id]);

            return $tenant->fresh();
        });
    }
}
