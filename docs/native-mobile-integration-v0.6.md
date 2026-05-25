# DateBook SafeDate Tracker v0.6 Native Mobile Integration Spec

This document describes the hardened native Android/iOS integration layer added in v0.6.

## Security model

The native app must use three credentials returned by `POST /mobile/register-device`:

- `access_token`: sent as `Authorization: Bearer <token>`.
- `refresh_token`: used only with `/mobile/refresh-token`.
- `signing_secret`: used to HMAC-sign sensitive payloads such as location and device health.

Store these values only in secure OS storage:

- Android: Android Keystore + EncryptedSharedPreferences.
- iOS: Keychain.

Do not store tokens in plain preferences, SQLite, crash logs, analytics events, or URLs.

## Device registration

The user must already be logged in to WordPress/DateBook or otherwise have a valid WordPress REST nonce/session.

`POST /wp-json/datebook-safedate/v1/mobile/register-device`

Headers:

```http
X-WP-Nonce: <wordpress_rest_nonce>
Content-Type: application/json
```

Body:

```json
{
  "device_uuid": "stable-device-installation-uuid",
  "platform": "android",
  "device_name": "Pixel 8",
  "app_version": "1.0.0"
}
```

Response:

```json
{
  "ok": true,
  "device_id": 12,
  "device_uuid": "stable-device-installation-uuid",
  "access_token": "...",
  "refresh_token": "...",
  "signing_secret": "...",
  "access_expires_at": "2026-05-25 20:00:00",
  "refresh_expires_at": "2026-06-23 20:00:00",
  "signature_algorithm": "HMAC-SHA256-Base64",
  "canonical_format": "METHOD\\nROUTE\\nTIMESTAMP\\nNONCE\\nSHA256_RAW_BODY"
}
```

## Signed request algorithm

Required for:

- `POST /mobile/location/signed`
- `POST /mobile/device-health/signed`

Headers:

```http
Authorization: Bearer <access_token>
X-DBSD-Device-ID: <device_uuid>
X-DBSD-Timestamp: <unix_seconds>
X-DBSD-Nonce: <random_128_bit_nonce_base64url>
X-DBSD-Signature: <base64_hmac_sha256>
Content-Type: application/json
```

Canonical string:

```text
METHOD\n
ROUTE\n
TIMESTAMP\n
NONCE\n
SHA256_RAW_BODY
```

Example canonical string for location:

```text
POST
/datebook-safedate/v1/mobile/location/signed
1779652800
qTuf4i3BfVpu5q1o
bf3f8f6b0e8a2...
```

Signature:

```text
base64( HMAC_SHA256(canonical_string, signing_secret) )
```

The server rejects:

- Missing signature headers.
- Device ID mismatch.
- Timestamp outside the configured clock window.
- Reused nonce.
- Bad HMAC.
- Expired/invalid access token.
- Rate-limit overflow.

## Signed location ping

`POST /wp-json/datebook-safedate/v1/mobile/location/signed`

Body:

```json
{
  "session_id": 123,
  "lat": 43.6532,
  "lng": -79.3832,
  "accuracy": 12.4,
  "speed": 1.2,
  "heading": 270,
  "battery_level": 0.82,
  "recorded_at": "2026-05-24T20:00:00Z"
}
```

Only the traveler device can submit signed location for a SafeDate session.

## Signed device-health ping

`POST /wp-json/datebook-safedate/v1/mobile/device-health/signed`

Body:

```json
{
  "session_id": 123,
  "online_status": "online",
  "battery_level": 0.82,
  "charging": false,
  "permission_state": "granted_background",
  "network_type": "wifi",
  "recorded_at": "2026-05-24T20:00:00Z"
}
```

## Token refresh

`POST /wp-json/datebook-safedate/v1/mobile/refresh-token`

Body:

```json
{
  "refresh_token": "...",
  "device_uuid": "..."
}
```

v0.7.4 requires this request to be HMAC-signed with the device signing secret using the same canonical header format as signed location/device-health calls. Refresh responses rotate both the access token and refresh token. The app must replace old tokens immediately. Reusing an old refresh token is treated as a critical security event and the device must be re-paired.

## Device revoke

`POST /wp-json/datebook-safedate/v1/mobile/revoke-device`

Header:

```http
Authorization: Bearer <access_token>
```

Use this on logout, account compromise, or device removal.

## Recommended Android implementation

- Use FusedLocationProviderClient for foreground/background location, respecting platform permission prompts.
- Store tokens and signing secret using Android Keystore-backed EncryptedSharedPreferences.
- Use WorkManager for resilient retry when network is unavailable.
- Use a foreground service only when legally/UX appropriate and with persistent user notification.
- Never collect background location unless the SafeDate session is active and the traveler explicitly started tracking.

## Recommended iOS implementation

- Use Core Location with Always/When-In-Use permissions according to product need.
- Store tokens and signing secret in Keychain.
- Use significant-location-change or background location only when the user explicitly starts a SafeDate journey.
- Display clear in-app tracking state and system-compliant background location disclosure.
- Retry failed pings using URLSession background tasks where appropriate.

## Operational notes

- The admin screen is under SafeDate > Mobile API v0.6.
- Critical events include bad signatures and replay attempts.
- Default access-token TTL is 24 hours; refresh TTL is 30 days.
- Default signed-request timestamp window is 5 minutes.
- Default signed-location limit is 30 requests/minute/device.
