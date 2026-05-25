# DateBook SafeDate Tracker v0.7.6 - QA Security Hardening

v0.7.6 is a stabilization release that closes the v0.7.5 senior QA blockers. It does not add new social/dating features.

## Fixed

- Pair-device now requires both `pairing_id` and `code`.
- Pairing codes are now 10 hex characters and short-lived.
- Wrong pairing-code attempts increment `attempt_count`.
- Pairing codes lock after the configured failed-attempt threshold.
- Pairing claims use an atomic `UPDATE ... WHERE status='pending'` transition before token issuance.
- Native Android starter now calls `/mobile/pair-device` and no longer requires manual token entry.
- Native iOS starter now calls `/mobile/pair-device` and no longer requires manual token entry.
- PHPUnit suite name updated to v0.7.6.
- Added behavioral pairing hardening tests.

## CI requirement

This package includes the WordPress PHPUnit harness and GitHub Actions workflow, but it must still be run in a full WordPress test environment before production promotion. Attach the CI run URL to any production release candidate.

## Production notes

The starter apps still need production secure storage hardening: Android Keystore and iOS Keychain. The code now demonstrates the server pairing flow; token persistence should be implemented by the final app team.
