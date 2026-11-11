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

    /**
     * What a traveller of each occupancy pays when the package has no
     * price tier of its own for it, as a share of the adult (sharing)
     * price. Children and infants used to silently pay the full adult fare.
     */
    public const OCCUPANCY_FALLBACK_FACTORS = [
        HolidayPackagePriceTier::OCC_ADULT_SHARING => '1',
        HolidayPackagePriceTier::OCC_ADULT_SINGLE => '1',
        HolidayPackagePriceTier::OCC_CHILD_WITH_BED => '0.75',
        HolidayPackagePriceTier::OCC_CHILD_WITHOUT_BED => '0.5',
        HolidayPackagePriceTier::OCC_INFANT => '0.1',
    ];

    public const OCCUPANCY_FALLBACK_AGE_LABELS = [
        HolidayPackagePriceTier::OCC_CHILD_WITH_BED => 'Age 5y - 11y',
        HolidayPackagePriceTier::OCC_CHILD_WITHOUT_BED => 'Age 2y - 5y',
        HolidayPackagePriceTier::OCC_INFANT => 'Under 2y',
    ];

    /**
     * Per-person price for one occupancy type: its own tier when set,
     * otherwise the adult (sharing) tier — or the package price — times
     * OCCUPANCY_FALLBACK_FACTORS.
     *
     * @return array{occupancy_type: string, age_label: ?string, sell_price: string, net_price: string, derived: bool}
     */
    public function priceForOccupancy(string $occupancy): array
    {
        $tiers = $this->relationLoaded('priceTiers') ? $this->priceTiers : $this->priceTiers()->get();
        $tier = $tiers->firstWhere('occupancy_type', $occupancy);
        if ($tier) {
            return [
                'occupancy_type' => $occupancy,
                'age_label' => $tier->age_label,
                'sell_price' => (string) ($tier->sell_price ?? $this->sell_price),
                'net_price' => (string) ($tier->net_price ?? $this->net_price),
                'derived' => false,
            ];
        }

        $adult = $tiers->firstWhere('occupancy_type', HolidayPackagePriceTier::OCC_ADULT_SHARING);
        $factor = \Brick\Math\BigDecimal::of(self::OCCUPANCY_FALLBACK_FACTORS[$occupancy] ?? '1');
        $scale = fn ($v) => (string) \Brick\Math\BigDecimal::of((string) ($v ?? 0))->multipliedBy($factor)->toScale(2, \Brick\Math\RoundingMode::HALF_UP);

        return [
            'occupancy_type' => $occupancy,
            'age_label' => self::OCCUPANCY_FALLBACK_AGE_LABELS[$occupancy] ?? null,
            'sell_price' => $scale($adult->sell_price ?? $this->sell_price),
            'net_price' => $scale($adult->net_price ?? $this->net_price),
            'derived' => true,
        ];
    }

    /** Who may see (and so book) which packages; guests pass null. */
    public function scopeVisibleTo($query, $user)
    {
        return $query->where(function ($q) use ($user) {
            if (! $user) {
                $q->whereNull('tenant_id')
                    ->orWhere('share_on_platform_site', true)
                    ->orWhere('share_with_network', true);

                return;
            }

            $q->whereNull('tenant_id')
                ->orWhere('tenant_id', $user->tenant_id)
                ->orWhere('share_with_network', true)
                ->when(is_null($user->tenant_id), fn ($w) => $w->orWhere('share_on_platform_site', true));
        });
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
