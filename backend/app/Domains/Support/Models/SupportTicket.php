<?php

namespace App\Domains\Support\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Raise amendment like requesting any correction like name spell mistake,
 * or date change, or add the baggage or know the value on any product on
 * specific bookings so that it will [be] assigned to concerned agent/staff
 * to look into it and give solution against each ticket" — always tied to
 * one booking (see CATEGORIES below for the concrete request types named in
 * that backlog item), with an assignee and a reply thread
 * (SupportTicketReply) so staff can converse and record the resolution.
 */
class SupportTicket extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED];

    public const CATEGORY_NAME_CORRECTION = 'name_correction';
    public const CATEGORY_DATE_CHANGE = 'date_change';
    public const CATEGORY_ADD_BAGGAGE = 'add_baggage';
    public const CATEGORY_PRICING_QUERY = 'pricing_query';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_NAME_CORRECTION,
        self::CATEGORY_DATE_CHANGE,
        self::CATEGORY_ADD_BAGGAGE,
        self::CATEGORY_PRICING_QUERY,
        self::CATEGORY_OTHER,
    ];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'category',
        'subject',
        'description',
        'status',
        'priority',
        'raised_by_user_id',
        'assigned_to_user_id',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id')->orderBy('created_at');
    }
}
