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

## Storefront and its 54 templates

`storefront/` is a plain HTML/CSS/JS app (ES modules, no build step) for customers. It covers the whole journey for every service: search, results (sort and filters), details (seat map for buses), traveller details with guest checkout or sign-in, payment (UPI, cards, net banking, international card, or the customer's wallet) and the confirmation with ticket/voucher and invoice download.

Each template is a full design: its own colours, fonts, corner and shadow style, and its own layout for every page: header (7 layouts), home/search (10), results (8), details (5), traveller form (5), payment (6) and footer (4). Any two templates differ on at least 4 of those 7 page layouts. See them all at `/#/templates` on the storefront.

- **Which template shows:** the white-label partner's choice in the portal (Storefront settings → template, `template_id`), returned by `GET /api/whitelabel/current`. `?template=N` in the URL previews another one for that browser session.
- **Deploy:** upload `storefront/` to the partner's web root (or any static host) and set `apiBase` in `storefront/config.js`. Add the storefront's origin to the API's `CORS_ALLOWED_ORIGINS`.
- **Adding or changing templates:** edit `storefront/js/templates.js`, then run `node tools/storefront/sync-templates.mjs` to regenerate `backend/config/whitelabel_templates.php` so the portal's template picker lists the same set.

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

- **Storefront run:** for each of the 54 templates, a browser books for real through that template's pages (home search → results → details → travellers → payment → confirmation) and checks the booking is ticketed and shows in the customer's My bookings. Services, payment methods and guest vs signed-in rotate across templates, so every service and every page layout is booked through. It also checks every template at phone width for sideways scrolling.

Results and screenshots go to `e2e/results/`.
