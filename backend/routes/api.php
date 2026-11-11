<?php

use App\Http\Controllers\Api\ActivityBookingController;
use App\Http\Controllers\Api\ApiPartnerCredentialController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusBookingController;
use App\Http\Controllers\Api\CabBookingController;
use App\Http\Controllers\Api\CancellationPolicyController;
use App\Http\Controllers\Api\CashbackRuleController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\GroupBookingController;
use App\Http\Controllers\Api\CruiseBookingController;
use App\Http\Controllers\Api\CurrencyRateController;
use App\Http\Controllers\Api\FlightBookingController;
use App\Http\Controllers\Api\HotelBookingController;
use App\Http\Controllers\Api\HotelGroupBookingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MyBookingsController;
use App\Http\Controllers\Api\MyPaymentsController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\RecentSearchController;
use App\Http\Controllers\Api\HolidayBookingController;
use App\Http\Controllers\Api\CatalogBookingPaymentController;
use App\Http\Controllers\Api\HolidayPackageController;
use App\Http\Controllers\Api\HolidayPackagePdfController;
use App\Http\Controllers\Api\HolidayPackageSearchController;
use App\Http\Controllers\Api\InsuranceBookingController;
use App\Http\Controllers\Api\Inventory\ActivityInventoryItemController;
use App\Http\Controllers\Api\Inventory\BusInventoryItemController;
use App\Http\Controllers\Api\Inventory\CabInventoryItemController;
use App\Http\Controllers\Api\Inventory\CruiseInventoryItemController;
use App\Http\Controllers\Api\Inventory\FlightInventoryItemController;
use App\Http\Controllers\Api\Inventory\HotelInventoryItemController;
use App\Http\Controllers\Api\Inventory\InsuranceInventoryItemController;
use App\Http\Controllers\Api\MarkupRuleController;
use App\Http\Controllers\Api\CommissionRuleController;
use App\Http\Controllers\Api\NotificationGatewayConfigController;
use App\Http\Controllers\Api\OfflinePaymentController;
use App\Http\Controllers\Api\PaymentGatewayConfigController;
use App\Http\Controllers\Api\PaymentMethodRouteController;
use App\Http\Controllers\Api\PlatformSettingsController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\SupplierConnectionController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\WhiteLabelController;
use App\Http\Controllers\Api\VisaApplicationController;
use App\Http\Controllers\Api\VisaProductController;
use App\Http\Controllers\Api\VisaProductSearchController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every route below runs behind the 'tenant' middleware (ResolveTenant),
| which binds the acting tenant into TenantContext before any controller
| or model global scope runs — see Database Design Document §3 and the
| System Architecture Document's tenant-context middleware.
|
*/

// Uptime monitors: database, cache, storage, cron and backup status.
Route::get('/health', function () {
    $report = app(\App\Domains\Settings\Services\SystemStatus::class)->report();

    return response()->json($report, $report['status'] === 'down' ? 503 : 200);
});

// Razorpay / Stripe server-to-server notifications (signature-checked).
Route::post('/payments/webhooks/{gateway}', [\App\Http\Controllers\Api\PaymentCheckoutController::class, 'webhook']);

Route::middleware('tenant')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('auth.register');
    // Website pages edited in Admin settings → Pages (privacy, terms...).
    Route::get('/pages/{slug}', [\App\Http\Controllers\Api\CmsPageController::class, 'show']);
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('auth.forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1')->name('auth.reset-password');

    // Public, guest-accessible browsing — genuinely no login required, so a
    // direct customer or an agent's own customer can look at (and an agent
    // can share a link to) a package before anyone signs up.
    Route::get('/packages', [HolidayPackageSearchController::class, 'index']);
    // Must be registered before '/packages/{slug}' — otherwise the
    // wildcard route below would greedily match "filters" as a slug.
    Route::get('/packages/filters', [HolidayPackageSearchController::class, 'filters']);
    Route::get('/packages/{slug}', [HolidayPackageSearchController::class, 'show']);
    Route::get('/packages/{slug}/itinerary.pdf', [HolidayPackagePdfController::class, 'download']);
    // "Once customers enquired email need to go with details" — a public
    // "email me this itinerary" action, separate from booking.
    Route::post('/packages/{slug}/enquire', [HolidayPackageSearchController::class, 'enquire']);
    // Guest checkout — "direct customers can book by login/signup or as a
    // guest" — see HolidayBookingController::guestStore()'s docblock.
    Route::post('/packages/{slug}/guest-bookings', [HolidayBookingController::class, 'guestStore']);
    Route::post('/packages/{slug}/quote', [HolidayBookingController::class, 'quote']);

    // Public storefront's flight search — "guest-accessible browsing,
    // login required only to complete a booking" (Platform Roadmap's B2C
    // public portal). Kept as its own public route rather than moved
    // wholesale with the rest of /flights/*: hold/confirm/pay and every
    // other module's search still require an account (see
    // FlightBookingController::search()'s docblock for how it prices a
    // guest the same as a signed-in B2C customer).
    Route::post('/flights/search', [FlightBookingController::class, 'search']);
    // Guest checkout — "from home page to till booking, only during the
    // payment page you may ask book as guest or login and book" — see
    // FlightBookingController::guestHold()'s docblock. Mirrors
    // /packages/{slug}/guest-bookings above.
    Route::post('/flights/guest-bookings', [FlightBookingController::class, 'guestHold']);

    // Public storefront's hotel search — same "guest-accessible browsing,
    // login required only to complete a booking" convention as /flights/*
    // above (see HotelBookingController::search()'s docblock).
    Route::post('/hotels/search', [HotelBookingController::class, 'search']);
    // Guest checkout — see HotelBookingController::guestHold()'s docblock.
    // Mirrors /flights/guest-bookings above.
    Route::post('/hotels/guest-bookings', [HotelBookingController::class, 'guestHold']);

    // Public storefront's bus search + inline seat map — same convention as
    // /flights/* and /hotels/* above (see BusBookingController::search()'s
    // docblock). /bus/seatmap is public too: the seat picker is shown
    // inline on the search-results card, before any login.
    Route::post('/bus/search', [BusBookingController::class, 'search']);
    Route::post('/bus/seatmap', [BusBookingController::class, 'seatMap']);
    // Guest checkout — see BusBookingController::guestHold()'s docblock.
    // Mirrors /flights/guest-bookings above.
    Route::post('/bus/guest-bookings', [BusBookingController::class, 'guestHold']);

    // Cabs/Insurance/Cruises/Activities search — made guest-accessible for
    // the same reason Flights/Hotels/Buses already are: the storefront
    // header lists all nine verticals as one menu for every visitor (see
    // frontend HomePage.tsx's docblock, "this menu... to all b2b and
    // b2c"), so a guest clicking any of them needs a working search page,
    // not a bounce to /login. Only search moved here — hold/confirm/pay
    // still require an account (guest checkout for these four hasn't been
    // built yet, same gap Flights/Hotels/Buses had until their own
    // guest-bookings routes above were added). See each controller's
    // search()/markupRoleFor() for the null-$user handling this needed.
    Route::post('/cabs/search', [CabBookingController::class, 'search']);
    Route::post('/insurance/search', [InsuranceBookingController::class, 'search']);
    Route::post('/cruises/search', [CruiseBookingController::class, 'search']);
    Route::post('/activities/search', [ActivityBookingController::class, 'search']);

    // Visa product browsing — same "guest-accessible catalog browsing"
    // convention as /packages above (see HolidayPackageSearchController's
    // scopeVisibleTo() docblock); booking a visa product still requires an
    // account, same gap as Cabs/Insurance/Cruises/Activities above.
    // Exchange rates for the storefront currency switcher — not sensitive,
    // and guests use the switcher too (it 401'd for every signed-out visitor).
    Route::get('/currency-rates', [CurrencyRateController::class, 'index']);
    Route::get('/visa', [VisaProductSearchController::class, 'index']);
    Route::get('/visa/{visaProduct}', [VisaProductSearchController::class, 'show']);

    // Public storefront homepage's "Offers & promos" section — see
    // CouponController::active()'s docblock. Registered before
    // '/coupons/validate' below only for readability; there's no wildcard
    // conflict between them.
    Route::get('/coupons/active', [CouponController::class, 'active']);
    // Storefront footer's "Subscribe for deals" box — see
    // NewsletterController::subscribe()'s docblock.
    Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe']);

    // Storefront floating chat widget's optional "smart reply" upgrade —
    // see AssistantController's docblock. Throttled separately from the
    // general API rate limit (and much tighter) since each call spends a
    // slice of the configured Gemini key's free quota; status() is cheap
    // and unthrottled so the widget can check it on every page load.
    Route::get('/assistant/status', [AssistantController::class, 'status']);
    Route::post('/assistant/chat', [AssistantController::class, 'chat'])->middleware('throttle:20,1');

    // Storefront footer's app badges + certification logos — see
    // PlatformSettingsController::publicSettings()'s docblock for why this
    // is a separate no-auth route rather than relaxing /platform-settings.
    Route::get('/platform-settings/public', [PlatformSettingsController::class, 'publicSettings']);

    // The resolved tenant's own name/logo/theme for every pre-login or
    // guest page — StorefrontHeader and the Login/Register/Forgot-/Reset-
    // Password screens — see WhiteLabelController::current()'s docblock.
    Route::get('/whitelabel/current', [WhiteLabelController::class, 'current']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile.update');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');

        // Two-factor login for the signed-in user.
        Route::get('/auth/two-factor', [\App\Http\Controllers\Api\TwoFactorController::class, 'show']);
        Route::post('/auth/two-factor/setup', [\App\Http\Controllers\Api\TwoFactorController::class, 'setup']);
        Route::post('/auth/two-factor/confirm', [\App\Http\Controllers\Api\TwoFactorController::class, 'confirm']);
        Route::post('/auth/two-factor/recovery-codes', [\App\Http\Controllers\Api\TwoFactorController::class, 'recoveryCodes']);
        Route::post('/auth/two-factor/disable', [\App\Http\Controllers\Api\TwoFactorController::class, 'disable']);

        // Real card/UPI payments (Razorpay / Stripe).
        Route::post('/payments/checkout', [\App\Http\Controllers\Api\PaymentCheckoutController::class, 'create']);
        Route::get('/payments/checkout/{transaction}/status', [\App\Http\Controllers\Api\PaymentCheckoutController::class, 'status']);

        // Admin settings (Phase 1): tax, security, monitoring, messages,
        // website pages, failed bookings, GST register, system health.
        Route::get('/admin/settings', [\App\Http\Controllers\Api\AdminSettingsController::class, 'index']);
        Route::put('/admin/settings/{group}', [\App\Http\Controllers\Api\AdminSettingsController::class, 'update']);
        Route::get('/admin/notification-templates', [\App\Http\Controllers\Api\AdminSettingsController::class, 'templates']);
        Route::put('/admin/notification-templates', [\App\Http\Controllers\Api\AdminSettingsController::class, 'saveTemplate']);
        Route::delete('/admin/notification-templates/{event}/{channel}', [\App\Http\Controllers\Api\AdminSettingsController::class, 'resetTemplate']);
        Route::post('/admin/notification-templates/test', [\App\Http\Controllers\Api\AdminSettingsController::class, 'testTemplate'])->middleware('throttle:10,1');
        Route::get('/admin/notification-logs', [\App\Http\Controllers\Api\AdminSettingsController::class, 'logs']);
        Route::get('/admin/gst-register', [\App\Http\Controllers\Api\AdminSettingsController::class, 'invoiceRegister']);
        Route::get('/admin/system-status', [\App\Http\Controllers\Api\AdminSettingsController::class, 'systemStatus']);
        Route::get('/admin/pages', [\App\Http\Controllers\Api\CmsPageController::class, 'index']);
        Route::put('/admin/pages/{slug}', [\App\Http\Controllers\Api\CmsPageController::class, 'upsert']);
        Route::delete('/admin/pages/{slug}', [\App\Http\Controllers\Api\CmsPageController::class, 'destroy']);
        Route::get('/admin/failed-bookings', [\App\Http\Controllers\Api\FailedBookingController::class, 'index']);
        Route::post('/admin/failed-bookings/{booking}/issue', [\App\Http\Controllers\Api\FailedBookingController::class, 'issue']);
        Route::post('/admin/failed-bookings/{booking}/refund', [\App\Http\Controllers\Api\FailedBookingController::class, 'refund']);

        // Phase 2: change history, roles and per-user rights.
        Route::get('/admin/audit-logs', [\App\Http\Controllers\Api\AdminSettingsController::class, 'auditLogs']);
        Route::get('/admin/roles', [\App\Http\Controllers\Api\RolesController::class, 'index']);
        Route::put('/admin/roles/{role}', [\App\Http\Controllers\Api\RolesController::class, 'update']);
        Route::post('/admin/roles/{role}/reset', [\App\Http\Controllers\Api\RolesController::class, 'reset']);
        Route::put('/tenants/{tenant}/users/{user}/rights', [TenantUserController::class, 'rights']);
        // Partner webhooks.
        Route::get('/webhooks', [\App\Http\Controllers\Api\WebhookController::class, 'index']);
        Route::post('/webhooks', [\App\Http\Controllers\Api\WebhookController::class, 'store']);
        Route::put('/webhooks/{webhook}', [\App\Http\Controllers\Api\WebhookController::class, 'update']);
        Route::delete('/webhooks/{webhook}', [\App\Http\Controllers\Api\WebhookController::class, 'destroy']);
        Route::post('/webhooks/{webhook}/test', [\App\Http\Controllers\Api\WebhookController::class, 'test'])->middleware('throttle:10,1');
        Route::get('/webhooks/{webhook}/deliveries', [\App\Http\Controllers\Api\WebhookController::class, 'deliveries']);
        Route::post('/webhook-deliveries/{delivery}/retry', [\App\Http\Controllers\Api\WebhookController::class, 'retry'])->middleware('throttle:20,1');
        // Business reports (CSV with ?format=csv).
        Route::get('/business-reports/profit', [\App\Http\Controllers\Api\BusinessReportsController::class, 'profit']);
        Route::get('/business-reports/sales', [\App\Http\Controllers\Api\BusinessReportsController::class, 'sales']);
        Route::get('/business-reports/cancellations', [\App\Http\Controllers\Api\BusinessReportsController::class, 'cancellations']);
        Route::get('/business-reports/funnel', [\App\Http\Controllers\Api\BusinessReportsController::class, 'funnel']);
        Route::get('/business-reports/gstr1', [\App\Http\Controllers\Api\BusinessReportsController::class, 'gstr1']);
        Route::get('/business-reports/tcs', [\App\Http\Controllers\Api\BusinessReportsController::class, 'tcs']);
        Route::get('/business-reports/tds', [\App\Http\Controllers\Api\BusinessReportsController::class, 'tds']);

        Route::apiResource('tenants', TenantController::class)->only(['index', 'show', 'store']);
        Route::get('/tenants/{tenant}/users', [TenantUserController::class, 'index']);
        Route::post('/tenants/{tenant}/users', [TenantUserController::class, 'store']);
        Route::put('/tenants/{tenant}/users/{user}', [TenantUserController::class, 'update']);
        Route::post('/tenants/{tenant}/users/{user}/reset-password', [TenantUserController::class, 'resetPassword']);
        Route::post('/tenants/{tenant}/users/{user}/reset-two-factor', [TenantUserController::class, 'resetTwoFactor']);

        Route::get('/platform-settings', [PlatformSettingsController::class, 'show']);
        Route::put('/platform-settings', [PlatformSettingsController::class, 'update']);

        Route::get('/whitelabel/templates', [WhiteLabelController::class, 'templates']);
        Route::get('/tenants/{tenant}/whitelabel', [WhiteLabelController::class, 'show']);
        Route::put('/tenants/{tenant}/whitelabel', [WhiteLabelController::class, 'update']);
        Route::post('/tenants/{tenant}/whitelabel/verify-dns', [WhiteLabelController::class, 'verifyDns']);

        // An API Partner tenant's own "FyndMyTrip Connect API" credentials —
        // see ApiPartnerCredentialController's docblock. Deliberately a
        // separate resource from /whitelabel above: an API Partner has no
        // storefront to theme, it has credentials to integrate with.
        Route::get('/tenants/{tenant}/api-credentials', [ApiPartnerCredentialController::class, 'index']);
        Route::post('/tenants/{tenant}/api-credentials', [ApiPartnerCredentialController::class, 'store']);
        Route::put('/tenants/{tenant}/api-credentials/{credential}', [ApiPartnerCredentialController::class, 'update']);
        Route::post('/tenants/{tenant}/api-credentials/{credential}/regenerate-secret', [ApiPartnerCredentialController::class, 'regenerateSecret']);
        Route::delete('/tenants/{tenant}/api-credentials/{credential}', [ApiPartnerCredentialController::class, 'destroy']);


        Route::get('/reports/summary', [ReportsController::class, 'summary']);
        Route::get('/reports/bookings', [ReportsController::class, 'bookings']);
        Route::get('/reports/bookings/{booking}', [ReportsController::class, 'bookingDetail']);
        Route::post('/reports/bookings/{booking}/status', [ReportsController::class, 'updateBookingStatus']);
        Route::post('/reports/bookings/{booking}/notes', [ReportsController::class, 'addBookingNote']);
        Route::get('/reports/bookings/{booking}/cancellation-quote', [ReportsController::class, 'cancellationQuote']);
        Route::post('/reports/bookings/{booking}/refunds', [ReportsController::class, 'initiateRefund']);
        Route::post('/reports/bookings/{booking}/refunds/{refund}/complete', [ReportsController::class, 'completeRefund']);
        Route::post('/reports/bookings/{booking}/refunds/{refund}/fail', [ReportsController::class, 'failRefund']);
        Route::post('/reports/bookings/{booking}/reschedule', [ReportsController::class, 'reschedule']);
        Route::post('/reports/bookings/{booking}/reissue', [ReportsController::class, 'reissue']);
        Route::get('/reports/payments', [ReportsController::class, 'payments']);
        Route::get('/reports/ledger', [ReportsController::class, 'ledger']);
        Route::get('/reports/commission-statement', [ReportsController::class, 'commissionStatement']);

        Route::get('/report-schedules', [ReportScheduleController::class, 'index']);
        Route::post('/report-schedules', [ReportScheduleController::class, 'store']);
        Route::put('/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'update']);
        Route::delete('/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'destroy']);
        Route::post('/report-schedules/{reportSchedule}/send-now', [ReportScheduleController::class, 'sendNow']);

        // "Amendment / support ticket" module — raise a correction/query
        // against a specific booking, assign it to an agent/staff, and
        // converse until it's solved. See SupportTicketController.
        Route::get('/support-tickets', [SupportTicketController::class, 'index']);
        Route::post('/support-tickets', [SupportTicketController::class, 'store']);
        Route::get('/support-tickets/assignable-users', [SupportTicketController::class, 'assignableUsers']);
        Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::patch('/support-tickets/{ticket}', [SupportTicketController::class, 'update']);
        Route::post('/support-tickets/{ticket}/replies', [SupportTicketController::class, 'reply']);

        Route::get('/wallet', [WalletController::class, 'me']);
        Route::get('/wallet/statement', [WalletController::class, 'statement']);
        Route::post('/wallet/topup', [WalletController::class, 'topup']);
        Route::get('/wallet/tenant', [WalletController::class, 'tenantWallet']);
        Route::get('/wallet/tenant/statement', [WalletController::class, 'tenantStatement']);

        // Offline payment capture — "Capture offline payment request from
        // partners, Agents, API, Whitelable ... verify and approve or
        // reject." See OfflinePaymentController/Service.
        Route::get('/offline-payments', [OfflinePaymentController::class, 'index']);
        Route::post('/offline-payments', [OfflinePaymentController::class, 'store']);
        Route::get('/offline-payments/{offlinePayment}', [OfflinePaymentController::class, 'show']);
        Route::post('/offline-payments/{offlinePayment}/approve', [OfflinePaymentController::class, 'approve']);
        Route::post('/offline-payments/{offlinePayment}/reject', [OfflinePaymentController::class, 'reject']);
        Route::get('/offline-payments/{offlinePayment}/attachments/{attachment}', [OfflinePaymentController::class, 'downloadAttachment']);

        // Credit system — "enable credit system to partners, Agents, API,
        // Whitelable, Sub Agents, Corporates ... fix the limit." See
        // CreditController/Wallet::debitFloor().
        Route::get('/credit', [CreditController::class, 'index']);
        Route::get('/credit/{targetUser}', [CreditController::class, 'show']);
        Route::patch('/credit/{targetUser}', [CreditController::class, 'update']);

        // Flight group bookings, staff-quoted flow — "These group bookings
        // only shown to b2b not to b2c users." See GroupBookingController/
        // Service/Request. (The other agreed flow, instant bulk booking, is
        // just the existing /flights/bookings hold()/confirm() path with a
        // higher passenger cap for non-customer roles — no new routes.)
        Route::get('/group-bookings', [GroupBookingController::class, 'index']);
        Route::post('/group-bookings', [GroupBookingController::class, 'store']);
        Route::get('/group-bookings/{groupBooking}', [GroupBookingController::class, 'show']);
        Route::post('/group-bookings/{groupBooking}/quote', [GroupBookingController::class, 'quote']);
        Route::post('/group-bookings/{groupBooking}/accept', [GroupBookingController::class, 'acceptQuote']);
        Route::post('/group-bookings/{groupBooking}/cancel', [GroupBookingController::class, 'cancel']);
        Route::post('/group-bookings/{groupBooking}/name-list', [GroupBookingController::class, 'submitNameList']);
        Route::post('/group-bookings/{groupBooking}/reject', [GroupBookingController::class, 'reject']);
        Route::post('/group-bookings/{groupBooking}/expire', [GroupBookingController::class, 'markExpired']);
        Route::post('/group-bookings/{groupBooking}/finalize', [GroupBookingController::class, 'finalize']);

        // Hotel group bookings, staff-quoted flow — the hotel counterpart
        // to Flight Group Bookings above, same "b2b only" rule. See
        // HotelGroupBookingController/Service/Request. (The other agreed
        // flow, instant bulk booking, is just the existing /hotels/... hold()/
        // confirm() path with a higher rooms/guests cap for non-customer
        // roles — no new routes; see HotelBookingController::MAX_ROOMS_B2B_BULK.)
        Route::get('/hotel-group-bookings', [HotelGroupBookingController::class, 'index']);
        Route::post('/hotel-group-bookings', [HotelGroupBookingController::class, 'store']);
        Route::get('/hotel-group-bookings/{hotelGroupBooking}', [HotelGroupBookingController::class, 'show']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/quote', [HotelGroupBookingController::class, 'quote']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/accept', [HotelGroupBookingController::class, 'acceptQuote']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/cancel', [HotelGroupBookingController::class, 'cancel']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/rooming-list', [HotelGroupBookingController::class, 'submitRoomingList']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/reject', [HotelGroupBookingController::class, 'reject']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/expire', [HotelGroupBookingController::class, 'markExpired']);
        Route::post('/hotel-group-bookings/{hotelGroupBooking}/finalize', [HotelGroupBookingController::class, 'finalize']);

        // "Where is the booking history then?" — every module's bookings
        // for the logged-in user, in one list (see MyBookingsController).
        Route::get('/my-bookings', [MyBookingsController::class, 'index']);
        Route::get('/my-bookings/{booking}/cancellation-quote', [\App\Http\Controllers\Api\MyBookingCancellationController::class, 'quote']);
        Route::post('/my-bookings/{booking}/cancel', [\App\Http\Controllers\Api\MyBookingCancellationController::class, 'cancel']);

        // Platform Roadmap Phase 7 — "Invoices, generated per booking, per
        // user... visible under that user's own login, not just to
        // staff." One generic endpoint for every module (see
        // InvoiceController) rather than one per booking controller.
        Route::get('/bookings/{booking}/invoice.pdf', [InvoiceController::class, 'show']);

        // "One payment history view... reused by every role" (Platform
        // Roadmap Phase 4) — see MyPaymentsController.
        Route::get('/my-payments', [MyPaymentsController::class, 'index']);

        // "Recent searches remembered per user underneath the search box"
        // (Platform Roadmap Phase 4) — every module's search page reads
        // and writes this same pair of endpoints (see RecentSearchController).
        Route::get('/recent-searches', [RecentSearchController::class, 'index']);
        Route::post('/recent-searches', [RecentSearchController::class, 'store']);

        Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);

        Route::get('/flights/bookings', [FlightBookingController::class, 'index']);
        // /flights/search itself is now registered publicly, above, outside
        // this auth:sanctum group — see that route's comment.
        Route::get('/flights/ancillary-catalog', [FlightBookingController::class, 'ancillaryCatalog']);
        Route::post('/flights/bookings', [FlightBookingController::class, 'hold']);
        Route::get('/flights/bookings/{booking}', [FlightBookingController::class, 'show']);
        Route::get('/flights/bookings/{booking}/ticket.pdf', [FlightBookingController::class, 'ticketPdf']);
        Route::get('/flights/bookings/{booking}/seatmap', [FlightBookingController::class, 'seatMap']);
        Route::post('/flights/bookings/{booking}/extras', [FlightBookingController::class, 'extras']);
        Route::post('/flights/bookings/{booking}/coupon', [FlightBookingController::class, 'applyCoupon']);
        Route::delete('/flights/bookings/{booking}/coupon', [FlightBookingController::class, 'removeCoupon']);
        Route::post('/flights/bookings/{booking}/confirm', [FlightBookingController::class, 'confirm']);
        Route::post('/flights/bookings/{booking}/pay', [FlightBookingController::class, 'payGateway']);

        Route::apiResource('cashback-rules', CashbackRuleController::class)->only(['index', 'store', 'update', 'destroy']);
        // Registered before the apiResource so 'options'/'preview' aren't
        // swallowed by the {markup_rule} wildcard.
        Route::get('/markup-rules/options', [MarkupRuleController::class, 'options']);
        Route::post('/markup-rules/preview', [MarkupRuleController::class, 'preview']);
        Route::apiResource('markup-rules', MarkupRuleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('commission-rules', CommissionRuleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('cancellation-policies', CancellationPolicyController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('payment-method-routes', PaymentMethodRouteController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('supplier-connections', SupplierConnectionController::class)->only(['index', 'store', 'update', 'destroy'])
            // The controller takes $tenantSupplierConfig, so bind {tenantSupplierConfig}; with the default {supplier_connection} edit and disconnect always got 403.
            ->parameters(['supplier-connections' => 'tenantSupplierConfig']);
        Route::apiResource('payment-gateway-configs', PaymentGatewayConfigController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('notification-gateway-configs', NotificationGatewayConfigController::class)->only(['index', 'store', 'update', 'destroy']);
        // Own inventory — dedicated model/table/controller per Product
        // Module (see task "Build the super product"), replacing the old
        // single generic 'inventory-items' endpoint.
        Route::apiResource('inventory/flights', FlightInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('inventory/hotels', HotelInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('inventory/buses', BusInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('inventory/cabs', CabInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('inventory/insurance', InsuranceInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('inventory/cruises', CruiseInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('inventory/activities', ActivityInventoryItemController::class)->only(['index', 'store', 'update', 'destroy']);

        // Manual/offline booking creation against a tenant's own inventory
        // allotment — "create the dummy/test hotels bus for booking test.
        // once API integrated then we will get real API" (Hotel/Bus have
        // no live supplier search+booking engine yet, see Task #116).
        Route::post('/inventory/hotels/{hotelInventoryItem}/bookings', [HotelBookingController::class, 'store']);
        Route::get('/hotels/bookings/{booking}/voucher.pdf', [HotelBookingController::class, 'voucherPdf']);
        Route::post('/inventory/buses/{busInventoryItem}/bookings', [BusBookingController::class, 'store']);
        Route::get('/bus/bookings/{booking}/ticket.pdf', [BusBookingController::class, 'ticketPdf']);

        // Phase 3's real Bus booking flow — hold -> confirm/pay, mirroring
        // the /flights/* endpoints above (see BusBookingController).
        // search()/seatMap() are now public — see the guest-accessible
        // /bus/search and /bus/seatmap routes above, alongside
        // /flights/search.
        Route::post('/bus/bookings', [BusBookingController::class, 'hold']);
        Route::get('/bus/bookings/{booking}', [BusBookingController::class, 'show']);
        Route::post('/bus/bookings/{booking}/confirm', [BusBookingController::class, 'confirm']);
        Route::post('/bus/bookings/{booking}/pay', [BusBookingController::class, 'payGateway']);

        // Phase 3's real Hotel booking flow — hold -> confirm/pay, mirroring
        // the /bus/* endpoints above (see HotelBookingController). search()
        // is now public — see the guest-accessible /hotels/search route
        // above, alongside /flights/search.
        Route::post('/hotels/bookings', [HotelBookingController::class, 'hold']);
        Route::get('/hotels/bookings/{booking}', [HotelBookingController::class, 'show']);
        Route::post('/hotels/bookings/{booking}/confirm', [HotelBookingController::class, 'confirm']);
        Route::post('/hotels/bookings/{booking}/pay', [HotelBookingController::class, 'payGateway']);

        // Phase 3's real Cab booking flow — hold -> confirm/pay, mirroring
        // the /bus/* and /hotels/* endpoints above (see
        // CabBookingController). No legacy store()/ticket-tool route for
        // Cab — it never had one, unlike Bus/Hotel. search() is now public
        // — see the guest-accessible /cabs/search route above, alongside
        // /flights/search.
        Route::post('/cabs/bookings', [CabBookingController::class, 'hold']);
        Route::get('/cabs/bookings/{booking}', [CabBookingController::class, 'show']);
        Route::post('/cabs/bookings/{booking}/confirm', [CabBookingController::class, 'confirm']);
        Route::post('/cabs/bookings/{booking}/pay', [CabBookingController::class, 'payGateway']);
        Route::get('/cabs/bookings/{booking}/voucher.pdf', [CabBookingController::class, 'voucherPdf']);

        // Phase 3's real Insurance booking flow — hold -> confirm/pay,
        // mirroring the /bus/*, /hotels/* and /cabs/* endpoints above (see
        // InsuranceBookingController). No legacy store()/ticket-tool route
        // for Insurance — it never had one, unlike Bus/Hotel. search() is
        // now public — see the guest-accessible /insurance/search route
        // above, alongside /flights/search.
        Route::post('/insurance/bookings', [InsuranceBookingController::class, 'hold']);
        Route::get('/insurance/bookings/{booking}', [InsuranceBookingController::class, 'show']);
        Route::post('/insurance/bookings/{booking}/confirm', [InsuranceBookingController::class, 'confirm']);
        Route::post('/insurance/bookings/{booking}/pay', [InsuranceBookingController::class, 'payGateway']);
        Route::get('/insurance/bookings/{booking}/policy.pdf', [InsuranceBookingController::class, 'policyPdf']);

        // Phase 3's fifth and final real-booking-flow vertical — Cruise —
        // hold -> confirm/pay, mirroring the /bus/*, /hotels/*, /cabs/* and
        // /insurance/* endpoints above (see CruiseBookingController). No
        // legacy store()/ticket-tool route for Cruise — it never had one,
        // unlike Bus/Hotel. search() is now public — see the
        // guest-accessible /cruises/search route above, alongside
        // /flights/search.
        Route::post('/cruises/bookings', [CruiseBookingController::class, 'hold']);
        Route::get('/cruises/bookings/{booking}', [CruiseBookingController::class, 'show']);
        Route::post('/cruises/bookings/{booking}/confirm', [CruiseBookingController::class, 'confirm']);
        Route::post('/cruises/bookings/{booking}/pay', [CruiseBookingController::class, 'payGateway']);
        Route::get('/cruises/bookings/{booking}/ticket.pdf', [CruiseBookingController::class, 'ticketPdf']);

        // Phase 3's sixth and final item — Activities — hold -> confirm/pay,
        // mirroring the /bus/*, /hotels/*, /cabs/*, /insurance/* and
        // /cruises/* endpoints above (see ActivityBookingController).
        // Activities had no admin screen or booking flow of any kind before
        // this, unlike every other module above which at least had "Own
        // inventory" management already. search() is now public — see the
        // guest-accessible /activities/search route above, alongside
        // /flights/search.
        Route::post('/activities/bookings', [ActivityBookingController::class, 'hold']);
        Route::get('/activities/bookings/{booking}', [ActivityBookingController::class, 'show']);
        Route::post('/activities/bookings/{booking}/confirm', [ActivityBookingController::class, 'confirm']);
        Route::post('/activities/bookings/{booking}/pay', [ActivityBookingController::class, 'payGateway']);
        Route::get('/activities/bookings/{booking}/voucher.pdf', [ActivityBookingController::class, 'voucherPdf']);

        // Holiday Management (DMC) — admin CRM catalog management.
        Route::apiResource('holiday-packages', HolidayPackageController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::apiResource('visa-products', VisaProductController::class)->only(['index', 'store', 'update', 'destroy']);

        // Holiday Management (DMC) — booking still requires an account
        // (browsing/search/PDF above are public — see backlog: guest checkout
        // to also allow booking without signing up first). Visa product
        // browsing (/visa, /visa/{visaProduct}) is now public too — see
        // that guest-accessible route above, alongside /packages.
        Route::post('/packages/{slug}/bookings', [HolidayBookingController::class, 'store']);
        Route::post('/holiday-bookings/{booking}/confirm', [CatalogBookingPaymentController::class, 'confirm']);
        Route::post('/holiday-bookings/{booking}/pay', [CatalogBookingPaymentController::class, 'payGateway']);
        Route::post('/visa/{visaProduct}/applications', [VisaApplicationController::class, 'store']);
        Route::post('/visa-bookings/{booking}/confirm', [CatalogBookingPaymentController::class, 'confirm']);
        Route::post('/visa-bookings/{booking}/pay', [CatalogBookingPaymentController::class, 'payGateway']);
    });
});

/*
| Partner (B2B / Connect) API — see PartnerApiController and
| docs/partner-api.md. Token exchange is open; everything else needs the
| partner bearer token and is checked by AuthenticatePartnerApi.
*/
Route::prefix('partner/v1')->group(function () {
    Route::post('/token', [\App\Http\Controllers\Api\PartnerApiController::class, 'token']);
    Route::get('/openapi.json', [\App\Http\Controllers\Api\PartnerApiController::class, 'openapi']);
    Route::get('/docs', [\App\Http\Controllers\Api\PartnerApiController::class, 'docs']);

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::middleware('partner.api')->group(function () {
            Route::get('/me', [\App\Http\Controllers\Api\PartnerApiController::class, 'me']);
            Route::get('/bookings', [\App\Http\Controllers\Api\PartnerApiController::class, 'index']);
            Route::get('/bookings/{booking}', [\App\Http\Controllers\Api\PartnerApiController::class, 'show'])->whereNumber('booking');
            Route::get('/bookings/{booking}/cancellation-quote', [\App\Http\Controllers\Api\PartnerApiController::class, 'cancellationQuote'])->whereNumber('booking');
            Route::post('/bookings/{booking}/cancel', [\App\Http\Controllers\Api\PartnerApiController::class, 'cancel'])->whereNumber('booking');
        });

        foreach (\App\Http\Controllers\Api\PartnerApiController::MODULES as $service => [$module]) {
            Route::middleware("partner.api:{$module}")->group(function () use ($service) {
                Route::post("/{$service}/search", [\App\Http\Controllers\Api\PartnerApiController::class, 'search'])->defaults('service', $service);
                Route::post("/{$service}/bookings", [\App\Http\Controllers\Api\PartnerApiController::class, 'hold'])->defaults('service', $service);
                Route::post("/{$service}/bookings/{booking}/confirm", [\App\Http\Controllers\Api\PartnerApiController::class, 'confirm'])->defaults('service', $service)->whereNumber('booking');
            });
        }
    });
});
