<?php

namespace App\Domains\Inventory\Models\Concerns;

/**
 * Shared behaviour across all six dedicated per-product-module inventory
 * models (Flight/Hotel/Bus/Cab/Insurance/Cruise). Each model still declares
 * its own full $fillable/$casts (PHP property defaults can't call a helper
 * method, so the handful of common columns — title, capacity, pricing,
 * validity window, distribution flags, status — are simply repeated in
 * each model's arrays), but the actual behaviour those columns need
 * (default status/currency on create, the sellability check) lives here
 * once instead of six times.
 */
trait HasCommonInventoryFields
{
    protected static function bootHasCommonInventoryFields(): void
    {
        static::creating(function ($item) {
            if (empty($item->status)) {
                $item->status = self::STATUS_ACTIVE;
            }

            if (empty($item->currency)) {
                $item->currency = 'INR';
            }
        });
    }

    public function isSellable(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->capacity !== null && $this->capacity <= 0) {
            return false;
        }

        if ($this->valid_to && $this->valid_to->isPast()) {
            return false;
        }

        return true;
    }
}
