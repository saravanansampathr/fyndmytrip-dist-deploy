<?php

namespace App\Domains\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Flattened ancestor/descendant closure table — see Database Design Document
 * §3.5. Includes a self-referencing depth-0 row for every tenant. Maintained
 * by TenantService whenever a tenant is created or re-parented so that "all
 * descendants of X" / "all ancestors of X" queries stay O(1)-indexed instead
 * of requiring a recursive CTE on every request.
 *
 * @property int $ancestor_tenant_id
 * @property int $descendant_tenant_id
 * @property int $depth
 */
class TenantHierarchyClosure extends Model
{
    public $incrementing = false;
    public $timestamps = false;

    protected $table = 'tenant_hierarchy_closure';

    protected $primaryKey = null;

    protected $fillable = [
        'ancestor_tenant_id',
        'descendant_tenant_id',
        'depth',
    ];

    protected $casts = [
        'depth' => 'integer',
    ];

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'ancestor_tenant_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'descendant_tenant_id');
    }
}
