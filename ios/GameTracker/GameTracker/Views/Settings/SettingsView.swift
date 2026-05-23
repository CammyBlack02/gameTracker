import SwiftUI
import SwiftData

/// Settings tab — Plan 4a build-out.
///
/// Sections top-to-bottom: Sync, Account, Appearance, Storage, About.
struct SettingsView: View {

    // MARK: - Inputs

    let authAPI: AuthAPI
    let syncEngine: SyncEngine
    let imagesAPI: ImagesAPI
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

    /// Cover-preload progress reported during a Sync Now run.
    /// `coversTotal == 0` means the preload phase hasn't started yet
    /// (or there were no covers to fetch); the button shows the
    /// indeterminate sync spinner during that interval. Once the
    /// preload begins, the button switches to "Covers N / M".
    @State private var coversTotal: Int = 0
    @State private var coversDone: Int = 0

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
            .themedBackground()
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
                        if coversTotal > 0 {
                            // Cover-preload phase reports progress.
                            ProgressView(value: Double(coversDone),
                                         total: Double(coversTotal))
                                .progressViewStyle(.linear)
                                .frame(maxWidth: 120)
                            Text("\(coversDone) / \(coversTotal)")
                                .font(.footnote.monospacedDigit())
                                .foregroundStyle(.secondary)
                        } else {
                            ProgressView()
                        }
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
                Group {
                    Text(AppearanceMode.system.displayName).tag(AppearanceMode.system)
                    Text(AppearanceMode.light.displayName).tag(AppearanceMode.light)
                    Text(AppearanceMode.dark.displayName).tag(AppearanceMode.dark)
                }
                Divider()
                Group {
                    Text(AppearanceMode.matrix.displayName).tag(AppearanceMode.matrix)
                    Text(AppearanceMode.retroMac.displayName).tag(AppearanceMode.retroMac)
                    Text(AppearanceMode.gameBoy.displayName).tag(AppearanceMode.gameBoy)
                    Text(AppearanceMode.crtAmber.displayName).tag(AppearanceMode.crtAmber)
                }
            }
            .pickerStyle(.menu)

            ThemePreviewTile(mode: appearanceMode)
                .frame(height: 120)
                .listRowInsets(EdgeInsets())
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
        coversTotal = 0
        coversDone = 0
        defer {
            syncInFlight = false
            coversTotal = 0
            coversDone = 0
        }
        try? await syncEngine.runOnce()
        await preloadAllCoverThumbs()
    }

    /// After the data sync completes, pre-fetch every game's and item's
    /// cover thumbnail to local disk so the Library / Items / Stats /
    /// CoverFlow tabs render instantly without per-cell network round
    /// trips on first open. Already-downloaded files short-circuit
    /// inside ImagesAPI so this is cheap to run repeatedly.
    private func preloadAllCoverThumbs() async {
        // Snapshot the work: extract just the IDs + face flags off the
        // main actor so we don't pin SwiftData objects across the
        // download loop.
        let games = (try? context.fetch(FetchDescriptor<Game>(
            predicate: #Predicate { $0.serverId != nil
                                 && $0.syncStateRaw != "local_deleted" }
        ))) ?? []
        let items = (try? context.fetch(FetchDescriptor<Item>(
            predicate: #Predicate { $0.serverId != nil
                                 && $0.syncStateRaw != "local_deleted" }
        ))) ?? []

        enum Job: Sendable {
            case game(serverId: Int, face: ImagesAPI.Face)
            case item(serverId: Int, face: ImagesAPI.Face)
        }

        var jobs: [Job] = []
        for g in games {
            guard let id = g.serverId else { continue }
            if g.frontCoverImage != nil { jobs.append(.game(serverId: id, face: .front)) }
            if g.backCoverImage  != nil { jobs.append(.game(serverId: id, face: .back)) }
        }
        for i in items {
            guard let id = i.serverId else { continue }
            if i.frontImage != nil { jobs.append(.item(serverId: id, face: .front)) }
            if i.backImage  != nil { jobs.append(.item(serverId: id, face: .back)) }
        }

        coversTotal = jobs.count
        coversDone  = 0
        guard !jobs.isEmpty else { return }

        let api = imagesAPI
        await withTaskGroup(of: Void.self) { group in
            var iter = jobs.makeIterator()

            func dispatch(_ job: Job) {
                group.addTask { [api] in
                    switch job {
                    case .game(let id, let face):
                        _ = try? await api.downloadCover(
                            gameServerId: id, face: face, size: .thumb)
                    case .item(let id, let face):
                        _ = try? await api.downloadCover(
                            itemServerId: id, face: face, size: .thumb)
                    }
                }
            }

            // Prime up to 6 concurrent fetches; refill as each finishes.
            for _ in 0..<6 {
                if let next = iter.next() { dispatch(next) }
            }
            while await group.next() != nil {
                coversDone += 1
                if let next = iter.next() { dispatch(next) }
            }
        }
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

/// A compact preview that re-renders whenever the user changes
/// theme selection. Shows the theme's background, accent, and (if
/// applicable) a sample of the flourish that would appear in-app.
private struct ThemePreviewTile: View {
    let mode: AppearanceMode

    private var theme: Theme { ThemeRegistry.theme(for: mode) }

    var body: some View {
        ZStack {
            (theme.background ?? Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 8))

            // Flourish layer
            Group {
                switch theme.flourish {
                case .codeRain:
                    CodeRainView()
                        .environment(\.theme, theme)
                case .scanlines:
                    ScanlineOverlayView()
                case .platinumBevel:
                    LinearGradient(
                        colors: [Color(white: 0.93), Color(white: 0.80), Color(white: 0.67)],
                        startPoint: .top, endPoint: .bottom
                    )
                    .frame(height: 24)
                    .frame(maxHeight: .infinity, alignment: .top)
                case .none:
                    EmptyView()
                }
            }
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .allowsHitTesting(false)

            // Sample content — three cover-sized rectangles in the
            // theme's accent color.
            HStack(spacing: 8) {
                ForEach(0..<3, id: \.self) { _ in
                    RoundedRectangle(cornerRadius: 4)
                        .fill(theme.accent.opacity(0.85))
                        .frame(width: 50, height: 70)
                }
            }
        }
        .padding(.vertical, 4)
    }
}
