package com.datebook.safedate

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

interface CredentialStore {
    fun save(credentials: MobileCredentials)
    fun load(): MobileCredentials?
    fun clear()
}

/** Simple in-memory store for unit/demo use only. Do not use in production. */
class InMemoryCredentialStore : CredentialStore {
    private var value: MobileCredentials? = null
    override fun save(credentials: MobileCredentials) { value = credentials }
    override fun load(): MobileCredentials? = value
    override fun clear() { value = null }
}

/**
 * Production-oriented Android credential store using Android Keystore AES-GCM.
 * The encrypted blob is kept in SharedPreferences; the AES key is non-exportable
 * from Android Keystore. Never log tokens, GPS coordinates, or signing secrets.
 */
class KeystoreCredentialStore(context: Context) : CredentialStore {
    private val prefs = context.getSharedPreferences("safedate_secure_credentials", Context.MODE_PRIVATE)
    private val alias = "SafeDateMobileApiKey"

    private fun secretKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getEntry(alias, null) as? KeyStore.SecretKeyEntry)?.secretKey?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        val spec = KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setRandomizedEncryptionRequired(true)
            .build()
        generator.init(spec)
        return generator.generateKey()
    }

    override fun save(credentials: MobileCredentials) {
        val json = JSONObject().apply {
            put("deviceUuid", credentials.deviceUuid)
            put("accessToken", credentials.accessToken)
            put("refreshToken", credentials.refreshToken)
            put("signingSecret", credentials.signingSecret)
        }.toString()
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        val cipherText = cipher.doFinal(json.toByteArray(Charsets.UTF_8))
        prefs.edit()
            .putString("iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString("blob", Base64.encodeToString(cipherText, Base64.NO_WRAP))
            .apply()
    }

    override fun load(): MobileCredentials? {
        val iv = prefs.getString("iv", null) ?: return null
        val blob = prefs.getString("blob", null) ?: return null
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)))
        val json = JSONObject(String(cipher.doFinal(Base64.decode(blob, Base64.NO_WRAP)), Charsets.UTF_8))
        return MobileCredentials(
            deviceUuid = json.getString("deviceUuid"),
            accessToken = json.getString("accessToken"),
            refreshToken = json.getString("refreshToken"),
            signingSecret = json.getString("signingSecret")
        )
    }

    override fun clear() { prefs.edit().clear().apply() }
}
