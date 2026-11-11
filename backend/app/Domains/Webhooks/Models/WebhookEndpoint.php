<?php

namespace App\Domains\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    protected $fillable = ['tenant_id', 'url', 'secret', 'events', 'is_active', 'failures_in_a_row', 'last_success_at'];

    protected $casts = ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean', 'last_success_at' => 'datetime'];

    protected $hidden = ['secret'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
