# iOS Stats Tab Implementation Plan (Plan 3d)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `Stats` placeholder tab in `RootTabView` with a working read-only collection dashboard — 4 headline KPI cards, 2 Swift Charts visualisations (completions per year + games per platform), and a "recent additions" strip — plus a small currency fix to `GameDetailView` and `ItemDetailView` that ships the shared `Money.swift` utility everything uses.

**Architecture:** Pure UI work. All stats compute on-device from existing `@Query` results (`Game`, `Item`, `GameCompletion`) — no new networking, no new server endpoints, no schema changes. New views live under `Views/Stats/` and reuse the existing reactive-sync chrome (`ConflictBannerView`, `SyncStatusBannerView`).

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData (`@Model`, `@Query`), SwiftUI `Charts` framework (iOS 16+, fine for iOS 26.5 target). Existing `SyncEngine` / `SyncTrigger` / `ImagesAPI` / `ProxiesAPI` reused without modification.

**Predecessors:** Plans [3a](2026-05-21-ios-library-and-game-flows.md), [3b](2026-05-21-ios-completions-tab.md), [3c](2026-05-22-ios-items-tab.md). The spec for this plan lives at [`docs/superpowers/specs/2026-05-22-ios-stats-tab-design.md`](../specs/2026-05-22-ios-stats-tab-design.md).

**Execution rhythm:** Per-feature checkpoint pattern (memory: `feedback_per_feature_checkpoints`). One visible commit at the end of Task 8 (full tab wired + currency fixes applied), then a single user checkpoint covering both the new tab and the corrected detail-view labels.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-3d-stats-tab`, branched off `main` (Plan 3c merged at `98ae904`).
- **Pre-existing changes to leave alone in every commit:**
  - `js/completions.js` — old uncommitted whitespace edit.
  - `scripts/generate-thumbnails 2.php` + `tests/v2/*2.sh` — iCloud Drive conflict copies.
- **iCloud Drive Swift conflict files:** the repo's `.gitignore` covers `** [0-9].swift` siblings but copies still appear on disk. Clear before each test pass:

  ```bash
  find ios/GameTracker -name "* [0-9].swift" -print -delete
  ```

---

## What this plan does NOT build (Plan 3e+ territory)

- **Filters on Stats** — platform / physical / digital / year range.
- **Drill-down from charts** into filtered Library / Items / Completions views.
- **Time-played aggregation** from `GameCompletion.timeTaken` (free-form string).
- **Metacritic / star-rating averages** (sparse + unreliable data).
- **Live USD→GBP exchange-rate fetch** — constant is enough for v1.
- **Web parity for everything** in `api/stats.php` — top-5 lists, genre distribution, accessory-type breakdown all deferred.
- **Settings UI for the exchange rate.**
- **Image upload for games**, back-cover upload, polish bundle (sort menu / per-game "add completion") — all deferred from earlier plans.

---

## Server API surface this plan consumes

None. Every stat is computed from local SwiftData. No new endpoints, no new sync columns.

---

## File structure

### New iOS files

Under `ios/GameTracker/GameTracker/Views/Stats/`:

```
Stats/
├── StatsView.swift                  — main tab: @Query games/items/completions + KPI strip + charts + recents
├── KPICard.swift                    — small reusable card (title, big value, optional caption)
├── CompletionsByYearChart.swift     — Charts bar chart, current year highlighted
└── GamesByPlatformChart.swift       — Charts horizontal bar chart, top 8 + Other
```

Under `ios/GameTracker/GameTracker/Views/Common/`:

```
Common/
└── Money.swift                      — USD→GBP constant + formatter helpers
```

### Modified iOS files

- `Views/Tabs/RootTabView.swift` — replace the `Stats` `PlaceholderTabView` with `StatsView`.
- `Views/Detail/GameDetailView.swift` — relabel `Price paid` to `£` (no conversion); convert `Pricecharting` USD→GBP and relabel.
- `Views/Items/ItemDetailView.swift` — convert `Pricecharting value` USD→GBP, keep `£` label.

### Untouched

- Sync layer (`SyncEngine`, `PushBuilder`, `ChangeApplier`, `SyncAPI`, `DTOs.swift`) — no changes.
- Models (`Game`, `Item`, `GameCompletion`) — schema already supports everything.
- `LibraryView`, `ItemsView`, `CompletionsView`, `SettingsView` — Stats is purely additive.
- Server (`api/`) — no PHP edits.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-22-ios-stats-tab.md` (this file)

- [x] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current        # → plan-3d-stats-tab
git log --oneline -3              # spec + 3c merge
git status --short                # only pre-existing junk
```

Expected: branch is `plan-3d-stats-tab`; the design spec commit (`341537b`) sits on top of the 3c merge (`98ae904`).

- [x] **Step 0.2: Clear iCloud Swift conflict files**

```bash
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

Expected: prints any stragglers and deletes them, or prints nothing if clean.

- [x] **Step 0.3: Baseline test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -5
```

Expected: `** TEST SUCCEEDED **`.

- [x] **Step 0.4: Commit this plan doc**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add docs/superpowers/plans/2026-05-22-ios-stats-tab.md
git commit -m "Add Plan 3d (iOS Stats tab) implementation plan"
```

---

## Task 1: `Money` utility

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Common/Money.swift`

Shared, used by Tasks 2, 3, and 7. Two free functions plus a clearly-documented constant — no struct or class because the calls are stateless.

- [x] **Step 1.1: Write `Money.swift`**

```swift
import Foundation

/// Manual USD→GBP rate. Update as needed; promotable to a Settings
/// entry later if the user wants direct control. All call sites go
/// through `usdToGBP(_:)` so a refactor here propagates everywhere.
let USD_TO_GBP_RATE: Double = 0.78

/// Converts a USD amount into approximate GBP using the constant above.
/// No-op rounding; downstream formatter handles the display precision.
func usdToGBP(_ usd: Double) -> Double {
    usd * USD_TO_GBP_RATE
}

/// Formats a GBP amount as a localised currency string with £ prefix.
/// `1234.56` → `"£1,234.56"`. Falls back to `String(format:)` only if
/// the system formatter fails (extremely unlikely on iOS).
func formatGBP(_ amount: Double) -> String {
    let f = NumberFormatter()
    f.numberStyle = .currency
    f.currencyCode = "GBP"
    f.locale = Locale(identifier: "en_GB")
    return f.string(from: NSNumber(value: amount)) ?? String(format: "£%.2f", amount)
}
```

- [x] **Step 1.2: Build check**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`. (No commit yet — Tasks 1–8 ship as one bundle at the end of Task 8.)

---

## Task 2: Fix `GameDetailView` currency display

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift`

Two lines change. `pricePaid` is what the user entered (assumed £) — just relabel. `pricechartingPrice` is server-fetched USD — convert.

- [x] **Step 2.1: Edit `GameDetailView.swift`**

Find:

```swift
                    field("Price paid", game.pricePaid.map { String(format: "$%.2f", $0) })
                    field("Pricecharting", game.pricechartingPrice.map { String(format: "$%.2f", $0) })
```

Replace with:

```swift
                    field("Price paid", game.pricePaid.map { formatGBP($0) })
                    field("Pricecharting", game.pricechartingPrice.map { formatGBP(usdToGBP($0)) })
```

- [x] **Step 2.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 3: Fix `ItemDetailView` currency display

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Items/ItemDetailView.swift`

`pricePaid` here is already correctly labelled `£` (Plan 3c). Only `pricechartingPrice` needs converting.

- [x] **Step 3.1: Edit `ItemDetailView.swift`**

Find the `pricing` computed view's two `row(...)` lines:

```swift
    private var pricing: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Pricing").font(.headline)
            if let p = item?.pricePaid {
                row(label: "Price paid", value: "£\(format(p))")
            }
            if let p = item?.pricechartingPrice {
                row(label: "Pricecharting value", value: "£\(format(p))")
            }
        }
    }
```

Replace with:

```swift
    private var pricing: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Pricing").font(.headline)
            if let p = item?.pricePaid {
                row(label: "Price paid", value: formatGBP(p))
            }
            if let p = item?.pricechartingPrice {
                row(label: "Pricecharting value", value: formatGBP(usdToGBP(p)))
            }
        }
    }
```

(The local `format(_ value: Double) -> String` helper at the bottom of the file is no longer used by `pricing`. Leave it — it's small and may be called by future detail-view sections. Removing it can happen in a later cleanup.)

- [x] **Step 3.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 4: `KPICard`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Stats/KPICard.swift`

Reusable card view: header (small), big primary value, optional caption. Used by all four headline cards in the Stats KPI strip.

- [x] **Step 4.1: Write `KPICard.swift`**

```swift
import SwiftUI

/// One headline metric on the Stats tab. The optional caption renders
/// only when non-nil so cards without secondary detail don't reserve
/// extra height.
struct KPICard: View {
    let title: String
    let primary: String
    let caption: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(primary)
                .font(.title2.weight(.semibold))
                .lineLimit(1)
                .minimumScaleFactor(0.7)
            if let caption {
                Text(caption)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(Color.gray.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
    }
}
```

- [x] **Step 4.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 5: `CompletionsByYearChart`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Stats/CompletionsByYearChart.swift`

Bar chart of completions per year. Accepts pre-aggregated data so the chart itself stays dumb and testable. Current year rendered in `Color.accentColor`, prior years in `Color.gray`.

- [x] **Step 5.1: Write `CompletionsByYearChart.swift`**

```swift
import SwiftUI
import Charts

struct CompletionsByYearChart: View {

    /// Pre-aggregated `(year, count)` tuples, ascending by year.
    let data: [(year: Int, count: Int)]
    let currentYear: Int

    var body: some View {
        if data.isEmpty {
            ContentUnavailableView("No completions yet",
                                   systemImage: "chart.bar",
                                   description: Text("Log a completion to see your year-by-year progress."))
                .frame(height: 200)
        } else {
            Chart(data, id: \.year) { row in
                BarMark(
                    x: .value("Year", String(row.year)),
                    y: .value("Completions", row.count)
                )
                .foregroundStyle(row.year == currentYear ? Color.accentColor : Color.gray)
            }
            .chartYAxis {
                AxisMarks(position: .leading, values: .automatic(desiredCount: 4))
            }
            .frame(height: 200)
        }
    }
}
```

- [x] **Step 5.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 6: `GamesByPlatformChart`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Stats/GamesByPlatformChart.swift`

Horizontal bar chart of games per platform. Accepts pre-aggregated data already capped at top 8 + "Other". Height scales so few platforms doesn't waste space.

- [x] **Step 6.1: Write `GamesByPlatformChart.swift`**

```swift
import SwiftUI
import Charts

struct GamesByPlatformChart: View {

    /// Pre-aggregated `(platform, count)` tuples, descending by count,
    /// already capped at top 8 + `"Other"`.
    let data: [(platform: String, count: Int)]

    var body: some View {
        if data.isEmpty {
            ContentUnavailableView("No games yet",
                                   systemImage: "books.vertical",
                                   description: Text("Add a game to the library to see platform breakdown."))
                .frame(height: 200)
        } else {
            Chart(data, id: \.platform) { row in
                BarMark(
                    x: .value("Count", row.count),
                    y: .value("Platform", row.platform)
                )
            }
            .chartXAxis {
                AxisMarks(position: .bottom, values: .automatic(desiredCount: 4))
            }
            .frame(height: CGFloat(data.count) * 28 + 40)
        }
    }
}
```

- [x] **Step 6.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 7: `StatsView` — main tab

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Stats/StatsView.swift`

The biggest file in this plan. Holds three `@Query` properties (one per model), computed properties for each KPI and chart's data, and the body layout (KPI grid → completions chart → platform chart → recent additions). Navigation pushes `GameDetailView` for tile taps — mirrors `LibraryView`'s pattern.

- [x] **Step 7.1: Write `StatsView.swift`**

```swift
import SwiftUI
import SwiftData

struct StatsView: View {

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context

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
```

- [x] **Step 7.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 8: Wire `StatsView` into `RootTabView` + full test pass + bundle commit

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift`

`RootTabView` already holds `proxiesAPI` as a property, so plumbing it into `StatsView` is one extra argument on the swap.

- [x] **Step 8.1: Edit `RootTabView.swift`**

Find this block:

```swift
            PlaceholderTabView(title: "Stats",
                               systemImage: "chart.bar",
                               blurb: "Collection analytics.")
                .tabItem { Label("Stats", systemImage: "chart.bar") }
```

Replace with:

```swift
            StatsView(syncEngine: syncEngine,
                      syncTrigger: syncTrigger,
                      imagesAPI: imagesAPI,
                      proxiesAPI: proxiesAPI,
                      status: status)
                .tabItem { Label("Stats", systemImage: "chart.bar") }
```

- [x] **Step 8.2: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: `** TEST SUCCEEDED **`. (No new unit tests; existing suite still passes.)

- [x] **Step 8.3: Commit Tasks 1–8 together**

Six new files plus three modifications all ship in one commit (mirrors Plan 3c's bundling pattern):

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Common/Money.swift \
        ios/GameTracker/GameTracker/Views/Stats \
        ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift \
        ios/GameTracker/GameTracker/Views/Items/ItemDetailView.swift \
        ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift
git commit -m "Add Stats tab (replaces placeholder) with KPIs + Swift Charts; fix £/USD labels"
```

### 🛑 User checkpoint — Stats tab + currency fixes

Stop here. The owner should ⌘R in Xcode (iPhone 17 sim) and verify:

1. Tab bar still reads **Library / Items / Completions / Stats / Settings**. Stats no longer shows the placeholder "Coming soon" body.
2. Tapping Stats renders four KPI cards in a 2×2 grid:
   - **Collection** — total games + items number, plus a caption like `883 games · 7 consoles · 12 accessories`.
   - **Completions** — total number, plus `N this year`.
   - **Total paid** — a £ sum (matches your manual mental tally).
   - **Estimated value** — a £ sum, slightly different from Total paid where pricecharting data exists.
3. **Completions per year** chart renders bars for every year you've logged a completion. The current year is visually distinct (accent colour) vs prior years (gray).
4. **Games per platform** chart renders horizontal bars sorted by count. If you have more than 8 platforms, an "Other" bar appears at the bottom.
5. **Recently added** row shows 5 horizontally-scrollable game cells from the Library. Tap one → push `GameDetailView`.
6. **Currency fix on GameDetailView:** open a game with `pricePaid` set — the row reads `£X.XX`, not `$X.XX`. Open a game with `pricechartingPrice` set — the value is the USD figure × 0.78, labelled `£`.
7. **Currency fix on ItemDetailView:** open an item with `pricechartingPrice` set (e.g. PS Vita console) — the value is the USD figure × 0.78, labelled `£`.
8. Pull-to-refresh on the Stats tab triggers a sync.
9. No regression on Library / Items / Completions / Settings — every tab still loads, syncs, allows CRUD as before. Game covers + item covers still render correctly.

Resume the implementer queue only after the owner confirms or reports a specific failure.

---

## Task 9: Manual smoke pass (collapsed by default)

**Files:** none

The checkpoint above covers the usual flows. If the owner is comfortable folding the smoke pass into the checkpoint (Plan 3a / 3b / 3c precedent), this task is a no-op — confirm and move to Task 10. Otherwise walk this table.

- [x] **Step 9.1: Optional checklist (skip if checkpoint coverage was sufficient)**

| # | Action | Expected |
|---|---|---|
| 1 | Sign in with a multi-game account | Stats tab loads with non-zero numbers |
| 2 | Log a new completion on the Completions tab | Stats's Completions KPI + this-year caption + completions-per-year chart all update without re-launch |
| 3 | Add a new game with a `pricePaid` of `£20` | Stats's Total paid grows by £20 |
| 4 | Edit an item's `pricechartingPrice` | Stats's Estimated value reflects the change after sync |
| 5 | Open the web app | Stats info matches what the web shows where it works (totals, completions count) |
| 6 | Tap a recent-additions tile | GameDetailView pushes; pricePaid line reads `£`, pricecharting reads `£` |
| 7 | Open Library / Items / Completions / Settings | No regressions; all CRUD still works |

---

## Task 10: Push + open PR + wrap up

**Files:** none

- [x] **Step 10.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk (`js/completions.js`, iCloud `.sh`/`.php` conflict copies).

- [x] **Step 10.2: Push**

```bash
git push -u origin plan-3d-stats-tab
```

- [x] **Step 10.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-22-ios-stats-tab.md
git add docs/superpowers/plans/2026-05-22-ios-stats-tab.md
git commit -m "Mark Plan 3d (iOS Stats tab) complete"
git push
```

- [x] **Step 10.4: Open PR**

```bash
gh pr create --base main --head plan-3d-stats-tab \
  --title "Plan 3d: iOS Stats tab" \
  --body "$(cat <<'EOF'
## Summary

Replaces the **Stats** placeholder tab with a working on-device dashboard:

- **4 headline KPI cards** in a 2×2 grid: Collection (games + items, with consoles/accessories caption), Completions (total + this year), Total paid (£), Estimated value (£, USD→GBP-converted pricecharting where set with `pricePaid` fallback).
- **Completions per year** bar chart (SwiftUI Charts), current year highlighted.
- **Games per platform** horizontal bar chart, top 8 + Other.
- **Recently added** horizontal scroll of the last 5 games; tap → `GameDetailView`.
- All computed on-device from existing `@Query` results — no new networking.

Tab bar after this PR: **Library / Items / Completions / Stats / Settings** — all five functional for the first time.

## Currency fix

Folded in alongside the Stats tab so the new utility ships once and labelling is consistent everywhere:

- `Money.swift` — `USD_TO_GBP_RATE` constant, `usdToGBP(_:)`, `formatGBP(_:)` (locale-aware £).
- `GameDetailView` — `pricePaid` now labelled £ (no conversion; user input was always £), `pricechartingPrice` converted USD→GBP and labelled £.
- `ItemDetailView` — `pricechartingPrice` converted USD→GBP, label was already £.

## Test Plan

- [x] `xcodebuild test` on iPhone 17 sim — full suite still passes
- [x] Manual checkpoint against the live server: every KPI, both charts, recent additions tap-to-detail, GameDetailView + ItemDetailView currency labels
- [x] No regression on Library / Items / Completions / Settings tabs

## Not in scope (Plan 3e+ territory)

Filters on Stats, drill-down from charts, time-played aggregation from `timeTaken`, Metacritic/star-rating averages, live USD→GBP rate fetch, full web-stats parity (top-5 lists, genre distribution, accessory-type breakdown), Settings UI for the exchange rate, image upload for games, back-cover upload, polish bundle (sort menu / per-game "add completion").

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [x] Every referenced symbol exists: `Game`, `Item`, `GameCompletion`, `SyncEngine`, `SyncTrigger`, `ImagesAPI`, `ProxiesAPI`, `SyncStatus`, `ConflictBannerView`, `ConflictListView`, `SyncStatusBannerView`, `GameDetailView`, `GameGridCell`, `ItemCategory`. (All landed via Plan 2 / 3a / 3b / 3c.)
- [x] No file is referenced by two different names across tasks.
- [x] The `Money.swift` utility's `usdToGBP` + `formatGBP` are called identically in Tasks 2, 3, and 7. No name drift.
- [x] `KPICard` accepts `title: String, primary: String, caption: String?` consistently — `StatsView`'s four call sites in Task 7 use these names.
- [x] `CompletionsByYearChart` accepts `data: [(year: Int, count: Int)], currentYear: Int` — matches the `completionsByYear` computed property's tuple shape in `StatsView`.
- [x] `GamesByPlatformChart` accepts `data: [(platform: String, count: Int)]` — matches the `gamesByPlatform` computed property in `StatsView`.
- [x] All commit messages cover the visible behaviour and bundle interdependent files together (matches Plan 3c's style).
- [x] No "TBD" or "implement later" left anywhere.
