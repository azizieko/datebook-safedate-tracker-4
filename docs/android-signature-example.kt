import android.util.Base64
import java.security.MessageDigest
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

fun sha256Hex(input: ByteArray): String {
    return MessageDigest.getInstance("SHA-256").digest(input).joinToString("") { "%02x".format(it) }
}

fun hmacBase64(secret: String, canonical: String): String {
    val mac = Mac.getInstance("HmacSHA256")
    mac.init(SecretKeySpec(secret.toByteArray(Charsets.UTF_8), "HmacSHA256"))
    return Base64.encodeToString(mac.doFinal(canonical.toByteArray(Charsets.UTF_8)), Base64.NO_WRAP)
}

fun buildSafeDateSignature(
    method: String,
    route: String,
    timestamp: Long,
    nonce: String,
    rawJsonBody: String,
    signingSecret: String
): String {
    val bodyHash = sha256Hex(rawJsonBody.toByteArray(Charsets.UTF_8))
    val canonical = method.uppercase() + "\n" + route + "\n" + timestamp + "\n" + nonce + "\n" + bodyHash
    return hmacBase64(signingSecret, canonical)
}
