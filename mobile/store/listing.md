# Store listing text (Google Play and App Store)

Copy these into Play Console and App Store Connect. Replace `fyndmytrip.com` with the live website if it is different.

## Names and short texts

| Field | Text | Limit |
|---|---|---|
| App name | FyndMyTrip | 30 |
| iOS subtitle | Flights, Hotels, Buses & Trips | 30 |
| Play short description | Book flights, hotels, buses, cabs and holidays. Agents book for customers too. | 80 |
| iOS promotional text | Book flights, hotels, buses, cabs, holidays and visas in one app, pay by UPI, card or wallet, and keep every ticket in My Trips. | 170 |
| iOS keywords | flights,hotels,bus,cab,holiday,package,visa,insurance,cruise,travel agent,b2b,ticket,booking | 100 |
| Category | Play: Travel & Local. App Store: Travel (secondary: Business) | |
| Support URL / email | https://fyndmytrip.com/contact-us | |
| Privacy policy URL | https://fyndmytrip.com/privacy-policy | |
| Marketing URL | https://fyndmytrip.com | |

## Full description

FyndMyTrip is one app for all your travel bookings.

BOOK EVERYTHING IN ONE PLACE
• Flights: search any airport, compare fares, refundable and non-stop filters
• Hotels, buses (pick your seat, boarding and dropping points) and cabs
• Holiday packages, cruises, activities, travel insurance and visas

SIMPLE, CLEAR PRICES
• One Base Fare plus the airline's own taxes, shown before you pay
• Prices in your currency: INR in India, USD, AED, GBP, EUR and more abroad
• Live offers and popular routes on the home screen

PAY YOUR WAY
• UPI, credit and debit cards, net banking, international cards or your FyndMyTrip wallet
• Book flights, hotels, buses and holidays as a guest, no account needed

ALL YOUR TRIPS
• Ticket, voucher and invoice PDFs, ready to save or share
• Pay for a held booking, see the refund before you cancel, refunds go to your wallet

FOR TRAVEL AGENTS AND PARTNERS
• Sign in with your partner login to see your wallet, credit, sales and commission
• Book for your customers and pay from wallet or credit
• Find any booking by ID, email or phone, and share tickets without the fare

Your account, your control: change currency, turn on two-step sign-in, or delete your account from inside the app.

## What's new (first release)

First release: book flights, hotels, buses, cabs, holidays, cruises, activities, insurance and visas, with partner login for travel agents.

## Screenshots

Phone screenshots are in `mobile/store/screenshots/` (1290 × 2796, accepted by both stores for the 6.7"/6.9" iPhone slot and Play phone screenshots). The App Store also asks for iPad screenshots because the app runs on iPad; the same files can be used for iPad 13" after you set `supportsTablet` to `false` in `app.json` if you prefer phone only.

## App access (reviewers need to sign in)

Both stores need a working login for review. Create two accounts on the **live** site and enter them under Play Console → App content → App access, and App Store Connect → App Review Information:
- a customer account (email + password)
- a partner / agent account with some wallet balance, so the reviewer can see the Partner tab

Note for reviewers: "Guests can book flights, hotels, buses and holidays without signing in. Partner sign-in is for registered travel agents (Account → Partner / Agent sign-in)."

## Content rating (Play questionnaire / App Store age rating)

- Category: Utility, Productivity, Communication or Other (Play) → "Travel"-type app, no violence, sexual content, profanity, drugs, gambling or user-generated content shared with others.
- The app lets people buy things (travel bookings) with real money: Yes.
- Expected rating: Everyone / 4+.

## Data safety (Play) and App Privacy (Apple)

The app has no ads, no analytics or tracking SDKs, and does not track people across other apps or websites (Apple: "Data Not Used to Track You").

| Data | Collected | Why | Linked to the person |
|---|---|---|---|
| Name | Yes | Account, bookings (traveller names on tickets) | Yes |
| Email address | Yes | Account, booking confirmations | Yes |
| Phone number | Yes | Booking contact | Yes |
| Other personal info: date of birth, nationality, passport number | Yes, only when a booking needs it (international flights, visas) | App functionality (bookings) | Yes |
| Purchase history | Yes | My Trips, invoices | Yes |
| Payment info (card / UPI) | Not collected by the app. Entered on Razorpay / Stripe pages; the app only gets a payment reference | | |
| Location, contacts, photos, device IDs | Not collected | | |

- Data is encrypted in transit (HTTPS): Yes.
- People can ask for their data to be deleted: Yes, in the app (Account → Delete my account) and on the website.
- Play asks for an **account deletion web link**: use a page on the website that explains how to delete the account (sign in → Account → Delete my account, or write to support from the Contact page). If the website has no such page yet, https://fyndmytrip.com/contact-us works with a line there about deletion requests.

## Export compliance (Apple)

The app uses only standard HTTPS encryption. `ITSAppUsesNonExemptEncryption` is already set to `false`, so App Store Connect will not ask.
