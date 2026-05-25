# SafeDateStarter iOS v0.7.10

Minimal SwiftUI starter client for DateBook SafeDate Tracker v0.7.10.

This starter uses:

- `URLSession` for REST calls.
- `CryptoKit` for HMAC-SHA256 signatures.
- `KeychainStore` for credential save/load/delete.
- The v0.7.10 signed refresh and signed revoke/logout flow.

## Production setup

1. Create a new iOS App project named `SafeDateStarter` in Xcode.
2. Add the Swift files from `SafeDateStarter/` to the target.
3. Configure the WordPress REST base URL ending in `/wp-json/datebook-safedate/v1`.
4. Pair with `pairing_id + code` from the WordPress shortcode `[db_safedate_mobile_pair_code]`.
5. Keep tokens and signing secret in Keychain only.
6. Disable debug logging of tokens, signing material, and exact GPS coordinates.
7. Add background location mode only after privacy/legal review and explicit consent screens.
8. Add APNs push registration if using native push notifications.

## v0.7.10 notes

- Signed requests must sign the canonical WordPress REST route such as `/datebook-safedate/v1/mobile/refresh-token`.
- Revoke/logout includes `refresh_token` in the signed body so logout can succeed even if the access token has expired, provided the refresh token and HMAC signature are valid.
