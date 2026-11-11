<?php

namespace App\Domains\Wallet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A before/after snapshot of one credit change — see the
 * 2024_02_01_000042 migration's docblock and CreditController::update().
 */
class WalletCreditAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'wallet_credit_audit_logs';

    protected $fillable = [
        'wallet_id',
        'tenant_id',
        'changed_by_user_id',
        'old_credit_limit',
        'new_credit_limit',
        'old_credit_terms',
        'new_credit_terms',
        'old_credit_next_due_at',
        'new_credit_next_due_at',
        'old_credit_status',
        'new_credit_status',
        'remarks',
        'created_at',
    ];

    protected $casts = [
        'old_credit_limit' => 'decimal:2',
        'new_credit_limit' => 'decimal:2',
        'old_credit_next_due_at' => 'date',
        'new_credit_next_due_at' => 'date',
        'created_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
