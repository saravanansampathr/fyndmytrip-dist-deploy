<?php

namespace App\Domains\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Storefront footer "Subscribe for deals" signups — see the
 * create_newsletter_subscribers_table migration's docblock for why this
 * doesn't use BelongsToTenant's automatic scope (tenant_id nullable, like
 * Coupon).
 */
class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'tenant_id',
        'email',
        'subscribed_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];
}
