<?php

namespace App\Domains\Corporate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripRequest extends Model
{
    protected $fillable = ['tenant_id', 'requested_by', 'travellers', 'module', 'origin', 'destination', 'depart_date', 'return_date', 'cabin_class', 'estimated_amount', 'nights', 'purpose', 'cost_center', 'policy_issues', 'status', 'decided_by', 'decided_at', 'decision_note', 'booking_id'];

    protected $casts = ['depart_date' => 'date', 'return_date' => 'date', 'estimated_amount' => 'decimal:2', 'policy_issues' => 'array', 'decided_at' => 'datetime'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
