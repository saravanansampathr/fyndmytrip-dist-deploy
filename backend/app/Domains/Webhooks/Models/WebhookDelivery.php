<?php

namespace App\Domains\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_endpoint_id', 'event', 'booking_id', 'payload', 'status', 'attempts', 'response_code', 'error', 'next_attempt_at'];

    protected $casts = ['payload' => 'array', 'next_attempt_at' => 'datetime'];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
