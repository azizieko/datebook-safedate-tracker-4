# v0.7.10 - Release Candidate Hardening and CI Evidence

## v0.7.10-ci2 - PHPUnit bootstrap correction

- Loaded `tests/TestCase.php` from `tests/bootstrap.php` after the WordPress PHPUnit bootstrap so every matrix cell can include test files in any order.
- Ran v0.7.10 upgrade hooks in the PHPUnit bootstrap and shared fixture setup so custom SafeDate tables/options are present before test execution.
- Documented the second-pass GitHub Actions failure mode and correction.


- Fixed device-level pairing-abuse throttling to use pairing-attempt telemetry.
- Logged blocked pairing-code creation attempts into telemetry.
- Removed Android API insecure default credential-store fallback.
- Updated Android versionCode/versionName.
- Updated iOS README for v0.7.10.
- Added signed revoke/logout fallback using refresh token for expired access-token cases.
- Added CI evidence artifact generation for v0.7.10 and native starter checks.
- Added v0.7.10 behavioral tests.

# Changelog

## 0.7.9 - CI Evidence and Native Starter Production-Wiring

- Fixed native API route-signing documentation to use WordPress REST canonical routes.
- Wired Android starter to Keystore-backed credential storage by default.
- Wired iOS starter to Keychain-backed credential storage by default.
- Added signed native revoke/logout flows and made server revoke require a signed mobile request.
- Added pairing-attempt retention cleanup via daily WP-Cron.
- Removed raw `secret` from the public signature test-vector response.
- Added CI evidence artifact generation to GitHub Actions.
- Added v0.7.9 behavioral tests covering vector redaction, retention cleanup, and native secure-storage wiring.


## 0.7.7

- Fixed the v0.7.6 PHPUnit pairing test by including `pairing_id`.
- Added pairing-code creation abuse controls per user and per IP.
- Persisted locked pairing-code status after repeated invalid claims.
- Added pairing abuse PHPUnit coverage.
- Added signed refresh-token support to Android and iOS starter clients.
- Added Android credential-store and iOS Keychain secure-storage examples.
- Updated PHPUnit suite name to v0.7.7.

## 0.7.6

- Hardened native app pairing with required `pairing_id + code`.
- Added atomic pairing claim transition before token issuance.
- Added pairing-code attempt counting and lockout.
- Increased short-lived pairing code entropy to 10 hex characters.
- Updated Android starter to consume pairing endpoints.
- Updated iOS starter to consume pairing endpoints.
- Added PHPUnit coverage for pairing ID requirement, lockout, and single-use atomic claim behavior.
- Updated PHPUnit suite name to v0.7.6.


## 0.7.5

- Added production-style one-time native app pairing endpoints.
- Added mobile pairing shortcode.
- Added Web Push readiness endpoint and admin warnings.
- Added service-worker diagnostics endpoint.
- Added finer-grained SafeDate admin capabilities.
- Added behavioral PHPUnit coverage for pairing, push readiness, service-worker diagnostics, exact-location redaction, and encrypted secret storage.
- Added `SECURITY.md`.

## 0.7.4

- Added signed refresh-token requests, refresh-token reuse detection, public trusted-share throttling, service-worker rewrite flushing, and dedicated base capability.

## 0.7.3

- Added service-worker rewrite support, trusted-proxy IP handling, authenticated encryption for new mobile secrets, and admin device revocation UI.

## 0.7.2

- Added initial PHPUnit harness and test suite.

## 0.7.1 and earlier

- Added DateBook SafeDate sessions, audit logs, PWA support, mobile API hardening, and native starter kits.

## 0.7.8 - Native Signature Compatibility and CI Proof

- Fixed Android/iOS signed request canonical route mismatch.
- Added server signature test-vector endpoint.
- Added shared native HMAC test vector.
- Added pairing-attempt telemetry table and admin endpoint.
- Added Android Keystore credential storage example.
- Added full iOS Keychain save/load/delete helper.
- Added PHPUnit coverage for native signature compatibility and pairing telemetry.
- Added v0.7.8 CI results status document.