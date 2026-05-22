import SwiftUI
import SwiftData

/// Settings tab — Plan 4a build-out.
///
/// Sections top-to-bottom: Sync, Account, Appearance, Storage, About.
struct SettingsView: View {

    // MARK: - Inputs

    let authAPI: AuthAPI
    let syncEngine: SyncEngine
    @Bindable var status: SyncStatus

    // MARK: - Environment / SwiftData

    @Environment(AuthManager.self) private var authManager
    @Environment(\.modelContext) private var context

    @Query private var syncMetas: [SyncMetadata]
    @Query(filter: #Predicate<Game> { $0.syncStateRaw == "conflict" })
    private var conflictGames: [Game]
    @Query(filter: #Predicate<Item> { $0.syncStateRaw == "conflict" })
    private var conflictItems: [Item]

    // MARK: - Local state

    @AppStorage("appearanceMode") private var appearanceMode: AppearanceMode = .system

    @State private var showConfirmSignOut = false
    @State private var signOutInFlight = false
    @State private var syncInFlight = false
    @State private var showConflicts = false
    @State private var showConfirmClearCache = false
    @State private var cacheBytes: Int64 = 0

    // MARK: - Derived

    private var usernameDisplay: String {
        if case let .loggedIn(_, username) = authManager.state {
            return username
        }
        return "—"
    }

    private var conflictCount: Int {
        conflictGames.count + conflictItems.count
    }

    private var lastSyncedAt: Date? {
        syncMetas.first?.lastSyncedAt
    }

    private var cachePaths: [URL] {
        [ImageCachePaths.coversThumbs,
         ImageCachePaths.coversFull,
         ImageCachePaths.extrasThumbs,
         ImageCachePaths.extrasFull]
    }

    private var versionString: String {
        let info = Bundle.main.infoDictionary
        let short = info?["CFBundleShortVersionString"] as? String ?? "—"
        let build = info?["CFBundleVersion"] as? String ?? "—"
        return "\(short) (\(build))"
    }

    // MARK: - Body

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                SyncStatusBannerView(status: status)
                List {
                    syncSection
                    accountSection
                    appearanceSection
                    storageSection
                    aboutSection
                }
            }
            .navigationTitle("Settings")
            .task { await refreshCacheSize() }
            .alert("Sign out?", isPresented: $showConfirmSignOut) {
                Button("Sign out", role: .destructive) {
                    Task { await signOut() }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("This wipes the local copy of your library and image cache. Server data is unaffected.")
            }
            .alert("Clear cached images?", isPresented: $showConfirmClearCache) {
                Button("Clear", role: .destructive) {
                    Task { await clearCache() }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Wipes \(ImageCacheSizeCalculator.formatted(cacheBytes)) of downloaded covers. They re-download as you browse. Your library and items are not affected.")
            }
            .sheet(isPresented: $showConflicts) { ConflictListView() }
        }
    }

    // MARK: - Sections

    @ViewBuilder
    private var syncSection: some View {
        Section("Sync") {
            HStack {
                Text("Last synced").foregroundStyle(.secondary)
                Spacer()
                if let when = lastSyncedAt {
                    Text(when, style: .relative).font(.body)
                } else {
                    Text("—").foregroundStyle(.secondary)
                }
            }

            Button {
                Task { await syncNow() }
            } label: {
                HStack {
                    Spacer()
                    if syncInFlight {
                        ProgressView()
                    } else {
                        Text("Sync now")
                    }
                    Spacer()
                }
            }
            .disabled(syncInFlight)

            if conflictCount > 0 {
                Button {
                    showConflicts = true
                } label: {
                    HStack {
                        Text("Conflicts (\(conflictCount))")
                            .foregroundStyle(.primary)
                        Spacer()
                        Image(systemName: "chevron.right")
                            .foregroundStyle(.secondary)
                            .font(.footnote.weight(.semibold))
                    }
                }
            }
        }
    }

    private var accountSection: some View {
        Section {
            HStack {
                Text("Signed in as").foregroundStyle(.secondary)
                Spacer()
                Text(usernameDisplay).font(.body.weight(.medium))
            }
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
        } header: {
            Text("Account")
        } footer: {
            Text("Signing out removes your token and erases all locally-stored games, items, and completions from this phone. Your data on the server is unaffected and will re-sync the next time you sign in.")
        }
    }

    private var appearanceSection: some View {
        Section("Appearance") {
            Picker("Theme", selection: $appearanceMode) {
                ForEach(AppearanceMode.allCases) { mode in
                    Text(mode.displayName).tag(mode)
                }
            }
            .pickerStyle(.menu)
        }
    }

    private var storageSection: some View {
        Section("Storage") {
            HStack {
                Text("Cached images").foregroundStyle(.secondary)
                Spacer()
                Text(ImageCacheSizeCalculator.formatted(cacheBytes)).font(.body)
            }
            Button(role: .destructive) {
                showConfirmClearCache = true
            } label: {
                HStack {
                    Spacer()
                    Text("Clear image cache")
                    Spacer()
                }
            }
            .disabled(cacheBytes == 0)
        }
    }

    private var aboutSection: some View {
        Section("About") {
            HStack {
                Text("Version").foregroundStyle(.secondary)
                Spacer()
                Text(versionString).font(.body)
            }
            Link(destination: Config.serverBaseURL) {
                HStack {
                    Text("Web app").foregroundStyle(.primary)
                    Spacer()
                    Text(Config.serverBaseURL.host ?? Config.serverBaseURL.absoluteString)
                        .foregroundStyle(.secondary)
                    Image(systemName: "arrow.up.right.square")
                        .foregroundStyle(.secondary)
                        .font(.footnote)
                }
            }
        }
    }

    // MARK: - Sync flow

    private func syncNow() async {
        syncInFlight = true
        defer { syncInFlight = false }
        try? await syncEngine.runOnce()
    }

    // MARK: - Storage flow

    private func refreshCacheSize() async {
        let paths = cachePaths
        let bytes = await Task.detached(priority: .userInitiated) {
            ImageCacheSizeCalculator.totalBytes(under: paths)
        }.value
        await MainActor.run { cacheBytes = bytes }
    }

    private func clearCache() async {
        for path in cachePaths {
            try? FileManager.default.removeItem(at: path)
            try? FileManager.default.createDirectory(at: path, withIntermediateDirectories: true)
        }
        await refreshCacheSize()
    }

    // MARK: - Sign-out flow (unchanged behaviour from previous SettingsView)

    private func signOut() async {
        signOutInFlight = true
        defer { signOutInFlight = false }

        // 1. Best-effort server-side revoke. Don't block on failure.
        try? await authAPI.revoke()

        // 2. Wipe every row across every @Model.
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
        authManager.clearLocalSession()
    }
}
