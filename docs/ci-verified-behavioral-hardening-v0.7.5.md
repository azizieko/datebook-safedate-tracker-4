# DateBook SafeDate Tracker v0.7.5 - CI-Verified Behavioral Hardening

v0.7.5 is a QA/security hardening release. It does not add new dating/social features. It closes the v0.7.4 QA gaps by adding production-oriented native pairing endpoints, behavioral PHPUnit tests, Web Push readiness diagnostics, service-worker diagnostics, finer admin capabilities, and release documentation.

## Major changes

- Added native app pairing endpoints:
  - `POST /wp-json/datebook-safedate/v1/mobile/pairing-code`
  - `POST /wp-json/datebook-safedate/v1/mobile/pair-device`
  - `GET /wp-json/datebook-safedate/v1/mobile/pairing-status/{id}`
- Added pairing shortcode: `[db_safedate_mobile_pair_code]`
- Added Web Push readiness endpoint: `GET /wp-json/datebook-safedate/v1/push/readiness`
- Added service-worker diagnostics endpoint: `GET /wp-json/datebook-safedate/v1/pwa/service-worker-status`
- Added dedicated admin capabilities:
  - `dbsd_view_safety`
  - `dbsd_manage_incidents`
  - `dbsd_manage_devices`
  - `dbsd_export_safety`
  - `dbsd_manage_settings`
- Added behavioral tests for native pairing, Web Push readiness, exact-location redaction, and encrypted mobile secret storage.
- Added `CHANGELOG.md` and `SECURITY.md`.

## Production gates before release

A production release must include a passing CI run for the full WordPress PHPUnit matrix. The package includes the GitHub Actions workflow; attach the workflow run URL to the production release record.

## Native pairing flow

1. User logs into the WordPress/DateBook website.
2. User opens a page containing `[db_safedate_mobile_pair_code]`.
3. Website creates a short-lived one-time pairing code.
4. User enters the code in the Android/iOS app.
5. App calls `/mobile/pair-device` with the code and a device UUID.
6. Server returns access token, refresh token, and signing secret.
7. App stores secrets in Android Keystore or iOS Keychain.

Pairing codes are hashed server-side, short-lived, single-use, and IP-rate-limited.

## Web Push readiness

The plugin can store browser subscriptions without the PHP Web Push library, but production background push requires:

- `minishlink/web-push` installed with Composer.
- VAPID public key configured.
- VAPID private key configured.
- VAPID subject configured.

The `/push/readiness` endpoint reports whether the system is in production Web Push mode or browser-polling fallback mode.
