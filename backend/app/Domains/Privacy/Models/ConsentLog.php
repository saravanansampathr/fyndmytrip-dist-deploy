<?php

namespace App\Domains\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tenant_id', 'email', 'purpose', 'granted', 'ip', 'user_agent'];

    protected $casts = ['granted' => 'boolean'];
}
