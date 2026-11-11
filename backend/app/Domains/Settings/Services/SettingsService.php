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
            'monitoring' => [
                // Who gets emailed about failed bookings, failed payments
                // and backup problems.
                'alert_emails' => [],
                'backup_keep_days' => 14,
            ],
        ];
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

        return array_replace_recursive($defaults, is_array($saved) ? $saved : []);
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
