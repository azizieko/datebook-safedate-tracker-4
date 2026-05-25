# DateBook SafeDate Tracker v0.7.7 QA/CI Stabilization

This release addresses the v0.7.6 senior QA blockers.

## Included fixes

- Fixed the failing behavioral crypto PHPUnit test by sending the required `pairing_id`.
- Added pairing-code creation abuse controls per user and per IP per hour.
- Added persisted locked status and security event logging when pairing attempts exceed the configured limit.
- Added a pairing abuse controls PHPUnit test.
- Added signed refresh-token support to Android and iOS starter API clients.
- Added native secure storage examples: Android credential store placeholder and iOS Keychain helper.
- Updated PHPUnit suite naming to v0.7.7.

## CI note

This package includes the WordPress PHPUnit/CI configuration. Run the GitHub Actions workflow or local WordPress test suite before promoting beyond staging.
