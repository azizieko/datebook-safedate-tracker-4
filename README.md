# DateBook SafeDate Tracker v0.7.10

Release-candidate hardening package for staging/CI validation. Production promotion requires a passing external CI matrix.

# DateBook SafeDate Tracker

Version: 0.7.10

A consent-based safety and audit plugin for DateBook/WordPress dating websites.

## Core features through v0.4

- SafeDate session creation
- Two-party consent
- Traveler journey tracking
- Arrival and departure confirmation workflow
- Tamper-evident audit log
- SOS alerts
- Emergency contacts
- Trusted-contact share links
- Incident reports
- JSON audit exports
- PWA manifest and service worker
- Browser/Web Push subscription storage
- Live admin monitor

## New in v0.7.0

- Enhanced location pings with arrival geofence checks
- Automatic arrival prompt when traveler enters the meeting geofence
- Device health telemetry: online/offline, battery, charging, geolocation permission, network type
- Check-in request and response workflow
- Overdue check-in escalation via WP-Cron
- Post-departure safety check creation
- Stale-device/location escalation
- Admin Operations v0.5 monitor
- Admin action logging
- User privacy request workflow
- User SafeDate data summary endpoint
- New safety center shortcode
- New privacy tools shortcode

## Shortcodes

```text
[db_safedate_create]
[db_safedate_dashboard]
[db_safedate_session id="123"]
[db_safedate_contacts]
[db_safedate_notifications]
[db_safedate_trusted_share id="123"]
[db_safedate_incident_report id="123"]
[db_safedate_map id="123"]
[db_safedate_pwa_install]
[db_safedate_safety_center id="123"]
[db_safedate_privacy_tools]
```

## v0.5 REST endpoints

```text
POST /wp-json/datebook-safedate/v1/location/ping-enhanced
POST /wp-json/datebook-safedate/v1/device/health
POST /wp-json/datebook-safedate/v1/checkin/request
POST /wp-json/datebook-safedate/v1/checkin/respond
GET  /wp-json/datebook-safedate/v1/session/{id}/safety-status
POST /wp-json/datebook-safedate/v1/privacy/request
GET  /wp-json/datebook-safedate/v1/privacy/my-data
GET  /wp-json/datebook-safedate/v1/admin/ops
POST /wp-json/datebook-safedate/v1/admin/action
```

## Production notes

This plugin is a WordPress/PWA safety layer. Browser geolocation still depends on user permission, HTTPS, browser limits, and whether the app/page is active. For reliable locked-screen/background tracking, build a native Android/iOS companion app that posts to the same REST API.

For true server-sent Web Push delivery, install `minishlink/web-push` in the WordPress project and configure VAPID keys in the SafeDate settings. Without that library, the plugin stores subscriptions and uses browser/PWA polling while the site is open.

## Privacy and consent

SafeDate is designed for consent-based safety workflows. Do not use it for hidden surveillance. Make your terms, consent screens, retention policy, and emergency access policy clear before production deployment.


## v0.7.0 Native Mobile API Hardening

v0.6 adds a hardened native mobile API layer for Android/iOS companion apps:

- Mobile device registration endpoint.
- Bearer access token plus refresh token flow.
- One-time signing secret returned to the native app.
- HMAC-SHA256 signed location payloads.
- HMAC-SHA256 signed device-health payloads.
- Replay protection with nonce storage.
- Timestamp-window validation.
- Per-device REST rate limiting.
- Token revocation endpoint.
- Admin Mobile API Security dashboard.
- Native Android and iOS signature examples.

New shortcode:

```text
[db_safedate_mobile_pairing]
```

New endpoints:

```text
POST /wp-json/datebook-safedate/v1/mobile/register-device
POST /wp-json/datebook-safedate/v1/mobile/refresh-token
POST /wp-json/datebook-safedate/v1/mobile/revoke-device
GET  /wp-json/datebook-safedate/v1/mobile/whoami
POST /wp-json/datebook-safedate/v1/mobile/location/signed
POST /wp-json/datebook-safedate/v1/mobile/device-health/signed
GET  /wp-json/datebook-safedate/v1/mobile/session/{id}/safety-status
GET  /wp-json/datebook-safedate/v1/admin/mobile/security
```

See `docs/native-mobile-integration-v0.6.md` for the full Android/iOS integration spec.


## v0.7.0 Native App Starter Kits

This release adds starter native client projects that consume the hardened v0.6 mobile API.

Included paths:

```text
native/android/SafeDateStarter/
native/ios/SafeDateStarter/
native/shared/
docs/native-app-starter-kits-v0.7.md
```

New endpoint:

```text
GET /wp-json/datebook-safedate/v1/mobile/app-config
```

New shortcode:

```text
[db_safedate_native_apps]
```

The starter apps are intentionally minimal and dependency-light. Before production, replace manual credential entry with your WordPress/DateBook login flow and store credentials in OS secure storage.


## v0.7.1 Stabilization/Security Hotfix

This package includes the v0.7.1 hardening release:

- Audit/export location redaction for host/non-admin viewers.
- Central session state-machine validation.
- Fixed Native Apps admin submenu parent slug.
- Root-scope service worker at `/dbsd-sw.js`.
- Web Push health endpoint: `GET /wp-json/datebook-safedate/v1/push/health`.
- IP-based throttling for public/mobile token endpoints.
- Admin device revocation endpoint: `POST /wp-json/datebook-safedate/v1/admin/mobile/revoke-device`.
- Native app pairing flow design document.

See:

- `docs/stabilization-security-hotfix-v0.7.1.md`
- `docs/native-app-pairing-flow-v0.7.1.md`

## v0.7.8 Automated PHPUnit Test Release

This package adds automated regression tests for the stabilization/security areas identified in the v0.7 QA review and v0.7.1 hotfix.

New test assets:

```text
tests/bootstrap.php
tests/TestCase.php
tests/StateTransitionsTest.php
tests/RedactionBehaviorTest.php
tests/MobileTokenAbuseTest.php
tests/PWARegistrationTest.php
phpunit.xml.dist
composer.json
scripts/install-wp-tests.sh
.github/workflows/phpunit.yml
docs/phpunit-tests-v0.7.8.md
```

Coverage includes:

- strict SafeDate state transitions
- audit/export location redaction behavior
- mobile bearer-token abuse cases
- signed native location payload validation
- replay protection
- per-device rate limiting
- PWA manifest/service-worker configuration across standard and subdirectory WordPress installs

Run tests:

```bash
composer install
bash scripts/install-wp-tests.sh wordpress_test root '' localhost latest
vendor/bin/phpunit -c phpunit.xml.dist
```


## v0.7.8 Stabilization/Security Hotfix

Adds QA blocker fixes for redaction tests, root service-worker support, trusted-proxy IP throttling, authenticated mobile secret encryption, refresh-token device binding, admin device revocation UI, and expanded PHPUnit coverage. See `docs/stabilization-security-hotfix-v0.7.8.md`.

## v0.7.8 QA/Security Hardening

v0.7.8 is a stabilization-only release. It adds controlled service-worker rewrite flushing, dedicated SafeDate admin capability `dbsd_manage_safety`, signed refresh-token requests, refresh-token reuse detection, public trusted-share throttling, approximate-location accuracy bucketing, expanded PHPUnit tests, and documentation cleanup.

See:

```text
docs/qa-security-hardening-v0.7.8.md
docs/phpunit-tests-v0.7.8.md
```

Production gate: run the full PHPUnit CI matrix successfully before promoting beyond staging.


## v0.7.8 QA/Security Hardening

This package includes the v0.7.8 hardening layer:

- One-time native app pairing endpoints.
- Web Push readiness checks.
- Root service-worker diagnostics.
- Finer SafeDate admin capabilities.
- Behavioral PHPUnit tests for critical security paths.
- `CHANGELOG.md` and `SECURITY.md`.

Run the full test suite before production promotion:

```bash
composer install
bash scripts/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest true
vendor/bin/phpunit -c phpunit.xml.dist
```

Keep the plugin in staging until the GitHub Actions PHPUnit matrix passes.

## v0.7.8 Native Signature Compatibility and CI Proof

v0.7.8 closes the v0.7.7 QA blockers by aligning native client HMAC signing with the WordPress REST canonical route, adding shared test vectors, adding pairing-attempt telemetry, and upgrading native credential-storage examples.

New docs:

```text
docs/native-signature-ci-proof-v0.7.8.md
docs/ci-results-v0.7.8.md
native/shared/test-vector-v0.7.json
```

New endpoints:

```text
GET /wp-json/datebook-safedate/v1/mobile/signature-test-vector
GET /wp-json/datebook-safedate/v1/admin/pairing-attempts
GET /wp-json/datebook-safedate/v1/ci/proof
```

Production promotion still requires a passing GitHub Actions / WordPress PHPUnit matrix.


## v0.7.10 Native Starter Production-Wiring and CI Evidence

v0.7.10 addresses the v0.7.8 senior QA blockers by fixing route-signing documentation, wiring native starter apps to secure storage by default, adding signed native revoke/logout flows, adding pairing-attempt retention cleanup, redacting the public test-vector secret field, and generating CI evidence artifacts in GitHub Actions.

Docs:

```text
docs/native-starter-production-wiring-v0.7.10.md
docs/ci-results-v0.7.10.md
```

Production promotion still requires an actual successful GitHub Actions/WordPress PHPUnit matrix run URL and artifacts.
