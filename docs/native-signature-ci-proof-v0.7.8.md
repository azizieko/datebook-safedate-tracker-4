# DateBook SafeDate Tracker v0.7.8 — Native Signature Compatibility and CI Proof Release

## Purpose

v0.7.8 closes the v0.7.7 QA blockers around native Android/iOS signature compatibility and release evidence.

## Fixes

- Android and iOS starter clients now sign the WordPress REST canonical route, for example `/datebook-safedate/v1/mobile/refresh-token`, instead of the short client route `/mobile/refresh-token`.
- Added a server-side canonical-route helper and a public signature test-vector endpoint.
- Added pairing-attempt telemetry table for successful, failed, invalid, locked, and race-condition pairing attempts.
- Added admin pairing-attempt visibility endpoint.
- Added full Android Keystore AES-GCM credential storage example.
- Added full iOS Keychain save/load/delete credential storage helper.
- Added PHPUnit coverage for canonical route/signature compatibility and pairing telemetry.

## Signature canonical format

```text
METHOD
/datebook-safedate/v1/mobile/refresh-token
TIMESTAMP
NONCE
SHA256_RAW_BODY
```

Clients may still call URLs relative to a REST namespace base URL, such as:

```text
https://example.com/wp-json/datebook-safedate/v1/mobile/refresh-token
```

But the signed route must include the REST namespace path exactly as WordPress returns it via `WP_REST_Request::get_route()`.

## Test vector

See:

```text
native/shared/test-vector-v0.7.json
GET /wp-json/datebook-safedate/v1/mobile/signature-test-vector
```

## CI status

This package includes CI workflow and PHPUnit tests. A full CI pass must still be run in GitHub Actions or an equivalent WordPress PHPUnit environment before production release.
