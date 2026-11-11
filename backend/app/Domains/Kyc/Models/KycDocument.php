<?php

namespace App\Domains\Kyc\Models;

use App\Domains\Cms\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycDocument extends Model
{
    public const TYPES = ['pan' => 'PAN card', 'gst' => 'GST certificate', 'id_proof' => 'Owner ID proof', 'address_proof' => 'Address proof', 'iata' => 'IATA certificate', 'other' => 'Other'];

    protected $fillable = ['agent_application_id', 'tenant_id', 'type', 'media_file_id', 'expires_on', 'status', 'reminded_at'];

    protected $casts = ['expires_on' => 'date', 'reminded_at' => 'datetime'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }
}
