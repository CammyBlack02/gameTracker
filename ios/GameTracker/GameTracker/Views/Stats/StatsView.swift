import SwiftUI
import SwiftData

struct StatsView: View {

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context
    @Environment(\.theme) private var theme

    @Query(filter: #Predicate<Game> { $0.syncStateRaw != "local_deleted" })
    private var allGames: [Game]

    @Query(filter: #Predicate<Item> { $0.syncStateRaw != "local_deleted" })
    private var allItems: [Item]

    @Query(filter: #Predicate<GameCompletion> { $0.syncStateRaw != "local_deleted" })
    private var allCompletions: [GameCompletion]

    @State private var showConflicts = false

    private var currentYear: Int {
        Calendar.current.component(.year, from: Date())
    }

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
            .navigationTitle("Stats")
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
            .overlay {
                if theme.flourish == .scanlines {
                    ScanlineOverlayView()
                        .ignoresSafeArea()
                }
            }
        }
    }

    @ViewBuilder
    private var content: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                kpiStrip
                completionsSection
                platformSection
                recentSection
            }
            .padding(.horizontal)
            .padding(.vertical, 16)
        }
    }

    // MARK: - Sections

    private var kpiStrip: some View {
        LazyVGrid(columns: [GridItem(.flexible(), spacing: 12),
                            GridItem(.flexible(), spacing: 12)],
                  spacing: 12) {
            KPICard(title: "Collection",
                    primary: "\(allGames.count + allItems.count)",
                    caption: collectionCaption)
            KPICard(title: "Completions",
                    primary: "\(allCompletions.count)",
                    caption: "\(completionsThisYear) this year")
            KPICard(title: "Total paid",
                    primary: formatGBP(totalPaid),
                    caption: nil)
            KPICard(title: "Estimated value",
                    primary: formatGBP(estimatedValue),
                    caption: "current market")
        }
    }

    private var completionsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Completions per year").font(.headline)
            CompletionsByYearChart(data: completionsByYear, currentYear: currentYear)
        }
    }

    private var platformSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Games per platform").font(.headline)
            GamesByPlatformChart(data: gamesByPlatform)
        }
    }

    @ViewBuilder
    private var recentSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Recently added").font(.headline)
            if recentGames.isEmpty {
                Text("No games added yet.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } else {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 12) {
                        ForEach(recentGames) { g in
                            NavigationLink(value: g.persistentModelID) {
                                GameGridCell(game: g, imagesAPI: imagesAPI)
                                    .frame(width: 110, height: 160)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Computed stats

    private var collectionCaption: String {
        let g = allGames.count
        let consoles = allItems.filter { ItemCategory.isConsole(rawString: $0.category) }.count
        let accessories = allItems.count - consoles
        return "\(g) games · \(consoles) consoles · \(accessories) accessories"
    }

    private var completionsThisYear: Int {
        allCompletions.filter { $0.completionYear == currentYear }.count
    }

    private var totalPaid: Double {
        let g = allGames.compactMap(\.pricePaid).reduce(0, +)
        let i = allItems.compactMap(\.pricePaid).reduce(0, +)
        return g + i
    }

    /// Per-row: prefer USD→GBP-converted pricecharting; fall back to
    /// `pricePaid`; if both nil, contribute 0.
    private var estimatedValue: Double {
        let g = allGames.reduce(0.0) { acc, x in
            acc + (x.pricechartingPrice.map(usdToGBP) ?? x.pricePaid ?? 0)
        }
        let i = allItems.reduce(0.0) { acc, x in
            acc + (x.pricechartingPrice.map(usdToGBP) ?? x.pricePaid ?? 0)
        }
        return g + i
    }

    private var completionsByYear: [(year: Int, count: Int)] {
        var byYear: [Int: Int] = [:]
        for c in allCompletions {
            if let y = c.completionYear { byYear[y, default: 0] += 1 }
        }
        return byYear.sorted { $0.key < $1.key }.map { (year: $0.key, count: $0.value) }
    }

    private var gamesByPlatform: [(platform: String, count: Int)] {
        var byPlatform: [String: Int] = [:]
        for g in allGames { byPlatform[g.platform, default: 0] += 1 }
        let sorted = byPlatform.sorted { $0.value > $1.value }
        let top = Array(sorted.prefix(8)).map { (platform: $0.key, count: $0.value) }
        let otherCount = sorted.dropFirst(8).reduce(0) { $0 + $1.value }
        return otherCount > 0
            ? top + [(platform: "Other", count: otherCount)]
            : top
    }

    private var recentGames: [Game] {
        allGames.sorted { $0.createdAt > $1.createdAt }.prefix(5).map { $0 }
    }
}
