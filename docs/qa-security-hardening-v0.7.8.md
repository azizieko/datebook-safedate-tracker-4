# DateBook SafeDate Tracker v0.7.4 QA/Security Hardening

v0.7.4 is a stabilization release. It does not add product features. It addresses the v0.7.3 senior QA blockers and expands the automated test suite around safety-critical behavior.

## Hardening changes

- Added controlled rewrite flushing on activation and v0.7.4 upgrade for the root service worker route `/dbsd-sw.js`.
- Added dedicated SafeDate administrator capability: `dbsd_manage_safety`.
- Granted `dbsd_manage_safety` to the WordPress administrator role during activation/upgrade.
- Replaced broad SafeDate admin menu/endpoint checks with the dedicated capability.
- Added signed refresh-token requests using the same HMAC-SHA256 canonical signing scheme as signed location and device-health payloads.
- Added refresh-token reuse detection through `wp_dbsd_mobile_refresh_history`.
- Added public trusted-share IP throttling.
- Continued trusted-proxy-only handling for forwarded IP headers.
- Added approximate-location accuracy bucketing for host/export/trusted-share views.
- Added PWA/service-worker rewrite flush tracking via `dbsd_v040_rewrite_flushed_version`.

## New/expanded automated tests

- Signed refresh-token requests reject bad signatures.
- Refresh-token rotation rejects reuse of old refresh tokens.
- Revoked devices cannot use authenticated mobile endpoints.
- Public trusted-share links are IP throttled.
- Location and export endpoints redact coordinates and bucket accuracy for non-exact viewers.
- Service-worker rewrite flush version is recorded on upgrade.
- Service-worker source includes the expected root scope and `Service-Worker-Allowed` behavior.
- Administrator role receives `dbsd_manage_safety`.
- Admin menu source uses dedicated SafeDate capability instead of broad `manage_options`.

## CI notes

The package includes `.github/workflows/phpunit.yml`, `composer.json`, `phpunit.xml.dist`, and `scripts/install-wp-tests.sh`. Run the full test matrix before production deployment.

Recommended command locally:

```bash
composer install
bash scripts/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest true
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit -c phpunit.xml.dist
```

## Remaining production checklist

- Run and publish successful CI results for all supported PHP/WordPress versions.
- Complete native Android/iOS secure storage implementation review.
- Configure real VAPID keys for Web Push.
- Review retention/legal policy for location and incident records.
- Perform browser-device PWA installation testing on iOS Safari, Android Chrome, and desktop Chrome/Edge.
