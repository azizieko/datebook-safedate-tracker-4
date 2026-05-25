import Foundation
import CryptoKit

struct MobileCredentials {
    let deviceUuid: String
    var accessToken: String
    var refreshToken: String
    let signingSecret: String
}

final class SafeDateApi {
    let apiBase: URL
    var credentials: MobileCredentials?

    init(apiBase: URL, credentials: MobileCredentials? = nil) {
        self.apiBase = apiBase
        self.credentials = credentials ?? (try? KeychainStore.loadCredentials())
    }

    private func sha256Hex(_ body: Data) -> String {
        SHA256.hash(data: body).map { String(format: "%02x", $0) }.joined()
    }

    private func hmacBase64(secret: String, canonical: String) -> String {
        let key = SymmetricKey(data: Data(secret.utf8))
        let signature = HMAC<SHA256>.authenticationCode(for: Data(canonical.utf8), using: key)
        return Data(signature).base64EncodedString()
    }

    private func routeURL(_ route: String) throws -> URL {
        let base = apiBase.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let url = URL(string: base + route) else { throw NSError(domain: "SafeDateApi", code: 0, userInfo: [NSLocalizedDescriptionKey: "Invalid API URL"]) }
        return url
    }

    /// WordPress verifies signatures against WP_REST_Request::get_route(), e.g.
    /// /datebook-safedate/v1/mobile/refresh-token. Keep short routes for URL building
    /// when apiBase already ends in /datebook-safedate/v1, but sign the canonical route.
    private func canonicalRoute(_ route: String) -> String {
        route.hasPrefix("/datebook-safedate/v1/") ? route : "/datebook-safedate/v1" + route
    }

    private func postJSON(route: String, body: Data, headers: [String: String] = ["Content-Type": "application/json"]) async throws -> Data {
        var request = URLRequest(url: try routeURL(route))
        request.httpMethod = "POST"
        request.httpBody = body
        headers.forEach { request.setValue($0.value, forHTTPHeaderField: $0.key) }
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw NSError(domain: "SafeDateApi", code: 1, userInfo: [NSLocalizedDescriptionKey: String(data: data, encoding: .utf8) ?? "Request failed"])
        }
        return data
    }

    /// Pair a native app using the WordPress-generated pairing_id + one-time code. Store returned credentials in Keychain in production.
    func pairDevice(pairingId: Int, code: String, deviceName: String = "iOS SafeDate", appVersion: String = "0.7.10") async throws -> MobileCredentials {
        let deviceUuid = UUID().uuidString
        let payload: [String: Any] = [
            "pairing_id": pairingId,
            "code": code,
            "device_uuid": deviceUuid,
            "platform": "ios",
            "device_name": deviceName,
            "app_version": appVersion
        ]
        let body = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let data = try await postJSON(route: "/mobile/pair-device", body: body)
        let json = try JSONSerialization.jsonObject(with: data) as? [String: Any]
        let creds = MobileCredentials(
            deviceUuid: json?["device_uuid"] as? String ?? deviceUuid,
            accessToken: json?["access_token"] as? String ?? "",
            refreshToken: json?["refresh_token"] as? String ?? "",
            signingSecret: json?["signing_secret"] as? String ?? ""
        )
        credentials = creds
        try? KeychainStore.save(credentials: creds)
        return creds
    }

    private func signedRequest(method: String, route: String, body: Data) throws -> URLRequest {
        guard let credentials = credentials else { throw NSError(domain: "SafeDateApi", code: 2, userInfo: [NSLocalizedDescriptionKey: "Pair device first"]) }
        var request = URLRequest(url: try routeURL(route))
        request.httpMethod = method
        request.httpBody = body
        let timestamp = String(Int(Date().timeIntervalSince1970))
        let nonce = UUID().uuidString
        let canonical = "\(method.uppercased())\n\(canonicalRoute(route))\n\(timestamp)\n\(nonce)\n\(sha256Hex(body))"
        request.setValue("Bearer \(credentials.accessToken)", forHTTPHeaderField: "Authorization")
        request.setValue(credentials.deviceUuid, forHTTPHeaderField: "X-DBSD-Device-Id")
        request.setValue(timestamp, forHTTPHeaderField: "X-DBSD-Timestamp")
        request.setValue(nonce, forHTTPHeaderField: "X-DBSD-Nonce")
        request.setValue(hmacBase64(secret: credentials.signingSecret, canonical: canonical), forHTTPHeaderField: "X-DBSD-Signature")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        return request
    }



    func signedRefreshToken() async throws -> MobileCredentials {
        guard let credentials = credentials else { throw NSError(domain: "SafeDateApi", code: 5, userInfo: [NSLocalizedDescriptionKey: "Pair device first"]) }
        let payload: [String: Any] = [
            "refresh_token": credentials.refreshToken,
            "device_uuid": credentials.deviceUuid
        ]
        let body = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let request = try signedRequest(method: "POST", route: "/mobile/refresh-token", body: body)
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw NSError(domain: "SafeDateApi", code: 6, userInfo: [NSLocalizedDescriptionKey: String(data: data, encoding: .utf8) ?? "Refresh failed"])
        }
        let json = try JSONSerialization.jsonObject(with: data) as? [String: Any]
        let updated = MobileCredentials(
            deviceUuid: credentials.deviceUuid,
            accessToken: json?["access_token"] as? String ?? "",
            refreshToken: json?["refresh_token"] as? String ?? "",
            signingSecret: credentials.signingSecret
        )
        self.credentials = updated
        try? KeychainStore.save(credentials: updated)
        return updated
    }

    func signedRevokeAndLogout() async throws -> String {
        guard let credentials = credentials else { throw NSError(domain: "SafeDateApi", code: 7, userInfo: [NSLocalizedDescriptionKey: "Pair device first"]) }
        let payload: [String: Any] = ["device_uuid": credentials.deviceUuid, "refresh_token": credentials.refreshToken]
        let body = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let request = try signedRequest(method: "POST", route: "/mobile/revoke-device", body: body)
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw NSError(domain: "SafeDateApi", code: 8, userInfo: [NSLocalizedDescriptionKey: String(data: data, encoding: .utf8) ?? "Revoke failed"])
        }
        try? KeychainStore.clearCredentials()
        self.credentials = nil
        return String(data: data, encoding: .utf8) ?? "{}"
    }

    func signedLocationPing(sessionId: Int, lat: Double, lng: Double, accuracy: Double?) async throws -> String {
        let payload: [String: Any] = [
            "session_id": sessionId,
            "lat": lat,
            "lng": lng,
            "accuracy": accuracy ?? 0,
            "recorded_at": ISO8601DateFormatter().string(from: Date())
        ]
        let body = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let request = try signedRequest(method: "POST", route: "/mobile/location/signed", body: body)
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw NSError(domain: "SafeDateApi", code: 3, userInfo: [NSLocalizedDescriptionKey: String(data: data, encoding: .utf8) ?? "Request failed"])
        }
        return String(data: data, encoding: .utf8) ?? "{}"
    }

    func signedDeviceHealth(sessionId: Int, batteryLevel: Double?, charging: Bool?) async throws -> String {
        let payload: [String: Any] = [
            "session_id": sessionId,
            "online_status": "online",
            "battery_level": batteryLevel ?? 0,
            "charging": charging ?? false,
            "permission_state": "granted",
            "recorded_at": ISO8601DateFormatter().string(from: Date())
        ]
        let body = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let request = try signedRequest(method: "POST", route: "/mobile/device-health/signed", body: body)
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw NSError(domain: "SafeDateApi", code: 4, userInfo: [NSLocalizedDescriptionKey: String(data: data, encoding: .utf8) ?? "Request failed"])
        }
        return String(data: data, encoding: .utf8) ?? "{}"
    }
}
