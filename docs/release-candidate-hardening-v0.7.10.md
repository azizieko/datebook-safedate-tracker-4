# DateBook SafeDate Tracker v0.7.10 Release Candidate Hardening

v0.7.10 closes the v0.7.9 senior-QA blockers that could be safely addressed inside the package:

- Device-level pairing abuse now uses `dbsd_mobile_pairing_attempts` telemetry instead of relying on fields that are not reliably present on pairing-code rows.
- Blocked pairing-code creation attempts are logged into telemetry.
- Android starter API no longer defaults to `InMemoryCredentialStore()`.
- Android version is updated to `versionCode 710` / `versionName 0.7.10`.
- iOS README is updated for the current pairing, Keychain, signed refresh, and signed revoke flows.
- Signed revoke/logout accepts a valid refresh-token fallback when the access token is expired, while still requiring a valid HMAC signature.
- CI workflow now generates v0.7.10 evidence artifacts and adds native starter checks.

Production promotion still requires an actual successful external CI run and attached artifacts.
