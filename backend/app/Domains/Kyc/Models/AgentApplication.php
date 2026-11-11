<?php

namespace App\Domains\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentApplication extends Model
{
    protected $fillable = ['parent_tenant_id', 'company_name', 'contact_name', 'email', 'phone', 'pan', 'gstin', 'address', 'city', 'state', 'password_hash', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'tenant_id', 'user_id'];

    protected $hidden = ['password_hash'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }
}
