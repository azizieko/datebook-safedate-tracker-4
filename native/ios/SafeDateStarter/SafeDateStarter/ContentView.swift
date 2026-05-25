import SwiftUI

struct ContentView: View {
    @State private var apiBase = "https://example.com/wp-json/datebook-safedate/v1"
    @State private var pairingId = ""
    @State private var pairingCode = ""
    @State private var sessionId = "123"
    @State private var credentials: MobileCredentials? = try? KeychainStore.loadCredentials()
    @State private var output = ""

    var body: some View {
        NavigationView {
            Form {
                Section("API") {
                    TextField("API base", text: $apiBase)
                    TextField("Session ID", text: $sessionId)
                }
                Section("Pair this iPhone") {
                    TextField("Pairing ID from WordPress", text: $pairingId)
                    TextField("One-time pairing code", text: $pairingCode)
                    Button("Pair device") { Task { await pairDevice() } }
                }
                Section("Demo") {
                    Button("Signed refresh token") { Task { await refreshToken() } }
                    Button("Send demo signed location") { Task { await sendDemoPing() } }
                    Button("Signed revoke/logout") { Task { await revokeLogout() } }
                    Text(output).font(.footnote)
                }
            }
            .navigationTitle("SafeDate Starter")
        }
    }

    private func pairDevice() async {
        guard let url = URL(string: apiBase), let id = Int(pairingId) else { output = "Invalid API base or pairing ID"; return }
        let api = SafeDateApi(apiBase: url)
        do {
            credentials = try await api.pairDevice(pairingId: id, code: pairingCode)
            output = "Paired. Device UUID: \(credentials?.deviceUuid ?? "")\nStored in iOS Keychain."
        } catch { output = error.localizedDescription }
    }


    private func refreshToken() async {
        guard let url = URL(string: apiBase) else { output = "Invalid API base"; return }
        let api = SafeDateApi(apiBase: url, credentials: credentials)
        do {
            credentials = try await api.signedRefreshToken()
            output = "Token refreshed and stored securely."
        } catch { output = error.localizedDescription }
    }

    private func revokeLogout() async {
        guard let url = URL(string: apiBase) else { output = "Invalid API base"; return }
        let api = SafeDateApi(apiBase: url, credentials: credentials)
        do {
            output = try await api.signedRevokeAndLogout()
            credentials = nil
        } catch { output = error.localizedDescription }
    }
    private func sendDemoPing() async {
        guard let url = URL(string: apiBase), let id = Int(sessionId) else { output = "Invalid API base or session ID"; return }
        let api = SafeDateApi(apiBase: url, credentials: credentials)
        do { output = try await api.signedLocationPing(sessionId: id, lat: 43.6532, lng: -79.3832, accuracy: 25) }
        catch { output = error.localizedDescription }
    }
}
