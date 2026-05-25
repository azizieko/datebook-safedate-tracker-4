# v0.7.7 Local Validation and CI Status

## Local validation performed in this build environment

| Check | Result |
|---|---:|
| ZIP extraction source | Passed |
| PHP syntax lint across plugin and tests | Passed |
| JavaScript syntax check for shipped frontend/admin scripts | Passed |
| Package ZIP integrity | Passed after packaging |

## Full WordPress PHPUnit CI status

Full WordPress PHPUnit execution was **not run in this build environment** because Composer, PHPUnit, and the WordPress test library are not installed here.

The package includes:

- `composer.json`
- `phpunit.xml.dist`
- `scripts/install-wp-tests.sh`
- `.github/workflows/phpunit.yml`

Run the included GitHub Actions workflow or a local WordPress test environment before promotion beyond staging.

## v0.7.7 tests added/updated

- Fixed `BehavioralCryptoRoundTripTest` to include the required `pairing_id` when claiming a native pairing code.
- Added `PairingAbuseControlsTest` for user/hour pairing-code throttling and persisted lockout state after failed claims.
- Kept existing mobile-token abuse, native-pairing hardening, service-worker, push readiness, and redaction tests.
