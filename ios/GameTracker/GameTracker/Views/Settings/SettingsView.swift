import SwiftUI
import SwiftData

/// Settings tab — currently just account + sign-out. Future Plan 3b work
/// will add appearance, clear cache, etc.
struct SettingsView: View {
    let authAPI: AuthAPI

    @Environment(AuthManager.self) private var authManager
    @Environment(\.modelContext) private var context

    @State private var showConfirmSignOut = false
    @State private var signOutInFlight = false

    private var usernameDisplay: String {
        if case let .loggedIn(_, username) = authManager.state {
            return username
        }
        return "—"
    }

    var body: some View {
        NavigationStack {
            List {
                Section("Account") {
                    HStack {
                        Text("Signed in as")
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(usernameDisplay).font(.body.weight(.medium))
                    }
                }

                Section {
                    Button(role: .destructive) {
                        showConfirmSignOut = true
                    } label: {
                        HStack {
                            Spacer()
                            if signOutInFlight {
                                ProgressView()
                            } else {
                                Text("Sign out")
                            }
                            Spacer()
                        }
                    }
                    .disabled(signOutInFlight)
                } footer: {
                    Text("Signing out removes your token and erases all locally-stored games, items, and completions from this phone. Your data on the server is unaffected and will re-sync the next time you sign in.")
                }
            }
            .navigationTitle("Settings")
            .alert("Sign out?", isPresented: $showConfirmSignOut) {
                Button("Sign out", role: .destructive) {
                    Task { await signOut() }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("This wipes the local copy of your library and image cache. Server data is unaffected.")
            }
        }
    }

    // MARK: - Sign-out flow

    private func signOut() async {
        signOutInFlight = true
        defer { signOutInFlight = false }

        // 1. Best-effort server-side revoke. Don't block on failure — the
        //    token still gets cleared locally below.
        try? await authAPI.revoke()

        // 2. Wipe every row across every @Model in the SwiftData store.
        try? context.delete(model: Game.self)
        try? context.delete(model: Item.self)
        try? context.delete(model: GameCompletion.self)
        try? context.delete(model: GameImage.self)
        try? context.delete(model: ItemImage.self)
        try? context.delete(model: SyncMetadata.self)
        try? context.save()

        // 3. Clear the on-disk cover cache so the next signed-in user
        //    doesn't briefly see this user's covers before re-downloads land.
        try? FileManager.default.removeItem(at: ImageCachePaths.coversThumbs)

        // 4. Clear keychain + UserDefaults + flip auth state.
        //    RootView observes the state and swaps to LoginView automatically.
        authManager.clearLocalSession()
    }
}
