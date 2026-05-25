# DateBook SafeDate Tracker v0.7.3 Stabilization/Security Hotfix

This release keeps v0.7.2 in staging posture and fixes the QA blockers reported by senior QA.

## Fixes
- Corrected PHPUnit redaction test response key from `audit` to `events`.
- Added rewrite/query-var support and subdirectory-aware matching for `/dbsd-sw.js`.
- Hardened client IP detection: forwarded headers are trusted only when `REMOTE_ADDR` matches configured trusted proxy CIDRs.
- Added `dbsd_trusted_proxy_cidrs` setting.
- Removed new plaintext signing-secret fallback.
- Replaced new mobile signing-secret storage with Sodium secretbox when available, falling back to AES-256-GCM.
- Kept legacy AES-CBC read support for devices created by older builds.
- Bound refresh token rotation to the expected device UUID/header.
- Added admin device revocation form in the Mobile API Security screen.
- Added/updated PHPUnit tests for redaction, PWA root service worker assumptions, trusted proxy behavior, crypto hardening, and admin revocation.
