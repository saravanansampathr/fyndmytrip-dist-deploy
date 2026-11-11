# FyndMyTrip — deployable product

| Folder | What it is |
|---|---|
| `backend/` | Laravel 11 API (PHP 8.2+): tenants (Master → White-label → Agent → Sub-agent, API partners), users and roles, flights, hotels, buses, cabs, holidays, cruises, activities, insurance, visa, wallet and credit, payments, coupons, cashback, commission, margin (markup) rules, refunds, reports, invoices. |
| `frontend/` | Compiled portal + storefront (Vite/React build 106) with logos, favicon, icons, payment badges and airport/country data. Calls `https://api-demo.fyndmytrip.in/api`. |
| `storefront/` | Customer booking storefront with **54 complete design templates** (source included, no build step): home and search, results, details, traveller details, payment and confirmation for flights, hotels, buses, cabs, holidays, cruises, activities, insurance and visas. |
| `tools/frontend-margin-patch/` | How the Margin rules page in `frontend/` was produced (the React source is not in this repo, only the build). |
| `e2e/` | End-to-end checks: backend tests, an API run and a browser run. |

The older files at the repo root (`index_1..4.html`, `index-*.js/css`, `fyndmytrip-backend-deploy-full.tar.gz`) are earlier partial uploads, superseded by `backend/` and `frontend/`.

## Deploy

Backend (cPanel or any PHP host, document root = `backend/public`):

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env        # fill in DB, APP_URL, payment/supplier keys
php artisan key:generate    # first install only
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force   # every deploy: syncs role permissions
php artisan config:cache && php artisan route:cache
```

Frontend: upload the contents of `frontend/` to the web root with an SPA fallback to `index.html` (every unknown path serves `index.html`).

**Cron (required).** Add one cPanel cron job that runs every minute:

```
* * * * * cd /home/<user>/<app>/backend && php artisan schedule:run >> /dev/null 2>&1
```

It reconciles gateway payments every 10 minutes, and makes the daily backup at 01:30 (database + uploads, in `storage/app/backups`). **Admin settings → Security & health** shows whether the cron job and the backup are running; `GET /api/health` is the same check for an uptime monitor.

## Admin settings (no code changes needed)

**Admin settings** in the portal (super admin and platform staff; white-label admins see Messages and Website pages for their own site):

- **Tax & invoices:** home state, GST % and SAC code per service, invoice and credit-note prefixes (numbers run per financial year, e.g. `FMT2627-000001`), and TCS on overseas holiday packages (threshold and rates; no PAN means the higher rate). A credit note is issued automatically when a booking is refunded.
- **Messages:** the email, SMS and WhatsApp text for booking confirmed, cancelled, refunded, failed, and the staff alert, with `{{placeholders}}`, a DLT / WhatsApp template ID, *Send a sample*, and a log of what was sent. Providers are set in **Payment & notification gateways** (email: SMTP, SendGrid, Amazon SES; SMS: MSG91, Twilio; WhatsApp: Gupshup, Twilio, Interakt).
- **Website pages:** privacy policy, terms, refund and cancellation policy, disclaimer, about us, and any extra page. The starter text is a draft for your lawyer; until a page is published the website keeps its built-in page.
- **Failed bookings:** paid bookings the supplier refused, paid bookings not ticketed after 30 minutes, and gateway payments where the customer never came back. Issue by hand (enter the PNR / ticket numbers) or refund in one click.
- **GST register:** every invoice and credit note for a financial year, with CSV download for your CA.
- **Security & health:** wrong-password lock-out, how long staff and customers stay signed in, which roles must turn on two-factor login, alert emails, backup retention, and system health.

Every user can turn on two-factor login (any authenticator app) under **Login security**.

## Card, UPI and net-banking payments

Add the Razorpay and/or Stripe keys in **Payment & notification gateways** and route methods to them in **Payment gateway routing**. Test keys (`rzp_test_…`, `sk_test_…`) take test payments only. With keys in place, every *Pay* button opens the real Razorpay checkout or a Stripe Checkout window, and the booking is confirmed only after the server has checked the payment with the gateway; without keys the old simulated payment is used. Refunds of gateway payments go back to the card automatically.

Set these webhook URLs in the gateway dashboards, with the webhook secret saved in the same settings:

- Razorpay: `https://api-demo.fyndmytrip.in/api/payments/webhooks/razorpay` (event `payment.captured`)
- Stripe: `https://api-demo.fyndmytrip.in/api/payments/webhooks/stripe` (event `checkout.session.completed`)

## Storefront and its 54 templates

`storefront/` is a plain HTML/CSS/JS app (ES modules, no build step) for customers. It covers the whole journey for every service: search, results (sort and filters), details (seat map for buses), traveller details with guest checkout or sign-in, payment (UPI, cards, net banking, international card, or the customer's wallet) and the confirmation with ticket/voucher and invoice download.

Each template is a full design: its own colours, fonts, corner and shadow style, and its own layout for every page: header (7 layouts), home/search (10), results (8), details (5), traveller form (5), payment (6) and footer (4). Any two templates differ on at least 4 of those 7 page layouts. See them all at `/#/templates` on the storefront.

- **Which template shows:** the white-label partner's choice in the portal (Storefront settings → template, `template_id`), returned by `GET /api/whitelabel/current`. `?template=N` in the URL previews another one for that browser session.
- **Deploy:** upload `storefront/` to the partner's web root (or any static host) and set `apiBase` in `storefront/config.js`. Add the storefront's origin to the API's `CORS_ALLOWED_ORIGINS`.
- **Adding or changing templates:** edit `storefront/js/templates.js`, then run `node tools/storefront/sync-templates.mjs` to regenerate `backend/config/whitelabel_templates.php` so the portal's template picker lists the same set.

## Paying for packages and visas

Holiday packages (`POST /packages/{slug}/bookings`) and visa applications (`POST /visa/{id}/applications`) create a held booking that the traveller pays for with `POST /holiday-bookings/{id}/confirm` or `/visa-bookings/{id}/confirm` (wallet amount) and, for any remainder, `/pay` (gateway method). Paid bookings become *confirmed* and get an invoice; the ops team then issues vouchers / processes the visa. `VisaSampleProductSeeder` adds six sample visa products.

## Partner API (B2B / Connect)

API partners book through `/api/partner/v1` with the credentials issued under **Tenants → API credentials** (client ID and secret, an allowed domain, the services it may use, and a per-minute rate limit).

1. `POST /api/partner/v1/token` with `client_id` and `client_secret` returns a bearer token, valid for one hour.
2. Search with `POST /{service}/search`, hold with `POST /{service}/bookings`, then pay from the partner's prepaid wallet with `POST /{service}/bookings/{id}/confirm`. Services are `flights`, `hotels`, `bus`, `cabs`, `insurance`, `cruises` and `activities`.
3. Read bookings with `GET /bookings` and `GET /bookings/{id}`, and cancel with `GET /bookings/{id}/cancellation-quote` and `POST /bookings/{id}/cancel`.

The calls act as the partner account's API user, so its margin rules, wallet and reports apply. Developer docs are at `/api/partner/v1/docs`, with OpenAPI at `/api/partner/v1/openapi.json`.

## GST invoices

Invoices read GSTINs from each account's **Business registration / GSTIN** field. For the seller, that's the booking's account, falling back to the platform (Master) account.

Each invoice shows the GSTINs, the place of supply and the SAC code:

| SAC | Bookings |
|---|---|
| 998551 | Transport |
| 998552 | Hotels |
| 998555 | Holiday packages |
| 998559 | Everything else |

The GST on the service fee (18% unless changed in Admin settings) is split into CGST + SGST when the seller and buyer are in the same state, and charged as IGST when they're in different states. International flights require each traveller's passport number, date of birth, and a passport expiry after the travel date.

## Margin (markup) rules

Managed at **Margin rules** in the portal, or `/api/markup-rules`.

- **Who it applies to:** Everyone, Admins (super admin + platform staff), Staff users, White-label partners, API partners, Agents, Sub-agents, Direct customers.
- **Which service:** all services, or one of flights, hotels, buses, cabs, holidays, insurance, visa, cruises, activities.
- **Optionally only for** one chosen account and/or one chosen user (within your own hierarchy).
- **Type:** % of net fare, flat per booking (PNR), flat per passenger, flat per destination/route; optional domestic/international and supplier scope.
- **Which rule wins:** the nearest account up the chain that has a matching rule; inside it the most specific rule (user → account → service → user type → supplier → scope). Rules from levels above are a ceiling that can't be exceeded.
- **Who can edit:** super admin, platform staff, white-label admins, agents, API users (`markup.manage`). Sub-agents can only view (`markup.view`). Direct customers have no access.
- **Check a margin** (`POST /api/markup-rules/preview`) shows the margin and sell price a chosen user or account would get.

## Testing

```bash
cd backend && composer install && cd ..
e2e/run.sh
```

Runs the backend test suite, then on a throwaway SQLite database seeded with the demo hierarchy (all passwords `password`):

- **API run:** each of 9 user types (super admin, staff, direct customer, white-label admin, agent, sub-agent, direct agent, API partner, and a staff user created by the white-label admin) searches, holds and pays for a flight, hotel, bus, cab, cruise, activity and insurance booking. The margin charged is checked against the rules. It also covers holiday packages, the white-label storefront, visa, reports, invoices, refunds and permission boundaries.
- **Browser run:** Playwright drives `frontend/` against the local API. It covers creating, editing, previewing and filtering margin rules, read-only and hidden views per role, and 20 pages × 5 roles with no JS errors.
- **Website bookings:** in the real website, every service (flights, hotels with 2 rooms, buses with 2 seats, cabs, cruises, activities, insurance, holiday packages, visa) from the search page through results, traveller details, review & pay and the confirmation, as customer, guest and every partner/admin role. It checks the amount paid equals the price the results page quoted, and covers coupons, add-ons, wallet / wallet + gateway / gateway payment, invoices in My Trips and an admin cancel + wallet refund.

- **Storefront run:** for each of the 54 templates, a browser books for real through that template's pages (home search → results → details → travellers → payment → confirmation) and checks the booking is ticketed and shows in the customer's My bookings. Services, payment methods and guest vs signed-in rotate across templates, so every service and every page layout is booked through. It also checks every template at phone width for sideways scrolling.

Results and screenshots go to `e2e/results/`.
