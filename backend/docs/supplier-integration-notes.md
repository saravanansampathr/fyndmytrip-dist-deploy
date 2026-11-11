# Supplier API research notes — TBO, TripJack, Akbar Travels

Written while researching the three launch suppliers named throughout the spec suite, in
response to the request to pull each supplier's **test API** from the web so it could be
tried before real production keys are exchanged. The headline finding, common to all
three: **none of them offer a public, self-service sandbox you can just start calling.**
Every one of them gates even "test" credentials behind some form of registration or
partner onboarding. That's normal for B2B travel-API providers (it stops random signups
scraping live fare data), but it does mean the "test it first, then get a real key" plan
needs a small adjustment: registration comes first, for all three, even for testing.

Below is what was actually found for each, and what code exists today as a result.

## 1. TBO (Travel Boutique Online / Tek Travels) — best documented of the three

TBO publishes real, detailed, publicly-browsable API documentation at
`apidoc.tektravels.com/flight/`. No login was needed to read it. It fully describes the
Flight API contract:

- **Authenticate** — exchanges `ClientId` + `UserName` + `Password` + `EndUserIp` for a
  `TokenId`. No documented expiry was stated, so the adapter caches it for 10 minutes as a
  conservative default.
- **Search** — takes `TokenId`, passenger counts, cabin class, and one-or-two `Segments`
  (one-way vs round-trip); returns a `TraceId` (valid ~15 minutes) plus a list of results,
  each with a `ResultIndex`.
- **FareQuote** — re-confirms a specific `TraceId` + `ResultIndex` is still bookable at
  that price immediately before booking.
- **Book** — takes full passenger details (title, name, DOB, nationality, contact, email)
  and returns a `PNR` + `BookingId`.
- **Ticket** — issues the actual ticket against that `PNR`/`BookingId`, returns ticket
  numbers per passenger.
- **GetBookingDetails** — retrieves a booking by `PNR`/`BookingId` (used here for both
  "check refund status" and "read back a confirmed itinerary").
- **SendChangeRequest** — cancellation / amendment request against a `BookingId`.

**No self-service sandbox exists.** TBO's own documentation and partner-facing pages say
a `UserName`/`Password` pair — described as "test environment credentials" — is issued
only after you register as a travel agency and are onboarded by TBO's API integration
team, contactable at **apiintegrationteam@tbo.com**. There is no anonymous "try it now"
key.

**What's built:** `backend/app/Domains/Suppliers/Adapters/TBOFlightSupplierAdapter.php` —
a complete, real (non-mock) implementation of the project's `SupplierAdapterInterface`,
covering the full Authenticate → Search → FareQuote → Book → Ticket → GetBookingDetails →
SendChangeRequest sequence above, using Laravel's `Http` facade and `Cache::remember()`
for the token. It's wired into `config/suppliers.php` (`'TBO' => TBOFlightSupplierAdapter::class`,
plus a `tbo` config block reading `TBO_API_BASE_URL` / `TBO_CLIENT_ID` / `TBO_USERNAME` /
`TBO_PASSWORD` from `.env`) and passes `php -l`. **It has not been exercised against a
live TBO endpoint** — that's not possible without real credentials, and this sandbox
can't reach Packagist to install Laravel anyway (see main README). Two things to note
once real credentials arrive and this gets its first live run:

1. TBO's `Book` call wants `Nationality`/`ContactNo`/`Email`/`Title` per passenger, which
   `booking_passengers` doesn't fully capture today — the adapter fills sane defaults
   (`Nationality: IN`, placeholder contact/email) that should be replaced with real
   captured values before going live.
2. `TraceId` + `ResultIndex` (from Search) and `PNR` + `BookingId` (from Book) are packed
   into the interface's single `$externalId` / `$supplierBookingRef` strings as
   `"{TraceId}|{ResultIndex}"` and `"{PNR}:{BookingId}"` — an internal detail of this
   adapter only, the shared interface and every other adapter are untouched.

**Next step to actually test this:** email apiintegrationteam@tbo.com (or register via
whatever agency-signup path TBO points you to) asking specifically for **UAT/test
environment credentials**, not production ones. Once you have a `UserName`/`Password`,
drop them into `.env` and this adapter is ready to run.

## 2. TripJack — endpoint contract confirmed directly, real test host found

Update: once Chrome was connected in this session, TripJack's Postman workspace
(`postman.com/grey-space-945303/tripjack`) — previously unreadable because it's
JavaScript-rendered — could be browsed directly. That's a materially better source than
the first pass (which only had indirect corroboration via an unrelated GitHub PR), and it
turned up a genuinely important finding:

**TripJack has a real, dedicated test host: `https://apitest.tripjack.com`.** This is
distinct from their production host and was seen directly on live, populated request
definitions — it directly answers "does TripJack have a test API?" with "yes." The
catch: reaching it still requires an `apikey` header value, and that key is only issued
after TripJack's partner team onboards you — there's no anonymous self-serve signup that
hands one out.

Confirmed directly (read off real request definitions, not guessed):

- `POST /fms/v1/air-search-all` — search
- `POST /booking/review-price` — re-confirms a `priceId` from search and turns it into a
  `bookingId` (TripJack's term for a held-but-not-yet-ticketed booking)
- `POST /booking/fare-rule` — fare rules for a `bookingId`
- `POST /booking/seat-map` — seat map for a `bookingId`
- `POST /oms/v1/air/book` — books **and** tickets a `bookingId` in one call (traveller
  info + optional GST/delivery info) — TripJack doesn't split "book" and "ticket" into
  two calls the way TBO does
- `POST /oms/v1/booking-details` — retrieve a booking by `bookingId`
- Auth: a single `apikey` request header — no token-exchange step like TBO's Authenticate
- Error envelope: `{"status":{"success":false,"httpStatus":...},"errors":[{"errCode",
  "message","details"}]}` — confirmed from a real captured error response
  ("Insufficient Balance"), which also proves the workspace author was hitting a live
  backend, not a mock.

Not confirmed — no successful (non-error) response body was available to read, so the
adapter's response-parsing field names (`searchResult.tripInfos.ONWARD[]`, `totalPriceList`,
etc.) are inferred from TripJack's generally-documented shape and should be checked
against the first real response once credentials exist. Two gaps flagged honestly rather
than guessed: the "Get Balance" endpoint's URL was left empty in the source workspace, and
no verified cancellation endpoint was found for the public Flight API (an "amendment" flow
exists, but it lives under what looks like the workspace author's own reseller admin
backend, not a confirmed TripJack endpoint).

**One more thing worth flagging:** the browsed workspace had a real, populated `apikey`
value on several requests — evidently the workspace author's own TripJack test
credential, left in a collection they chose to publish. That value was deliberately not
copied into this codebase and no live call was made with it — it's not this project's
credential to use, sandbox key or not. Worth knowing it's out there if you look at that
workspace yourself.

**What's built:** `backend/app/Domains/Suppliers/Adapters/TripJackFlightSupplierAdapter.php`
— a real (non-mock) adapter covering search → review-price → book → booking-details,
wired into config (`'TRIPJACK' => TripJackFlightSupplierAdapter::class`, reading
`TRIPJACK_API_BASE_URL` / `TRIPJACK_API_KEY`), passes `php -l`. `cancelBooking()` and
`getSupplierAccountBalance()` deliberately throw a clear exception rather than guess at
unconfirmed endpoints. Like the TBO adapter, this hasn't been exercised against a live
call — that needs a real `apikey` from TripJack first.

**Next step:** use TripJack's contact form to request partner/API onboarding, ask
specifically for **test/UAT access to apitest.tripjack.com**, and once you have a key,
plug it into `.env` — this adapter is ready to run against it.

## 3. Akbar Travels — no technical documentation found at all

Searching turned up nothing beyond a B2B lead-capture signup page at
`agents.akbartravelsonline.com/b2bplus/apisign-up`, which asks for name/email/mobile and
implies a manual sales/onboarding process ("go live within just 30 days"). No endpoint
list, no auth scheme, no request/response samples — nothing a developer could build
against — is publicly published anywhere that was found.

**Not built:** there's nothing to build from. An adapter class would be pure guesswork.

**Next step:** submit that signup form and expect a human-driven onboarding conversation
rather than a downloadable spec — Akbar looks like the least self-service of the three.

## Bottom line

| Supplier | Public docs | Dedicated test host? | Self-serve test key? | Adapter code |
|---|---|---|---|---|
| TBO | Yes, detailed | Not documented separately from production | No — email apiintegrationteam@tbo.com | Built, unverified (needs real creds) |
| TripJack | Yes, confirmed directly | Yes — `apitest.tripjack.com` | No — partner onboarding issues the `apikey` | Built, unverified (needs real creds) |
| Akbar Travels | None | Unknown | No — lead-gen form only | Not built (no spec exists) |

None of the three can be "tested" today without first registering with that supplier —
but TripJack is the closest to good news: they do run a real, separate test environment,
so once you have an `apikey` for it, testing is just pointing `.env` at it. The realistic
path is: send the three registration/contact requests now (TBO's is the most concrete — a
named email address; TripJack's is their contact form, asking specifically for
`apitest.tripjack.com` access), and as credentials come back one at a time, plug them into
`.env` — both the TBO and TripJack adapters are ready to go live-tested immediately.
Akbar Travels would need a fresh adapter written once (if ever) a real technical contract
surfaces from their onboarding process.
