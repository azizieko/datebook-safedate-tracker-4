# Native App Pairing Flow Design v0.7.1

This document defines the recommended production pairing model for Android/iOS apps using the hardened v0.6/v0.7 mobile API.

## Goals
- Do not ask users to paste long WordPress nonces into the app.
- Do not expose access tokens, refresh tokens, or signing secrets in URLs.
- Make each native device revocable by the user or an administrator.
- Bind mobile credentials to a single WordPress/DateBook user and stable device UUID.

## Recommended flow

1. User logs in on the DateBook website.
2. User opens the DateBook SafeDate mobile pairing page.
3. Server creates a short-lived one-time pairing code, ideally 6-8 digits plus a server-side random token.
4. Mobile app scans a QR code or enters the pairing code.
5. Mobile app calls a future endpoint such as:
   - `POST /wp-json/datebook-safedate/v1/mobile/pairing/claim`
6. Server validates:
   - code exists
   - code is not expired
   - code has not been used
   - device fingerprint/UUID is present
   - rate limits have not been exceeded
7. Server creates the mobile device record and returns:
   - access token
   - refresh token
   - signing secret
   - expiry timestamps
8. App stores secrets in secure storage:
   - Android: Keystore-backed EncryptedSharedPreferences
   - iOS: Keychain
9. Website shows the paired device in the user's account and admin Mobile API dashboard.
10. User/admin can revoke the device at any time.

## Pairing-code security rules
- Expiry: 5-10 minutes.
- One-time use only.
- Hash pairing codes at rest.
- Limit attempts per IP, user, and device UUID.
- Log all failed claims in `dbsd_mobile_security_events`.
- Never place access/refresh tokens in QR code payloads.

## Production endpoints suggested for v0.8
- `POST /mobile/pairing/create` authenticated web user only.
- `POST /mobile/pairing/claim` public, IP-throttled, consumes code.
- `GET /mobile/devices` authenticated user view of own devices.
- `POST /mobile/devices/{id}/revoke` authenticated user revocation.
- `POST /admin/mobile/revoke-device` administrator revocation, added in v0.7.1.

## Client-side notes
- Android and iOS starters currently demonstrate API signing and token use.
- Replace manual credentials in starter kits with this pairing flow before production release.
- Disable debug logging for request bodies, headers, tokens, and signatures in release builds.
