package com.datebook.safedate

import android.app.Activity
import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import kotlin.concurrent.thread

class MainActivity : Activity() {
    private lateinit var output: TextView
    private lateinit var store: CredentialStore
    private var credentials: MobileCredentials? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = KeystoreCredentialStore(this)
        credentials = store.load()
        val layout = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(32, 32, 32, 32) }
        val apiBase = EditText(this).apply { hint = "https://example.com/wp-json/datebook-safedate/v1" }
        val pairingId = EditText(this).apply { hint = "Pairing ID from WordPress" }
        val pairingCode = EditText(this).apply { hint = "One-time pairing code" }
        val session = EditText(this).apply { hint = "SafeDate session ID" }
        val pair = Button(this).apply { text = "Pair this Android device" }
        val refresh = Button(this).apply { text = "Signed refresh token" }
        val ping = Button(this).apply { text = "Send demo signed location" }
        val logout = Button(this).apply { text = "Signed revoke/logout" }
        output = TextView(this)
        listOf(apiBase, pairingId, pairingCode, session, pair, refresh, ping, logout, output).forEach { layout.addView(it) }
        setContentView(layout)

        pair.setOnClickListener {
            thread {
                runCatching {
                    val api = SafeDateApi(apiBase.text.toString(), credentialStore = store)
                    credentials = api.pairDevice(pairingId.text.toString().toLong(), pairingCode.text.toString())
                    "Paired. Device UUID: ${credentials!!.deviceUuid}\nStored in Android Keystore-backed encrypted storage."
                }.onSuccess { show(it) }.onFailure { show(it.message ?: "Pairing error") }
            }
        }

        refresh.setOnClickListener {
            thread {
                runCatching {
                    val api = SafeDateApi(apiBase.text.toString(), credentials, store)
                    credentials = api.signedRefreshToken()
                    "Token refreshed and stored securely."
                }.onSuccess { show(it) }.onFailure { show(it.message ?: "Refresh error") }
            }
        }
        logout.setOnClickListener {
            thread {
                runCatching {
                    val api = SafeDateApi(apiBase.text.toString(), credentials, store)
                    api.signedRevokeAndLogout()
                    credentials = null
                    "Device revoked and local credentials cleared."
                }.onSuccess { show(it) }.onFailure { show(it.message ?: "Logout error") }
            }
        }
        ping.setOnClickListener {
            thread {
                runCatching {
                    val api = SafeDateApi(apiBase.text.toString(), credentials ?: error("Pair device first"), store)
                    api.signedLocationPing(session.text.toString().toLong(), 43.6532, -79.3832, 25f)
                }.onSuccess { show(it) }.onFailure { show(it.message ?: "Ping error") }
            }
        }
    }

    private fun show(text: String) = runOnUiThread { output.text = text }
}
