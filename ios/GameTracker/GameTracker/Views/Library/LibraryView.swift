import SwiftUI
import SwiftData

/// The Library tab: list/grid of games, search, sort, pull-to-refresh,
/// "+" button to add a game, tap to detail, swipe to delete.
struct LibraryView: View {

    enum ViewMode: String, CaseIterable, Identifiable {
        case list, grid
        var id: String { rawValue }
        var systemImage: String { self == .list ? "list.bullet" : "square.grid.2x2" }
    }

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context
    @Environment(\.theme) private var theme

    /// Reactive fetch — @Query re-runs whenever the underlying model
    /// container reports a change (including background sync writes),
    /// which a one-shot context.fetch in a computed property would not.
    /// Sort + search + platform-filter are applied in memory.
    @Query(filter: #Predicate<Game> { $0.syncStateRaw != "local_deleted" })
    private var allGames: [Game]

    @State private var search = ""
    @State private var sort: LibrarySortOption = .titleAsc
    @State private var viewMode: ViewMode = .list
    @State private var showAdd = false
    @State private var showConflicts = false
    @State private var showFilter = false
    @State private var platformFilter: Set<String> = []

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }
                SyncStatusBannerView(status: status)
                content
            }
            .navigationDestination(for: PersistentIdentifier.self) { id in
                GameDetailView(gameID: id,
                               imagesAPI: imagesAPI,
                               proxiesAPI: proxiesAPI,
                               syncTrigger: syncTrigger)
            }
            .navigationTitle("Library")
            .searchable(text: $search, prompt: "Search title or platform")
            .toolbar { toolbarContent }
            .sheet(isPresented: $showAdd) {
                AddGameView(imagesAPI: imagesAPI,
                            proxiesAPI: proxiesAPI,
                            syncTrigger: syncTrigger)
            }
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .sheet(isPresented: $showFilter) {
                PlatformFilterSheet(selected: $platformFilter)
            }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
        }
    }

    // MARK: - Content body, dispatched per view mode

    @ViewBuilder
    private var content: some View {
        let games = filteredGames
        if games.isEmpty {
            // Wrap the empty state in a List so pull-to-refresh has a
            // scroll context to attach to even when there's no data yet.
            List {
                ContentUnavailableView("No games", systemImage: "books.vertical",
                                       description: Text("Pull to sync, or tap + to add one."))
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            }
            .listStyle(.plain)
            .background {
                if theme.flourish == .codeRain {
                    CodeRainView()
                        .ignoresSafeArea()
                }
            }
        } else {
            switch viewMode {
            case .list:
                List {
                    ForEach(games) { g in
                        NavigationLink(value: g.persistentModelID) {
                            GameListRow(game: g, imagesAPI: imagesAPI)
                        }
                    }
                    .onDelete(perform: delete(at:))
                }
            case .grid:
                ScrollView {
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 110), spacing: 12)],
                              spacing: 12) {
                        ForEach(games) { g in
                            NavigationLink(value: g.persistentModelID) {
                                GameGridCell(game: g, imagesAPI: imagesAPI)
                                    .frame(height: 160)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(12)
                }
            }
        }
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .navigationBarTrailing) {
            Button { showAdd = true } label: { Image(systemName: "plus") }
        }
        ToolbarItem(placement: .navigationBarTrailing) {
            Menu {
                Picker("Sort", selection: $sort) {
                    ForEach(LibrarySortOption.allCases) { opt in
                        Text(opt.rawValue).tag(opt)
                    }
                }
                Picker("View", selection: $viewMode) {
                    ForEach(ViewMode.allCases) { mode in
                        Label(mode.rawValue.capitalized, systemImage: mode.systemImage).tag(mode)
                    }
                }
                Button { showFilter = true } label: {
                    Label("Filter platform…", systemImage: "line.3.horizontal.decrease.circle")
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
        }
    }

    // MARK: - Query + filtering

    /// Apply current sort + search + platform-filter to the @Query result.
    /// In-memory work; cheap even at a few thousand rows.
    private var filteredGames: [Game] {
        let sorted = allGames.sorted(using: sort.descriptor)
        return sorted.filter { g in
            if !platformFilter.isEmpty && !platformFilter.contains(g.platform) { return false }
            if search.isEmpty { return true }
            let s = search.lowercased()
            return g.title.lowercased().contains(s)
                || g.platform.lowercased().contains(s)
        }
    }

    // MARK: - Delete (swipe action)

    private func delete(at offsets: IndexSet) {
        let games = filteredGames
        for i in offsets {
            let g = games[i]
            // Locally-created rows that never synced: delete outright.
            if g.serverId == nil {
                context.delete(g)
            } else {
                g.syncState = .localDeleted
            }
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
    }
}
