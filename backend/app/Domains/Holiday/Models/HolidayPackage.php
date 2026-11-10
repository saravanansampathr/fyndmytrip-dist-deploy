<?php

namespace App\Domains\Holiday\Models;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A sellable holiday/tour package in the DMC catalog. Deliberately mirrors
 * CashbackRule's tenant convention: tenant_id NULL is a platform-wide
 * catalog entry, not automatically scoped (no BelongsToTenant trait) — the
 * owning controller decides who may see/manage which rows, same as
 * PaymentMethodRoute and CashbackRule.
 */
class HolidayPackage extends Model
{
    public const SUPPLIER_AKBAR = 'akbar';

    public const SUPPLIER_TRIPJACK = 'tripjack';

    public const SUPPLIER_MANUAL = 'manual';

    public const TYPE_TOUR = 'tour';

    public const TYPE_ACTIVITY = 'activity';

    public const SCOPE_DOMESTIC = 'domestic';

    public const SCOPE_INTERNATIONAL = 'international';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_EXPIRED = 'expired';

    public const DEFAULT_MARKUP_PERCENT = '10.00';

    protected $fillable = [
        'tenant_id',
        'share_on_platform_site',
        'share_with_network',
        'supplier_code',
        'supplier_package_ref',
        'slug',
        'name',
        'package_type',
        'booking_scope',
        'category',
        'destination_country',
        'destination_city',
        'route_summary',
        'duration_nights',
        'duration_days',
        'max_pax_label',
        'hotel_category',
        'is_fixed_departure',
        'valid_from',
        'valid_to',
        'cover_image_url',
        'net_price',
        'markup_percent',
        'sell_price',
        'currency',
        'inclusions',
        'exclusions',
        'terms',
        'status',
        'synced_at',
    ];

    protected $casts = [
        'share_on_platform_site' => 'boolean',
        'share_with_network' => 'boolean',
        'is_fixed_departure' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'net_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'inclusions' => 'array',
        'exclusions' => 'array',
        'terms' => 'array',
        'synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $package) {
            if (empty($package->status)) {
                $package->status = self::STATUS_DRAFT;
            }

            if (empty($package->markup_percent)) {
                $package->markup_percent = self::DEFAULT_MARKUP_PERCENT;
            }

            if (empty($package->slug) && ! empty($package->name)) {
                $package->slug = static::uniqueSlugFor($package->name);
            }

            if (empty($package->sell_price) && ! empty($package->net_price)) {
                $package->sell_price = static::priceWithMarkup($package->net_price, $package->markup_percent);
            }
        });
    }

    /**
     * The "+10% from that" markup rule, applied consistently everywhere a
     * net (supplier) price becomes a sell (customer-facing) price — here,
     * on price tiers, and on visa products.
     */
    public static function priceWithMarkup(string|float $netPrice, string|float $markupPercent): string
    {
        $net = \Brick\Math\BigDecimal::of((string) $netPrice);
        $markup = \Brick\Math\BigDecimal::of((string) $markupPercent);
        $multiplier = \Brick\Math\BigDecimal::of('100')->plus($markup)->dividedBy('100', 6, \Brick\Math\RoundingMode::HALF_UP);

        return (string) $net->multipliedBy($multiplier)->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
    }

    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (static::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function itineraryDays(): HasMany
    {
        return $this->hasMany(HolidayPackageItineraryDay::class)->orderBy('day_number');
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(HolidayPackagePriceTier::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(HolidayPackageMedia::class)->orderBy('sort_order');
    }

    public function isBookable(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->is_fixed_departure && $this->valid_to && $this->valid_to->isPast()) {
            return false;
        }

        return true;
    }
}
