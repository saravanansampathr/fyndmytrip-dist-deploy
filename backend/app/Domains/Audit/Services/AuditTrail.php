<?php

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Records who changed which business setting, with the old and new values.
 * Wired as a model observer (see AppServiceProvider) on the models admins
 * edit: margins, commission, cashback, coupons, policies, gateways,
 * suppliers, tenants, users, settings, templates, pages and roles.
 * Secrets are never stored: any field that looks like a key, password or
 * token is shown as changed, not with its value.
 */
class AuditTrail
{
    /** Models watched, with a short name shown in the admin screen. */
    public const WATCHED = [
        \App\Domains\Suppliers\Models\MarkupRule::class => 'Margin rule',
        \App\Domains\Commission\Models\CommissionRule::class => 'Commission rule',
        \App\Domains\Cashback\Models\CashbackRule::class => 'Cashback rule',
        \App\Domains\Coupons\Models\Coupon::class => 'Coupon',
        \App\Domains\Booking\Models\CancellationPolicy::class => 'Cancellation policy',
        \App\Domains\Payments\Models\PaymentGatewayConfig::class => 'Payment gateway',
        \App\Domains\Payments\Models\PaymentMethodRoute::class => 'Payment routing',
        \App\Domains\Notifications\Models\NotificationGatewayConfig::class => 'Notification gateway',
        \App\Domains\Notifications\Models\NotificationTemplate::class => 'Message template',
        \App\Domains\Suppliers\Models\TenantSupplierConfig::class => 'Supplier connection',
        \App\Domains\Tenancy\Models\Tenant::class => 'Account',
        \App\Models\User::class => 'User',
        \App\Domains\Settings\Models\AppSetting::class => 'Settings',
        \App\Domains\Cms\Models\CmsPage::class => 'Website page',
        \App\Domains\Auth\Models\RolePermission::class => 'Role permission',
        \App\Domains\Cms\Models\ContentItem::class => 'Website content',
        \App\Domains\Kyc\Models\AgentApplication::class => 'Agent application',
        \App\Domains\Webhooks\Models\WebhookEndpoint::class => 'Webhook',
        \App\Domains\Reporting\Models\FraudFlag::class => 'Fraud check',
        \App\Domains\Corporate\Models\TripRequest::class => 'Trip request',
    ];

    protected const SECRET = '/(secret|password|token|api_key|apikey|private|credential|auth_key|access_key|recovery)/i';

    protected const IGNORED = ['created_at', 'updated_at', 'last_login_at', 'remember_token', 'password_hash', 'two_factor_secret', 'two_factor_recovery_codes'];

    public static bool $paused = false;

    public function record(Model $model, string $action): void
    {
        if (self::$paused) {
            return;
        }
        try {
            $changes = $this->diff($model, $action);
            if ($action === 'updated' && $changes === []) {
                return;
            }
            $user = Auth::guard('sanctum')->user() ?? Auth::user();
            AuditLog::query()->create([
                'user_id' => $user?->id,
                'tenant_id' => $model->getAttribute('tenant_id') ?? $user?->tenant_id,
                'action' => $action,
                'subject_type' => class_basename($model),
                'subject_id' => is_numeric($model->getKey()) ? $model->getKey() : null,
                'label' => mb_substr((string) $this->label($model), 0, 191),
                'changes' => $changes ?: null,
                'ip' => app()->runningInConsole() ? null : request()?->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function diff(Model $model, string $action): array
    {
        $out = [];
        $keys = $action === 'updated' ? array_keys($model->getChanges()) : array_keys($model->getAttributes());
        foreach ($keys as $key) {
            if (in_array($key, self::IGNORED, true) || $key === $model->getKeyName()) {
                continue;
            }
            $old = $action === 'created' ? null : $model->getOriginal($key);
            $new = $action === 'deleted' ? null : $model->getAttribute($key);
            if ($action === 'updated' && $this->same($old, $new)) {
                continue;
            }
            $out[$key] = [$this->safe($key, $old), $this->safe($key, $new)];
        }

        return $out;
    }

    protected function same(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    protected function safe(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (preg_match(self::SECRET, $key)) {
            return '(hidden)';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (is_string($value) && ($decoded = json_decode($value, true)) !== null && is_array($decoded)) {
            $value = $decoded;
        }
        if (is_array($value)) {
            array_walk_recursive($value, function (&$v, $k) {
                if (is_string($k) && preg_match(self::SECRET, $k) && $v !== null && $v !== '') {
                    $v = '(hidden)';
                }
            });

            return $value;
        }
        if (is_object($value)) {
            return method_exists($value, 'value') ? $value->value : (string) json_encode($value);
        }

        return is_string($value) ? mb_substr($value, 0, 2000) : $value;
    }

    protected function label(Model $model): string
    {
        foreach (['name', 'title', 'code', 'key', 'slug', 'email', 'event', 'permission_key'] as $f) {
            $v = $model->getAttribute($f);
            if (is_string($v) && $v !== '') {
                return $f === 'permission_key' ? $model->getAttribute('role_key').': '.$v : $v;
            }
        }

        return (self::WATCHED[get_class($model)] ?? class_basename($model)).' #'.$model->getKey();
    }
}
