# DateBook SafeDate Tracker v0.7.4 PHPUnit Test Release

v0.7.4 adds automated PHPUnit coverage for the highest-risk areas identified in the v0.7 QA pass and v0.7.3 stabilization release.

## Test coverage added

### 1. State transitions

File: `tests/StateTransitionsTest.php`

Covers:

- Traveler can start a journey only from `ready`.
- Host cannot start B's journey.
- Host cannot claim B's arrival.
- Arrival and departure must happen in the correct order.
- REST `journey/start` rejects the wrong actor.

### 2. Location redaction

File: `tests/RedactionBehaviorTest.php`

Covers:

- Approximate mode rounds latitude/longitude to two decimal places.
- Approximate mode removes speed/heading movement metadata.
- Host audit API responses receive redacted location data by default.
- Traveler audit API responses retain exact location data.

### 3. Mobile token abuse and signed payload security

File: `tests/MobileTokenAbuseTest.php`

Covers:

- Invalid bearer token rejection.
- Bad HMAC signature rejection.
- Stale timestamp rejection.
- Nonce replay rejection.
- Per-device rate limiting for signed location pings.

### 4. PWA registration across common WordPress hosting setups

File: `tests/PWARegistrationTest.php`

Covers:

- Manifest uses the correct root scope and start URL.
- Frontend PWA script registers `/dbsd-sw.js`.
- Push health reports missing VAPID keys correctly.
- Subdirectory WordPress installs keep the service worker URL root-scoped under the public `home_url()`.

## Running locally

Install Composer dependencies:

```bash
composer install
```

Install the WordPress PHPUnit test suite:

```bash
bash scripts/install-wp-tests.sh wordpress_test root '' localhost latest
```

Run the tests:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Run a single test group/file:

```bash
vendor/bin/phpunit -c phpunit.xml.dist tests/MobileTokenAbuseTest.php
```

## CI matrix

A GitHub Actions workflow is included at `.github/workflows/phpunit.yml` and is configured for:

- PHP 7.4, 8.1, and 8.2
- WordPress 6.0 and latest
- MySQL 8.0

## Important notes

These are automated regression tests, not a replacement for a full security review. Before production launch, also run:

- manual mobile device testing on Android/iOS
- background location testing with locked-screen scenarios
- Web Push tests with real VAPID keys
- hosting tests on Apache, Nginx, LiteSpeed, and managed WordPress platforms
- privacy/legal review of location retention and disclosure flows
