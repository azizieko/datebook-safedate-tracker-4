# DateBook SafeDate Native API Contract v0.7

Base URL:

```text
https://YOUR-DOMAIN.com/wp-json/datebook-safedate/v1
```

## Auth lifecycle

1. User logs into WordPress/DateBook in an authenticated webview or native auth flow.
2. App calls `POST /mobile/register-device` while authenticated.
3. Server returns:
   - `device_uuid`
   - `access_token`
   - `refresh_token`
   - `signing_secret`
4. Store values securely:
   - Android: EncryptedSharedPreferences or Android Keystore-backed storage.
   - iOS: Keychain.
5. Use bearer token for all mobile endpoints.
6. Sign sensitive POST requests with HMAC-SHA256.
7. Refresh access token before expiry using `POST /datebook-safedate/v1/mobile/refresh-token`.
8. Revoke device on logout using signed `POST /mobile/revoke-device`; include `device_uuid` and `refresh_token` in the signed JSON body so revoke can succeed after access-token expiry.

## Signature

For signed endpoints, add headers:

```text
Authorization: Bearer ACCESS_TOKEN
X-DBSD-Device-Id: DEVICE_UUID
X-DBSD-Timestamp: unix_seconds
X-DBSD-Nonce: random_uuid_or_128_bit_value
X-DBSD-Signature: base64(hmac_sha256(signing_secret, canonical))
Content-Type: application/json
```

Canonical string:

```text
METHOD
ROUTE
TIMESTAMP
NONCE
SHA256_RAW_BODY
```

Example route must be exactly the WordPress REST route path returned by `WP_REST_Request::get_route()`, not the full URL and not the short client route:

```text
/datebook-safedate/v1/datebook-safedate/v1/mobile/location/signed
```

## Endpoints consumed by starter apps

```text
GET  /mobile/app-config
POST /mobile/register-device
POST /datebook-safedate/v1/mobile/refresh-token
POST /mobile/revoke-device
GET  /mobile/whoami
POST /datebook-safedate/v1/mobile/location/signed
POST /mobile/device-health/signed
GET  /mobile/session/{id}/safety-status
```

## Production requirements

- HTTPS only.
- Never log tokens or signing secrets.
- Use certificate pinning after your API host is final.
- Use OS secure storage.
- Add background-location disclosures and consent screens.
- Add rate-limit UX so users see helpful retry messages after HTTP 429.
- Add offline queue for location pings.
- Add remote kill switch for old app versions if a security issue is discovered.

## v0.7.4 refresh-token hardening

`POST /datebook-safedate/v1/mobile/refresh-token` must now be signed with the device signing secret.

Required headers:

```http
X-DBSD-Device-Id: <device_uuid>
X-DBSD-Timestamp: <unix_seconds>
X-DBSD-Nonce: <random_unique_nonce>
X-DBSD-Signature: <base64_hmac_sha256>
```

Canonical string:

```text
POST
/datebook-safedate/v1/datebook-safedate/v1/mobile/refresh-token
<TIMESTAMP>
<NONCE>
<SHA256_RAW_BODY>
```

Refresh-token reuse after rotation is treated as a critical security event and the device must be re-paired.

## v0.7.5 Native Pairing Addendum

### Create pairing code

`POST /wp-json/datebook-safedate/v1/mobile/pairing-code`

Requires a logged-in WordPress/DateBook web session. Returns a short-lived one-time code.

### Claim pairing code

`POST /wp-json/datebook-safedate/v1/mobile/pair-device`

Body:

```json
{
  "pairing_id": 123,
  "code": "A1B2C3D4E5",
  "device_uuid": "native-generated-device-id",
  "platform": "android|ios",
  "device_name": "User visible device name",
  "app_version": "0.7.5"
}
```

Returns access token, refresh token, and signing secret. Native clients must store these in Android Keystore or iOS Keychain.

### Pairing status

`GET /wp-json/datebook-safedate/v1/mobile/pairing-status/{id}`

Requires the logged-in web user who created the pairing code.


## v0.7.6 Pairing hardening addendum

Native clients must call `/mobile/pair-device` with both fields:

```json
{
  "pairing_id": 123,
  "code": "A1B2C3D4E5",
  "device_uuid": "client-generated-uuid",
  "platform": "ios|android",
  "device_name": "User visible device name",
  "app_version": "0.7.6"
}
```

Pairing codes are single-use, short-lived, and locked after repeated failed attempts. The server atomically transitions a code from `pending` to `claiming` before token issuance. Clients must store the returned access token, refresh token, and signing secret in iOS Keychain or Android Keystore.


## v0.7.8 native signature compatibility, signed refresh, and secure storage

Native clients should refresh tokens by signing `POST /datebook-safedate/v1/mobile/refresh-token` with the existing device signing secret. The body must include `refresh_token` and `device_uuid`. The URL may be built with a short route when the API base already includes `/wp-json/datebook-safedate/v1`, but the signature must use the WordPress canonical route `/datebook-safedate/v1/datebook-safedate/v1/mobile/refresh-token`, matching `WP_REST_Request::get_route()`. Store refresh tokens and signing secrets in Android Keystore-backed encrypted storage or iOS Keychain.

Pairing abuse controls now include per-user and per-IP pairing-code creation limits, per-code failed-attempt lockout, and security-event logging for lockouts.


### v0.7.8 test-vector endpoints

```text
GET /wp-json/datebook-safedate/v1/mobile/signature-test-vector
```

The shared native test vector is stored at:

```text
native/shared/test-vector-v0.7.json
```


## v0.7.10 production-wiring addendum

- Android starter uses `KeystoreCredentialStore` by default from `MainActivity`.
- iOS starter loads/saves credentials through `KeychainStore` by default.
- Refresh and logout/revoke are signed native requests.
- `POST /mobile/revoke-device` is now treated as a signed mobile endpoint.
- Test-vector endpoint no longer returns a raw `secret`; the packaged JSON test vector remains for local client/server compatibility tests only.
- Pairing-attempt telemetry is retained according to `dbsd_pairing_attempt_retention_days` and cleaned up by a daily cron hook.
