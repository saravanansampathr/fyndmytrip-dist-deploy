<?php

namespace App\Domains\Settings\Models;

use App\Domains\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDocument extends Model
{
    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $fillable = [
        'booking_id', 'type', 'financial_year', 'sequence', 'number', 'original_invoice_id',
        'amount', 'tax_amount', 'tcs_amount', 'details', 'issued_at',
    ];

    protected $casts = [
        'details' => 'array',
        'issued_at' => 'datetime',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tcs_amount' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withoutGlobalScopes();
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }
}
