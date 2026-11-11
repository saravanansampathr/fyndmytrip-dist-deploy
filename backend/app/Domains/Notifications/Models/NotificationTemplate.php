<?php

namespace App\Domains\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['tenant_id', 'event', 'channel', 'subject', 'body', 'provider_template_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
