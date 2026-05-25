# Security Policy

## Supported status

This plugin is in beta/staging until the full PHPUnit CI matrix passes and a legal/privacy review is complete.

## Reporting vulnerabilities

Report suspected vulnerabilities privately to the site owner or project maintainer. Do not disclose live user location, tokens, signing secrets, or audit data publicly.

## Sensitive data handled

The plugin may process precise location, profile identifiers, device metadata, emergency contacts, IP addresses, user agents, and safety incident records.

## Required production controls

- HTTPS only.
- Explicit consent before tracking.
- Exact-location access limited to the traveler and authorized staff.
- Production Web Push configured with VAPID keys and a Web Push provider/library.
- Native tokens stored in Android Keystore / iOS Keychain.
- CI test suite passing before deployment.
- Retention policy configured and documented.
- Admin access restricted with SafeDate-specific capabilities.
