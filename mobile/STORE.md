# Publishing the app to Google Play and the App Store

Builds and uploads run on GitHub (**Actions → Mobile store release → Run workflow**). Nobody needs a Mac or Android Studio. The store accounts stay yours: you create the app records and an access key once, put the keys in GitHub **repository secrets**, and every release after that is one button.

Never paste keys or passwords into chat, email or a commit. Add them only under **GitHub → repo → Settings → Secrets and variables → Actions → New repository secret**. Secrets cannot be read back, only used by the workflow.

## 1. Google Play (one time)

1. **Play Console → Create app**: name *FyndMyTrip*, default language English, App, Free. Package name is set by the first upload: `in.fyndmytrip.app`.
2. **Upload key** (signs every build you send to Google). Google keeps the real app signing key (Play App Signing, on by default), so if the upload key is ever lost Google can reset it. Add these secrets:
   - `ANDROID_UPLOAD_KEYSTORE_BASE64`, `ANDROID_UPLOAD_KEYSTORE_PASSWORD`, `ANDROID_UPLOAD_KEY_ALIAS`, `ANDROID_UPLOAD_KEY_PASSWORD`
3. **Service account** so GitHub can upload:
   - Google Cloud Console → IAM → Service accounts → Create (any name, no roles) → Keys → Add key → JSON. A `.json` file downloads.
   - Play Console → **Users and permissions → Invite new users** → the service account's email → App permissions: FyndMyTrip → *Release apps to testing tracks* and *Manage testing tracks* (add *Release to production* if GitHub should also push production releases).
   - Secret `PLAY_SERVICE_ACCOUNT_JSON` = the whole JSON file content.
4. **First upload**: Google asks for the very first `.aab` to be uploaded by hand. Run the workflow once (platform *android*), download **android-store-bundle** from the run, and upload it in Play Console → Testing → Internal testing → Create release. After that the workflow uploads by itself.
5. Fill in the store listing, content rating, data safety and app access using `mobile/store/listing.md`.

## 2. Apple App Store (one time)

1. **App Store Connect → Apps → + → New App**: platform iOS, name *FyndMyTrip*, language English (U.K. or U.S.), bundle ID `in.fyndmytrip.app` (register it under Certificates, Identifiers & Profiles → Identifiers if it is not in the list), SKU `fyndmytrip-ios`.
2. **API key** so GitHub can sign and upload: App Store Connect → Users and Access → Integrations → App Store Connect API → Team Keys → **+**, access **Admin** (needed for automatic signing certificates). Download the `.p8` file (it can be downloaded only once). Add secrets:
   - `ASC_KEY_ID` (Key ID shown in the list), `ASC_ISSUER_ID` (Issuer ID above the list), `ASC_KEY_P8` (the whole text of the .p8 file, including the BEGIN/END lines)
   - `APPLE_TEAM_ID`: developer.apple.com → Account → Membership details → Team ID (10 characters)
3. Run the workflow (platform *ios*). The build shows in App Store Connect → TestFlight after Apple processes it (10 to 30 minutes). Add it to a version, fill in the listing from `mobile/store/listing.md` and submit for review.

## 3. Every release

Actions → **Mobile store release** → Run workflow:

| Field | What to enter |
|---|---|
| Which store | both / android / ios |
| Version | e.g. `1.0.0`, raise it for each public release |
| API | the **live** API, e.g. `https://api.fyndmytrip.com/api` (the demo API is for testing only) |
| Website domain | e.g. `fyndmytrip.com` |
| Website URL | e.g. `https://fyndmytrip.com` |

The build number goes up by itself (the workflow's run number). Android lands as a draft on Internal testing (promote it to Production in Play Console); iOS lands in TestFlight.

## White-label partner apps

Same workflow with the partner's app name, app ID (e.g. `com.acmetravel.app`), colour, domain and website. Publish under the partner's own store accounts (their secrets, e.g. in a copy of this repo or a GitHub environment) or under yours with their name. Each partner app needs its own app record in Play Console and App Store Connect.
