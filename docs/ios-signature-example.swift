import Foundation
import CryptoKit

func sha256Hex(_ data: Data) -> String {
    let digest = SHA256.hash(data: data)
    return digest.map { String(format: "%02x", $0) }.joined()
}

func hmacBase64(secret: String, canonical: String) -> String {
    let key = SymmetricKey(data: Data(secret.utf8))
    let mac = HMAC<SHA256>.authenticationCode(for: Data(canonical.utf8), using: key)
    return Data(mac).base64EncodedString()
}

func buildSafeDateSignature(method: String, route: String, timestamp: Int, nonce: String, rawJsonBody: String, signingSecret: String) -> String {
    let bodyHash = sha256Hex(Data(rawJsonBody.utf8))
    let canonical = "\(method.uppercased())\n\(route)\n\(timestamp)\n\(nonce)\n\(bodyHash)"
    return hmacBase64(secret: signingSecret, canonical: canonical)
}
