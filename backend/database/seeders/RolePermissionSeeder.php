<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds Database Design Document §3.3's flat RBAC map. super_admin is
 * handled specially (implicit full access) in App\Domains\Auth\Concerns\
 * HasPermissions and does not need rows here.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // pricing.view_margin ("Margin need to show to all even i am
        // keeping markup to super admin") deliberately has NO grants below —
        // super_admin already holds it implicitly (HasPermissions), and that
        // matches today's intent. Add it to a role's array here (staff,
        // partner_admin, ...) whenever margin visibility should widen beyond
        // super admin; FlightBookingController::search()'s can_view_margin
        // flag and the net_amount/markup_amount it withholds both key off
        // this permission alone, so nothing else needs to change.
        $grants = [
            User::ROLE_STAFF => [
                'tenant.create', 'tenant.view', 'tenant.manage_users',
                'booking.view', 'booking.cancel', 'booking.refund', 'booking.manage',
                'ticket.view', 'ticket.create', 'ticket.manage',
                'wallet.view', 'supplier.configure',
                'payment_gateway.configure', 'notification_gateway.configure',
                // Cancellation/refund policy (Phase 2) is money-sensitive
                // enough that it's staff/partner-admin managed rather than
                // tenant self-service like markup/cashback rules.
                'policy.manage',
                // Credit-limit management stays staff-only — credit changes
                // are the platform extending its own money to a partner,
                // not something partner_admin self-serves. Offline payment
                // review is ALSO granted to partner_admin and agent below,
                // each scoped to their own hierarchy only (see
                // OfflinePaymentController::resolveTenantIds()); staff keep
                // it too as a fallback that reaches every hierarchy.
                'payments.offline.review', 'wallet.credit.manage',
                // Group booking quoting/finalizing is a real airline-side
                // fare negotiation the platform itself does — staff-only,
                // unlike offline payment review, which is fine for a
                // reseller to self-serve within their own hierarchy. See
                // GroupBookingController/Service.
                'group_booking.manage',
                // Same posture for hotel group bookings — a negotiated
                // block rate is a real hotel-side rate negotiation the
                // platform does on the requester's behalf. See
                // HotelGroupBookingController/Service.
                'hotel_group_booking.manage',
            ],
            User::ROLE_PARTNER_ADMIN => [
                'tenant.create', 'tenant.view', 'tenant.manage_users',
                'booking.view', 'booking.create', 'booking.cancel', 'booking.refund', 'booking.manage',
                'ticket.view', 'ticket.create', 'ticket.manage',
                'wallet.view', 'wallet.topup',
                'coupon.manage', 'supplier.configure',
                'payment_gateway.configure', 'notification_gateway.configure',
                'policy.manage',
                'payments.offline.submit',
                // A White-Label's own Agents/Sub-Agents sit under ITS
                // tenant hierarchy, so it may verify and approve/reject
                // their offline payment claims itself without escalating
                // to platform staff every time — OfflinePaymentController
                // still scopes this to the partner_admin's own descendant
                // tenants only (resolveTenantIds()/assertWithinReviewScope()),
                // never another Whitelabel's.
                'payments.offline.review',
                // "These group bookings only shown to b2b not to b2c
                // users" — every B2B-capable role gets group_booking.request
                // (submit/accept-quote/cancel/submit-name-list on their OWN
                // requests only — see GroupBookingController::resolveOwnRequest()),
                // same role list as payments.offline.submit, minus customer.
                'group_booking.request',
                // Same rule for the hotel group booking counterpart.
                'hotel_group_booking.request',
            ],
            // tenant.create deliberately NOT granted here — "Only
            // Whitelabel users [partner_admin] can have this privilege,
            // Agent can create sub users to book" (the backlog gap this
            // seeder now closes): an Agent may still add more LOGINS under
            // its own existing tenant (tenant.manage_users, unchanged
            // below), it just can no longer spin up a new Sub-Agent
            // tenant. Only a White-Label Partner or platform staff can.
            User::ROLE_AGENT => [
                'tenant.view', 'tenant.manage_users',
                'booking.view', 'booking.create', 'booking.cancel',
                'ticket.view', 'ticket.create',
                'wallet.view', 'wallet.topup',
                'payments.offline.submit',
                // A Sub-Agent is just another LOGIN under the Agent's own
                // tenant (see the tenant.create note above — a Sub-Agent
                // never gets its own tenant), so an Agent reviewing their
                // claims is same-tenant, not cross-tenant: OfflinePaymentController's
                // existing hierarchy scoping (resolveTenantIds()) already
                // covers it correctly with no further change needed there.
                'payments.offline.review',
                'group_booking.request',
                'hotel_group_booking.request',
            ],
            User::ROLE_SUB_AGENT => [
                'booking.view', 'booking.create',
                'ticket.view', 'ticket.create',
                'wallet.view', 'wallet.topup',
                'payments.offline.submit',
                'group_booking.request',
                'hotel_group_booking.request',
            ],
            User::ROLE_STAFF_USER => [
                'booking.view', 'booking.create',
                'ticket.view', 'ticket.create',
                // A white-label/agent tenant's own staff login also pays
                // into that tenant's accounts, so it can submit offline
                // payment claims too — it just has no wallet.topup (online
                // top-up) grant, mirroring what it already lacked.
                'payments.offline.submit',
                'group_booking.request',
                'hotel_group_booking.request',
            ],
            User::ROLE_API_USER => [
                'booking.view', 'booking.create',
                // API partners are explicitly named among who submits
                // offline payment claims ("partners, Agents, API,
                // Whitelable"), even though they otherwise have no other
                // wallet self-service permissions.
                'payments.offline.submit',
                'group_booking.request',
                'hotel_group_booking.request',
            ],
            User::ROLE_CUSTOMER => [
                'booking.view', 'booking.create',
                'ticket.view', 'ticket.create',
                'wallet.view', 'wallet.topup',
            ],
        ];

        foreach ($grants as $role => $permissions) {
            foreach ($permissions as $permission) {
                RolePermission::query()->firstOrCreate([
                    'role_key' => $role,
                    'permission_key' => $permission,
                ]);
            }

            // Reconcile rather than purely append — re-running this seeder
            // (as every deploy does) must also REVOKE a permission that's
            // been removed from the map above (e.g. this very change,
            // taking tenant.create away from Agent), not just add new ones.
            RolePermission::query()
                ->where('role_key', $role)
                ->whereNotIn('permission_key', $permissions)
                ->delete();
        }
    }
}
