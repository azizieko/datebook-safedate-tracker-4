package com.datebook.safedate

import android.util.Base64
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.util.UUID
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

data class MobileCredentials(
    val deviceUuid: String,
    val accessToken: String,
    val refreshToken: String,
    val signingSecret: String
)

/** Minimal dependency-free client for the DateBook SafeDate v0.7.10 mobile API. */
class SafeDateApi(private val apiBase: String, private var credentials: MobileCredentials? = null, private val credentialStore: CredentialStore) {
    init {
        if (credentials == null) credentials = credentialStore.load()
    }
    private fun sha256Hex(raw: String): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(raw.toByteArray(Charsets.UTF_8))
        return digest.joinToString("") { "%02x".format(it) }
    }

    private fun hmacBase64(secret: String, canonical: String): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(secret.toByteArray(Charsets.UTF_8), "HmacSHA256"))
        return Base64.encodeToString(mac.doFinal(canonical.toByteArray(Charsets.UTF_8)), Base64.NO_WRAP)
    }

    /**
     * WordPress REST verifies signatures against WP_REST_Request::get_route(),
     * e.g. /datebook-safedate/v1/mobile/refresh-token. The URL helper still
     * posts to apiBase + short route when apiBase already ends in /datebook-safedate/v1.
     */
    private fun canonicalRoute(route: String): String {
        return if (route.startsWith("/datebook-safedate/v1/")) route else "/datebook-safedate/v1" + route
    }

    private fun postJson(route: String, body: String, headers: Map<String, String> = mapOf("Content-Type" to "application/json")): String {
        val url = URL(apiBase.trimEnd('/') + route)
        val conn = (url.openConnection() as HttpURLConnection)
        conn.requestMethod = "POST"
        headers.forEach { (k, v) -> conn.setRequestProperty(k, v) }
        conn.doOutput = true
        conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
        val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
        val response = stream.bufferedReader().use { it.readText() }
        if (conn.responseCode !in 200..299) error("HTTP ${conn.responseCode}: $response")
        return response
    }

    /** Pair a native app using the WordPress-generated pairing_id + one-time code. Store returned credentials in Android Keystore in production. */
    fun pairDevice(pairingId: Long, code: String, deviceName: String = "Android SafeDate", appVersion: String = "0.7.10"): MobileCredentials {
        val deviceUuid = UUID.randomUUID().toString()
        val body = "{" +
            "\"pairing_id\":$pairingId," +
            "\"code\":\"$code\"," +
            "\"device_uuid\":\"$deviceUuid\"," +
            "\"platform\":\"android\"," +
            "\"device_name\":\"$deviceName\"," +
            "\"app_version\":\"$appVersion\"" +
            "}"
        val json = JSONObject(postJson("/mobile/pair-device", body))
        val creds = MobileCredentials(
            deviceUuid = json.getString("device_uuid"),
            accessToken = json.getString("access_token"),
            refreshToken = json.getString("refresh_token"),
            signingSecret = json.getString("signing_secret")
        )
        credentials = creds
        credentialStore.save(creds)
        return creds
    }

    private fun signedHeaders(method: String, route: String, body: String): Map<String, String> {
        val c = credentials ?: error("Mobile credentials are required")
        val timestamp = (System.currentTimeMillis() / 1000L).toString()
        val nonce = UUID.randomUUID().toString()
        val canonical = method.uppercase() + "\n" + canonicalRoute(route) + "\n" + timestamp + "\n" + nonce + "\n" + sha256Hex(body)
        return mapOf(
            "Authorization" to "Bearer ${c.accessToken}",
            "X-DBSD-Device-Id" to c.deviceUuid,
            "X-DBSD-Timestamp" to timestamp,
            "X-DBSD-Nonce" to nonce,
            "X-DBSD-Signature" to hmacBase64(c.signingSecret, canonical),
            "Content-Type" to "application/json"
        )
    }

    fun postSigned(route: String, body: String): String = postJson(route, body, signedHeaders("POST", route, body))



    fun signedRefreshToken(): MobileCredentials {
        val c = credentials ?: credentialStore.load() ?: error("Mobile credentials are required")
        credentials = c
        val route = "/mobile/refresh-token"
        val body = "{" +
            "\"refresh_token\":\"${c.refreshToken}\"," +
            "\"device_uuid\":\"${c.deviceUuid}\"" +
            "}"
        val json = JSONObject(postJson(route, body, signedHeaders("POST", route, body)))
        val updated = MobileCredentials(
            deviceUuid = c.deviceUuid,
            accessToken = json.getString("access_token"),
            refreshToken = json.getString("refresh_token"),
            signingSecret = c.signingSecret
        )
        credentials = updated
        credentialStore.save(updated)
        return updated
    }

    /** Revoke this device on the server with the signed mobile API, then clear local credentials. */
    fun signedRevokeAndLogout(): String {
        val c = credentials ?: credentialStore.load() ?: error("Mobile credentials are required")
        credentials = c
        val body = "{" + "\"device_uuid\":\"${c.deviceUuid}\"" + "}"
        val response = postSigned("/mobile/revoke-device", body)
        credentialStore.clear()
        credentials = null
        return response
    }

    fun signedLocationPing(sessionId: Long, lat: Double, lng: Double, accuracy: Float?): String {
        val body = "{" +
            "\"session_id\":$sessionId," +
            "\"lat\":$lat," +
            "\"lng\":$lng," +
            "\"accuracy\":${accuracy ?: 0f}," +
            "\"recorded_at\":\"${java.time.Instant.now()}\"" +
            "}"
        return postSigned("/mobile/location/signed", body)
    }

    fun signedDeviceHealth(sessionId: Long, batteryLevel: Float?, charging: Boolean?): String {
        val body = "{" +
            "\"session_id\":$sessionId," +
            "\"online_status\":\"online\"," +
            "\"battery_level\":${batteryLevel ?: 0f}," +
            "\"charging\":${charging ?: false}," +
            "\"permission_state\":\"granted\"," +
            "\"recorded_at\":\"${java.time.Instant.now()}\"" +
            "}"
        return postSigned("/mobile/device-health/signed", body)
    }
}
