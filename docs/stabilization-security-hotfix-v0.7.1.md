# DateBook SafeDate Tracker v0.7.1 Stabilization/Security Hotfix

## Scope
v0.7.1 is a hardening release for the v0.7 native-app starter package. It focuses on privacy, audit/export controls, state-machine validation, PWA/service-worker correctness, and mobile API abuse controls.

## Fixes included

### 1. Audit/export location redaction
- Added `DBSD_State::redact_location_payload()` and viewer-aware location modes.
- Host/non-admin audit responses redact exact location payloads unless exact host viewing is explicitly enabled.
- Session JSON exports now include `redaction_mode` and redact event payloads and location rows for non-exact viewers.
- Exact location remains available to the traveler and administrators.

### 2. Strict session state-machine validation
- Added `includes/class-dbsd-state.php`.
- Core actions now validate role + session status before transitioning:
  - journey start
  - location ping
  - arrival claim/respond
  - departure claim/respond
  - journey stop
- Invalid out-of-order actions return HTTP 409 with the current state and allowed states.

### 3. Correct v0.7 admin menu slug
- Fixed `DBSD_V070::admin_menu()` parent slug from `dbsd-admin` to `dbsd`.

### 4. Root-scope service worker
- Service worker is now available at `/dbsd-sw.js` through `template_redirect`.
- Response includes `Service-Worker-Allowed: /`.
- Frontend service worker registration now uses the root-scope URL.

### 5. Better Web Push health checks
- Added admin endpoint:
  - `GET /wp-json/datebook-safedate/v1/push/health`
- Reports PWA/push enabled status, active subscriptions, service-worker URL/scope, VAPID configuration, library availability, and server-push readiness.

### 6. IP-based throttling for public/mobile token endpoints
- Added IP-level throttling before bearer-token lookup in mobile authentication.
- Added IP throttling to device registration and refresh-token endpoints.
- Failed public/mobile endpoint abuse is now logged as mobile security events.

### 7. Admin device revocation
- Added admin endpoint:
  - `POST /wp-json/datebook-safedate/v1/admin/mobile/revoke-device`
- Required body:
```json
{
  "device_id": 123,
  "reason": "Lost phone / suspicious activity / user request"
}
```
- Revocation is logged in mobile security events.

### 8. Native app pairing flow design
- Added `docs/native-app-pairing-flow-v0.7.1.md` with the recommended production pairing sequence.

## Remaining recommendations
- Add automated PHPUnit tests for state transitions and redaction behavior.
- Replace manual native starter login with the documented one-time pairing code flow.
- Add a dedicated admin UI button for device revocation instead of exposing only the REST endpoint.
- Add end-to-end browser/PWA tests for service worker registration on common hosting setups.
- Add formal DPIA/privacy review before production use.
