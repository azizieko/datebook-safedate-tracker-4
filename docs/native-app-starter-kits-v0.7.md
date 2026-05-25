# DateBook SafeDate Tracker v0.7 - Native App Starter Kits

v0.7 adds minimal native app starter kits that consume the hardened v0.6 mobile API.

## Included projects

```text
native/android/SafeDateStarter/
native/ios/SafeDateStarter/
native/shared/
```

## Android starter

The Android starter is a dependency-free Kotlin sample using `HttpURLConnection` and `javax.crypto.Mac` to send signed requests.

It includes:

- API base input
- session ID input
- mobile token fields
- HMAC-SHA256 signing helper
- signed location ping example
- signed device-health example
- required manifest permissions

Before production:

- Replace manual token fields with WordPress/DateBook login and secure device pairing.
- Store tokens in EncryptedSharedPreferences or Android Keystore-backed storage.
- Replace demo coordinates with Fused Location Provider or Android location APIs.
- Add foreground service and visible notification if using background location.
- Add notification runtime permission request for Android 13+.

## iOS starter

The iOS starter is a SwiftUI sample using `URLSession`, `CryptoKit`, and manual credential entry.

It includes:

- API base input
- session ID input
- mobile token fields
- HMAC-SHA256 signing helper
- signed location ping example
- signed device-health example
- location usage description

Before production:

- Replace manual token fields with WordPress/DateBook login and secure device pairing.
- Store tokens in Keychain.
- Replace demo coordinates with CoreLocation.
- Add background location mode only after legal review and explicit user consent.
- Add APNs push notification registration if required.

## WordPress additions in v0.7

- Plugin version updated to 0.7.0.
- `DBSD_V070` integration class added.
- New REST discovery endpoint: `GET /mobile/app-config`.
- New admin page: SafeDate > Native Apps v0.7.
- New shortcode: `[db_safedate_native_apps]`.

## Security reminder

v0.7 is a starter-kit release. It does not remove the need for mobile security review, privacy review, penetration testing, app-store disclosure wording, or legal review for location safety features.
