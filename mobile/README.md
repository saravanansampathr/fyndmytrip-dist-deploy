# FyndMyTrip mobile apps (Android and iOS)

One codebase (Expo / React Native, TypeScript) builds both the Android and the iOS app. It talks to the same API as the website (`https://api-demo.fyndmytrip.in/api` by default) and books the same way as the storefront in `storefront/`.

## What the app does

- **Book** every service: flights (airport search), hotels, buses (seat map, boarding and dropping points), cabs, holiday packages, cruises, activities, travel insurance and visas. Popular searches, holiday packages and live offers on the home screen.
- **Results** with sort (cheapest, earliest, priciest) and filters (refundable, non-stop).
- **Checkout**: traveller details, contact, then payment by UPI, credit or debit card, net banking, international card or the customer's wallet.
  - Guests book flights, hotels, buses and holidays **without an account**. A guest session only opens its own booking and never signs the guest in (same rule as the website). Cabs, cruises, activities, insurance and visas need an account.
  - Real payments use the gateway set in Admin settings: Razorpay Checkout opens inside the app; Stripe opens its hosted page and the app checks with the API when the customer comes back. With no keys set, the API's simulated payment is used, like the website.
  - Prices follow the fare rule: one **Base Fare** (our markup and our GST folded in) plus the airline's own tax lines (K3 GST, fuel surcharge, other taxes). Our fee never shows as its own line.
- **Confirmation** with booking ID, PNR / ticket numbers, ticket or voucher PDF and invoice PDF (opens the phone's share / save sheet).
- **My Trips**: upcoming and past bookings, pay for a held booking, download documents, cancel with the refund quote shown first (refund goes to the wallet).
- **Account**: sign in (with two-factor code if turned on), create account, forgot password, wallet balance, currency, help and legal pages, sign out, and **delete my account** (Apple and Google require this in-app).
- **Currency**: prices show in the currency suggested for the phone's country (INR in India, USD/AED/GBP… elsewhere) and can be changed under Account. English only.

## Settings

`app.json` → `expo.extra`:

| Key | What it is |
|---|---|
| `apiBase` | The API, e.g. `https://api.fyndmytrip.com/api` for live |
| `storefrontHost` | The website domain this app belongs to. The API uses it for brand, prices and partner (a white-label partner's own app sets its domain here) |
| `websiteUrl` | Where Terms, Privacy, Contact and Partner pages open |

They can also be set at build time with `EXPO_PUBLIC_API_BASE`, `EXPO_PUBLIC_STOREFRONT_HOST` and `EXPO_PUBLIC_WEBSITE_URL`.

App identity: name **FyndMyTrip**, Android package and iOS bundle ID **`in.fyndmytrip.app`** (change in `app.json` before the first store upload if you want another).

## Builds

**Automatic (GitHub Actions, `.github/workflows/mobile-apps.yml`).** Every change under `mobile/` type-checks the app, builds an Android APK and an iOS Simulator build. Open the run under the repo's **Actions** tab and download **fyndmytrip-android-apk** to install on any Android phone (allow "install unknown apps"). The workflow can also be run by hand with a different API address.

**Store builds (needs your developer accounts).** With an Expo account (free) and the store accounts:

```bash
cd mobile
npm ci
npx eas-cli login
npx eas-cli build --platform android --profile production   # .aab for Google Play
npx eas-cli build --platform ios --profile production       # signed .ipa for the App Store
npx eas-cli submit --platform android                        # or upload the .aab in Play Console
npx eas-cli submit --platform ios                            # to App Store Connect / TestFlight
```

- Google Play: a Play Console developer account (one-time US$25). EAS creates and keeps the upload key.
- Apple App Store: an Apple Developer Program membership (US$99 per year). EAS creates the certificates and provisioning profile when you sign in with the Apple ID.
- `eas-cli build --profile preview --platform android` gives an APK to share with testers.

## Develop and test

```bash
cd mobile
npm ci
npx expo start            # scan the QR code with the Expo Go app, or press a / i for an emulator
npm run typecheck
```

End-to-end check against a local API (same database setup as `e2e/run.sh`):

```bash
# from the repo root, after composer install in backend/
export BACKEND_DIR=$PWD/backend E2E_DB=$PWD/mobile/e2e/results/e2e.sqlite
mkdir -p mobile/e2e/results && e2e/reset_db.sh
(cd backend && CORS_ALLOWED_ORIGINS=http://127.0.0.1:8040 DB_CONNECTION=sqlite DB_DATABASE=$E2E_DB php artisan serve --port=8010) &
(cd mobile && EXPO_PUBLIC_API_BASE=http://127.0.0.1:8010/api npx expo export --platform web --output-dir dist-web)
python3 e2e/spa_server.py mobile/dist-web 8040 &
(cd mobile && node e2e/app_e2e.js)    # guest flight, guest bus with seats, cab needs account, hotel by wallet + cancel, holiday pay-later, USD, account
```

## Layout

- `app/`: screens (expo-router). `(tabs)/` = Book, My Trips, Account; then `results`, `details`, `travellers`, `payment`, `done`, `trip`, `login`.
- `src/services.ts`: every service's search fields, offer reading and hold request (a port of `storefront/js/services.js`; change both together).
- `src/api.ts`, `src/store.tsx`, `src/money.ts`, `src/fare.ts`: API client, sign-in/currency state, money formatting, fare lines.
- `src/components/`: form fields, airport picker, date picker, Razorpay sheet.

Not in this first version: push notifications, agent (B2B) screens, Google/Apple sign-in. Each can be added on top.
