<?php

namespace App\Support;

use App\Domains\Tenancy\Models\Tenant;

/**
 * Resolves what a transactional email should say it's from — the gap
 * behind "customers should never know this is happening through
 * FyndMyTrip": every Mail::raw() call in this codebase (wallet
 * notifications, group-booking confirmations, password resets, package
 * detail requests, offline-payment rejections) previously sent with
 * Laravel's global config('mail.from') verbatim, so a White-Label
 * Partner's customer always saw "FyndMyTrip <no-reply@fyndmytrip.com>" in
 * their inbox no matter which brand they actually booked through.
 * HolidayPackageSearchController had already worked out the right fallback
 * chain for body text (tenant's legal_name/name, support_email,
 * support_phone) — this just makes that the one shared resolver and adds
 * the piece nothing did yet: the actual From header.
 *
 * Deliberately NOT a full "send as no-reply@their-domain.com" yet. Setting
 * a From address on a domain this platform's mail server has no SPF/DKIM
 * authorization for is a reliable way to get every email bounced or
 * spam-foldered — email providers increasingly enforce sender alignment.
 * Real domain-of-record sending needs the tenant to either verify their
 * own domain with an ESP (SES/SendGrid — NotificationGatewayConfig already
 * has a place to store those credentials per tenant, see
 * config/notification_gateways.php) and the app to actually swap mailer
 * transport per tenant at send time, or delegate SPF/DKIM to this
 * platform's sending domain. Until one of those is set up, changing only
 * the display NAME (not the address) is what's safe to do today — most
 * recipients read "Acme Travel <...>" in their inbox, not the raw address,
 * so this closes most of the real-world leak without the deliverability
 * risk.
 */
class TenantMailBranding
{
    public static function resolve(?Tenant $tenant): array
    {
        $brandName = $tenant?->legal_name ?? $tenant?->name ?? 'FyndMyTrip';

        return [
            'brand_name' => $brandName,
            // Display name only — see class docblock for why the address
            // itself still points at the platform's own authenticated
            // sending domain rather than the tenant's.
            'from_name' => $brandName,
            'from_address' => config('mail.from.address'),
            'support_email' => $tenant?->support_email ?? config('mail.from.address'),
            'support_phone' => $tenant?->support_phone,
        ];
    }

    /**
     * Convenience for the common case: resolve straight from a tenant_id
     * column. Tenant itself carries no tenant_id/global scope (it's the
     * root of the hierarchy, not a tenant-scoped model), so a plain
     * find() is enough here.
     */
    public static function resolveForTenantId(?int $tenantId): array
    {
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        return self::resolve($tenant);
    }
}
