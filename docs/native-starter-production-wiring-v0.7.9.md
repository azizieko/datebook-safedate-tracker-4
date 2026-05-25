# DateBook SafeDate Tracker v0.7.9 Native Starter Production-Wiring

This release focuses on closing v0.7.8 QA blockers without adding product features.

## Fixed

- Native API docs now require canonical WordPress REST routes for HMAC signing, for example `/datebook-safedate/v1/mobile/location/signed`.
- Android starter `MainActivity` uses `KeystoreCredentialStore` by default.
- iOS starter loads and saves credentials through `KeychainStore` by default.
- Android and iOS starters include signed refresh and signed revoke/logout flows.
- Server-side device revoke now requires a signed mobile request.
- Public signature test-vector endpoint no longer returns the raw test secret.
- Pairing-attempt telemetry gets daily retention cleanup through `dbsd_v079_cleanup_pairing_attempts`.
- GitHub Actions now uploads PHPUnit XML logs and a CI evidence markdown artifact for every matrix job.

## Production gate

Do not promote until the GitHub Actions matrix passes and the run URL/artifacts are attached to the release notes.
