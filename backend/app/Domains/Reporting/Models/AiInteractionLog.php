<?php

namespace App\Domains\Reporting\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Database Design Document §10.1 — every call from the Laravel monolith to
 * the AI Gateway microservice is logged here for cost tracking, quality
 * monitoring, and future model fine-tuning. input_summary/output_summary
 * are truncated/redacted, not the full raw prompt, per data-minimisation
 * practice (DPDP Act 2023).
 */
class AiInteractionLog extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'feature',
        'input_summary',
        'output_summary',
        'model_used',
        'latency_ms',
    ];

    protected $casts = [
        'latency_ms' => 'integer',
    ];
}
