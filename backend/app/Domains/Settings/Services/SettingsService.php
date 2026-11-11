<?php

namespace App\Domains\Settings\Services;

use App\Domains\Settings\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Business settings that admins change from the panel instead of asking
 * for a code release: tax rates, invoice numbering, login security,
 * alert recipients. Each group has defaults here; whatever an admin saves
 * is merged on top (so a new setting added in a later release simply
 * shows its default until someone changes it).
 */
class SettingsService
{
    protected const CACHE_PREFIX = 'fyndmytrip:settings:';

    /** @return array<string, array> */
    public static function defaults(): array
    {
        $services = ['flight', 'hotel', 'bus', 'cab', 'holiday', 'insurance', 'visa', 'cruise', 'activity'];

        return [
            'tax' => [
                // State code of the platform's own GST registration, used
                // when the company GSTIN hasn't been entered yet.
                'home_state' => (string) config('booking.gst_home_state', '33'),
                // GST charged on the platform's service fee (the margin),
                // per service. The margin shown to customers already
                // includes this tax.
                'gst_rates' => array_fill_keys($services, 18),
                'sac_codes' => [
                    'flight' => '998551', 'bus' => '998551', 'cab' => '998551', 'cruise' => '998551',
                    'hotel' => '998552', 'holiday' => '998555', 'insurance' => '998559',
                    'visa' => '998559', 'activity' => '998559',
                ],
                'invoice_prefix' => 'FMT',
                'credit_note_prefix' => 'FCN',
                // Tax collected at source on overseas tour packages
                // (Income Tax Act s.206C(1G)). Off until the CA confirms.
                'tcs' => [
                    'enabled' => false,
                    'threshold' => 1000000,
                    'rate_below' => 5,
                    'rate_above' => 20,
                    'require_pan' => true,
                ],
            ],
            'security' => [
                'login_max_attempts' => 5,
                'login_lockout_minutes' => 15,
                // How long a login stays valid before signing in again.
                'staff_session_hours' => 12,
                'customer_session_days' => 30,
                // Roles that must set up two-factor login.
                'require_2fa_roles' => [],
            ],
            // Values that used to be .env-only; applyPlatformConfig() puts
            // whatever is saved here over the config defaults at boot.
            'platform' => [
                'home_country' => (string) config('booking.home_country', 'IN'),
                'search_cache_seconds' => (int) config('suppliers.search_cache_ttl_seconds', 240),
                'supplier_failure_threshold' => (int) config('suppliers.circuit_breaker.failure_threshold', 3),
                'supplier_cooldown_seconds' => (int) config('suppliers.circuit_breaker.cooldown_seconds', 60),
                // Unpaid held bookings are cancelled (and seats released)
                // after this long. 0 = never.
                'hold_expiry_minutes' => 60,
                // Staff are alerted when a confirmed booking is still not
                // ticketed this long before departure / check-in.
                'ticketing_alert_hours' => 24,
                'maintenance' => [
                    'enabled' => false,
                    'message' => 'We are upgrading the site and will be back shortly. Existing bookings are safe.',
                ],
            ],
            'currency' => [
                // Currencies customers can choose. The pricing currency
                // (INR) is always available.
                'enabled' => \App\Domains\Payments\Services\CurrencyService::SUPPORTED,
                // Added on top of the market rate when charging in another
                // currency, to cover card FX costs.
                'fx_margin_percent' => 0,
                // Fixed rates (1 INR = x) used instead of the live rate.
                'fixed_rates' => [],
                // Country → currency picks that differ from the built-in map.
                'country_currency' => [],
            ],
            // Roles whose rights an admin has edited (the deploy seeder
            // leaves those alone).
            'roles' => ['customised' => []],
            'monitoring' => [
                // Who gets emailed about failed bookings, failed payments
                // and backup problems.
                'alert_emails' => [],
                'backup_keep_days' => 14,
            ],
        ];
    }

    /** Saved Platform values over the config defaults (called at boot). */
    public function applyPlatformConfig(): void
    {
        try {
            $saved = Cache::remember(self::CACHE_PREFIX.'platform:raw', now()->addHour(), function () {
                if (! Schema::hasTable('app_settings')) {
                    return [];
                }

                return AppSetting::query()->where('key', 'platform')->value('value') ?? [];
            });
        } catch (\Throwable) {
            return;
        }
        $map = [
            'home_country' => 'booking.home_country',
            'search_cache_seconds' => 'suppliers.search_cache_ttl_seconds',
            'supplier_failure_threshold' => 'suppliers.circuit_breaker.failure_threshold',
            'supplier_cooldown_seconds' => 'suppliers.circuit_breaker.cooldown_seconds',
        ];
        foreach ($map as $key => $path) {
            if (is_array($saved) && array_key_exists($key, $saved) && $saved[$key] !== null && $saved[$key] !== '') {
                config([$path => $saved[$key]]);
            }
        }
    }

    public function get(string $group): array
    {
        $defaults = self::defaults()[$group] ?? [];

        $saved = Cache::remember(self::CACHE_PREFIX.$group, now()->addHour(), function () use ($group) {
            if (! Schema::hasTable('app_settings')) {
                return [];
            }

            return AppSetting::query()->where('key', $group)->value('value') ?? [];
        });

        // Saved lists replace the default list whole (not index by index).
        return $this->mergeKeepingLists($defaults, is_array($saved) ? $saved : []);
    }

    public function value(string $group, string $path, mixed $default = null): mixed
    {
        return data_get($this->get($group), $path, $default);
    }

    public function put(string $group, array $values, ?int $userId = null): array
    {
        if (! array_key_exists($group, self::defaults())) {
            throw ValidationException::withMessages(['group' => ["Unknown settings group '{$group}'."]]);
        }

        // Lists replace rather than merge (array_replace_recursive would
        // keep stale entries of a shortened list).
        $current = AppSetting::query()->where('key', $group)->value('value') ?? [];
        $merged = $this->mergeKeepingLists(is_array($current) ? $current : [], $values);

        AppSetting::query()->updateOrCreate(['key' => $group], ['value' => $merged, 'updated_by' => $userId]);
        Cache::forget(self::CACHE_PREFIX.$group);
        Cache::forget(self::CACHE_PREFIX.$group.':raw');
        if ($group === 'platform') {
            $this->applyPlatformConfig();
        }

        return $this->get($group);
    }

    protected function mergeKeepingLists(array $base, array $patch): array
    {
        foreach ($patch as $k => $v) {
            if (is_array($v) && ! array_is_list($v) && is_array($base[$k] ?? null)) {
                $base[$k] = $this->mergeKeepingLists($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
