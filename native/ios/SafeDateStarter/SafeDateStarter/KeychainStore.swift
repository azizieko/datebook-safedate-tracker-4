import Foundation
import Security

/// Production-oriented Keychain helper for SafeDate native credentials.
/// Store refresh tokens and signing secrets in Keychain only; never UserDefaults or logs.
final class KeychainStore {
    static let service = "com.datebook.safedate.mobile"

    static func save(account: String, value: String) throws {
        let data = Data(value.utf8)
        try delete(account: account, ignoreMissing: true)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]
        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else { throw NSError(domain: NSOSStatusErrorDomain, code: Int(status)) }
    }

    static func load(account: String) throws -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecMatchLimit as String: kSecMatchLimitOne,
            kSecReturnData as String: true
        ]
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess, let data = item as? Data else { throw NSError(domain: NSOSStatusErrorDomain, code: Int(status)) }
        return String(data: data, encoding: .utf8)
    }

    static func delete(account: String, ignoreMissing: Bool = false) throws {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        let status = SecItemDelete(query as CFDictionary)
        if status == errSecItemNotFound && ignoreMissing { return }
        guard status == errSecSuccess || status == errSecItemNotFound else { throw NSError(domain: NSOSStatusErrorDomain, code: Int(status)) }
    }

    static func save(credentials: MobileCredentials) throws {
        try save(account: "deviceUuid", value: credentials.deviceUuid)
        try save(account: "accessToken", value: credentials.accessToken)
        try save(account: "refreshToken", value: credentials.refreshToken)
        try save(account: "signingSecret", value: credentials.signingSecret)
    }

    static func loadCredentials() throws -> MobileCredentials? {
        guard let deviceUuid = try load(account: "deviceUuid"),
              let accessToken = try load(account: "accessToken"),
              let refreshToken = try load(account: "refreshToken"),
              let signingSecret = try load(account: "signingSecret") else { return nil }
        return MobileCredentials(deviceUuid: deviceUuid, accessToken: accessToken, refreshToken: refreshToken, signingSecret: signingSecret)
    }

    static func clearCredentials() throws {
        try delete(account: "deviceUuid", ignoreMissing: true)
        try delete(account: "accessToken", ignoreMissing: true)
        try delete(account: "refreshToken", ignoreMissing: true)
        try delete(account: "signingSecret", ignoreMissing: true)
    }
}
