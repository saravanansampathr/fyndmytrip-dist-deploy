<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflinePaymentAttachment extends Model
{
    protected $fillable = [
        'offline_payment_request_id',
        'disk_path',
        'original_filename',
        'mime_type',
        'size_bytes',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(OfflinePaymentRequest::class, 'offline_payment_request_id');
    }
}
