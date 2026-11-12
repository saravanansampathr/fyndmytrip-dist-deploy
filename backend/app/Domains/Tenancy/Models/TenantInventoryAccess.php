<?php

namespace App\Domains\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inventory switched on or off for one account. See
 * InventoryAccessService for how the rows are read.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $inventory_key
 * @property bool $is_enabled
 * @property int|null $assigned_by_user_id
 */
class TenantInventoryAccess extends Model
{
    protected $table = 'tenant_inventory_access';

    protected $fillable = ['tenant_id', 'inventory_key', 'is_enabled', 'assigned_by_user_id'];

    protected $casts = ['is_enabled' => 'boolean'];

    /** Shown in Admin settings → Change history. */
    public function getNameAttribute(): string
    {
        return ($this->tenant?->name ?? 'Account #'.$this->tenant_id).': '.$this->inventory_key.' '.($this->is_enabled ? 'on' : 'off');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
