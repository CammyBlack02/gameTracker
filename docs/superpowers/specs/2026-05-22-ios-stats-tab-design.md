# iOS Stats Tab — Design (Plan 3d)

**Date:** 2026-05-22
**Status:** Design approved, awaiting implementation plan
**Author:** Cameron (with Claude)
**Predecessors:** [2026-05-15-ios-app-design.md](2026-05-15-ios-app-design.md), Plans 3a (Library), 3b (Completions), 3c (Items).

## Overview

Replace the **Stats** placeholder tab in `RootTabView` with a lean, on-device dashboard that aggregates the collection data already living in local SwiftData — games, items, and completions — into four headline KPIs, two Charts-framework visualisations, and a "recent additions" strip. All computation is local; no new networking, no new server endpoints, no schema changes.

Plan 3d also folds in a small **currency fix** to `GameDetailView` and `ItemDetailView` so the £/$ display becomes correct everywhere, sharing the same utility the Stats KPIs will use.

Tab bar after Plan 3d: **Library / Items / Completions / Stats / Settings** — all five tabs functional for the first time.

## Goals

- Surface the most useful collection stats in one read-only screen.
- Be reactive: stats refresh automatically as sync brings in new data or the user logs a completion (via SwiftData `@Query`).
- Display all monetary values in **£**, with USD→GBP conversion applied wherever the underlying column is in USD (`pricechartingPrice`).
- Lay foundations that future stats / filters / drill-downs can hang off without restructuring.

## Non-goals (out of scope for Plan 3d)

- **Filters** (platform / physical / digital / year range). Lean MVP renders unfiltered totals; filtering arrives in a follow-up plan if needed.
- **Drill-down from charts** into filtered Library / Items / Completions views. Bars and KPIs are not tappable in v1.
- **Time-played aggregation** from `GameCompletion.timeTaken` — the field is a free-form string (`"12h"`, `"20h 30m"`); parsing it is fragile and out of v1 scope.
- **Metacritic / star-rating averages.** Source data is sparse and unreliable on this account (Metacritic fetch on the web app doesn't run consistently); aggregating it would silently average over a tiny sample.
- **Live USD→GBP exchange-rate fetch.** Constant rate hardcoded in `Money.swift` for v1; promotable to Settings later.
- **Web parity for everything** — explicitly **not** mirroring every endpoint in `api/stats.php`. The web's "top 5 games / consoles / accessories" lists, accessory-type breakdown, and genre distribution are deferred.
- **Stats for sync / cache / settings concerns** (last sync time, image cache size, etc.). Separate concern from collection stats.

## Key Decisions (from brainstorming Q&A)

| Decision | Choice |
|---|---|
| Scope size | **Lean MVP** — 4 headline KPIs + 2 charts + recent additions, ~1 day |
| Data source | **Local SwiftData via `@Query`** (no new networking) |
| Currency display | **£ everywhere**; USD values converted via `Money.swift` constant |
| USD→GBP rate | **Hardcoded constant** (manual update; promote to Settings later) |
| Charts framework | **SwiftUI Charts** (iOS 16+, fine for our iOS 26.5 target) |
| KPI layout | **2×2 grid** of cards in the top section |
| Year highlighting | **Current year visually distinct** in the completions-per-year chart |
| Platform chart truncation | **Top 8 + "Other"** to keep the bar chart legible |
| Recent additions | **Last 5 games** by `createdAt` desc; horizontal scroll of `GameGridCell`s |
| Drill-down navigation | **Recent-additions tiles only** — push `GameDetailView`. Bars / KPIs not tappable. |
| Out-of-scope ratings | **No Metacritic / star averages** in v1 (data unreliable) |
| `pricechartingPrice` semantics | **Convert USD → GBP** wherever displayed; **`pricePaid` is already £**, no conversion |

---

## Section 1: High-level shape

```
RootTabView
└── (tab #4) StatsView                  ← replaces PlaceholderTabView("Stats")
    ├── ScrollView (vertical)
    │   ├── KPI strip                    (4 cards, 2×2)
    │   │   ├── Collection size
    │   │   ├── Completions
    │   │   ├── Total paid (£)
    │   │   └── Estimated value (£)
    │   ├── CompletionsByYearChart       (Swift Charts bar, current year highlighted)
    │   ├── GamesByPlatformChart         (Swift Charts bar, top 8 + Other)
    │   └── Recent additions             (horizontal scroll → GameDetailView)
    └── refreshable { syncEngine.runOnce() }
```

All sync, error, and conflict UX is reused — the existing `ConflictBannerView` and `SyncStatusBannerView` sit above the stats content the same way they do on Library / Items / Completions.

## Section 2: File structure

### New iOS files (under `ios/GameTracker/GameTracker/Views/Stats/`)

```
Stats/
├── StatsView.swift                    — main tab: @Query games/items/completions, layout
├── KPICard.swift                      — small reusable card (title, big value, caption)
├── CompletionsByYearChart.swift       — Swift Charts bar of completions grouped by year
└── GamesByPlatformChart.swift         — Swift Charts bar of games grouped by platform
```

### New iOS file (under `ios/GameTracker/GameTracker/Views/Common/`)

```
Common/
└── Money.swift                        — USD→GBP constant + formatter helpers
```

### Modified iOS files

- `Views/Tabs/RootTabView.swift` — replace the Stats `PlaceholderTabView` with `StatsView`.
- `Views/Detail/GameDetailView.swift` — fix `pricePaid` label (`$` → `£`, no conversion) and `pricechartingPrice` (apply USD→GBP, label `£`).
- `Views/Items/ItemDetailView.swift` — fix `pricechartingPrice` (apply USD→GBP, keep `£` label). `pricePaid` is already correctly labelled in this view.

### Untouched

- Sync layer (`SyncEngine`, `PushBuilder`, `ChangeApplier`, `SyncAPI`, `DTOs.swift`) — no new round-trips.
- Models (`Game`, `Item`, `GameCompletion`) — schema already carries every field needed.
- `LibraryView`, `ItemsView`, `CompletionsView`, `SettingsView` — Stats is purely additive.

---

## Section 3: Component specifications

### 3.1 `Money` utility

```swift
// Money.swift

/// Manual USD→GBP rate. Update as needed; promote to Settings later.
let USD_TO_GBP_RATE: Double = 0.78

func usdToGBP(_ usd: Double) -> Double { usd * USD_TO_GBP_RATE }

/// Formats a GBP amount as a localised currency string with £ prefix.
/// `1234.56` → `"£1,234.56"`.
func formatGBP(_ amount: Double) -> String {
    let f = NumberFormatter()
    f.numberStyle = .currency
    f.currencyCode = "GBP"
    f.locale = Locale(identifier: "en_GB")
    return f.string(from: NSNumber(value: amount)) ?? String(format: "£%.2f", amount)
}
```

Two free functions, no struct — these are stateless utilities used across views. If a future feature needs an instance-bound currency formatter (e.g. user-selectable locale), can refactor then.

### 3.2 `KPICard`

```swift
// KPICard.swift

struct KPICard: View {
    let title: String
    let primary: String
    let caption: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title).font(.caption).foregroundStyle(.secondary)
            Text(primary).font(.title2.weight(.semibold)).lineLimit(1).minimumScaleFactor(0.7)
            if let caption {
                Text(caption).font(.caption2).foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(Color.gray.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
    }
}
```

Reusable for all four headline cards. Caption is optional so cards without secondary info don't render extra whitespace.

### 3.3 `CompletionsByYearChart`

```swift
// CompletionsByYearChart.swift

import Charts

struct CompletionsByYearChart: View {
    /// Tuples already aggregated by the parent: `(year, count)` ascending.
    let data: [(year: Int, count: Int)]
    let currentYear: Int

    var body: some View {
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
```

The chart accepts pre-aggregated data so testing it visually doesn't require setting up `@Query`. Aggregation happens in `StatsView`.

### 3.4 `GamesByPlatformChart`

```swift
// GamesByPlatformChart.swift

import Charts

struct GamesByPlatformChart: View {
    /// Tuples already aggregated and capped at top 8 + "Other".
    let data: [(platform: String, count: Int)]

    var body: some View {
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
```

Horizontal bars (platform names can be long; reading left-to-right is friendlier than rotated labels). Height scales so 8 platforms gets a sensible chart and fewer don't waste vertical space.

### 3.5 `StatsView` (main tab)

Top-level body:

```swift
// StatsView.swift

import SwiftUI
import SwiftData

struct StatsView: View {

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context

    @Query(filter: #Predicate<Game> { $0.syncStateRaw != "local_deleted" })
    private var allGames: [Game]

    @Query(filter: #Predicate<Item> { $0.syncStateRaw != "local_deleted" })
    private var allItems: [Item]

    @Query(filter: #Predicate<GameCompletion> { $0.syncStateRaw != "local_deleted" })
    private var allCompletions: [GameCompletion]

    @State private var showConflicts = false

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
                               proxiesAPI: proxiesAPI,  // see note below
                               syncTrigger: syncTrigger)
            }
            .navigationTitle("Stats")
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
        }
    }

    // computed properties: kpis(), completionsByYear, gamesByPlatform, recentGames
    // (full details in the implementation plan)
}
```

### Note on `proxiesAPI` for `GameDetailView`

`GameDetailView` currently requires a `proxiesAPI: ProxiesAPI` parameter (used by its own logic; not for Stats display). `StatsView` therefore needs to receive `proxiesAPI` too if it wants to push to `GameDetailView`. Either:

- Add `proxiesAPI: ProxiesAPI` as an `StatsView` parameter (plumbed from `RootTabView`, which already holds it), OR
- Skip the navigation destination — recent-addition tiles become non-tappable in v1.

**Decision (plan-writing-time):** add `proxiesAPI` to `StatsView`. Tile tap-to-detail is small UX value and zero risk; the wiring is one extra property.

---

## Section 4: Stats computation

### 4.1 KPI: Collection size

```
games = allGames.count
items = allItems.count
consoles = allItems.filter { ItemCategory.isConsole(rawString: $0.category) }.count
accessories = items - consoles

primary = "\(games + items)"
caption = "\(games) games · \(consoles) consoles · \(accessories) accessories"
```

### 4.2 KPI: Completions

```
total = allCompletions.count
thisYear = allCompletions.filter { $0.completionYear == currentYear }.count

primary = "\(total)"
caption = "\(thisYear) this year"
```

### 4.3 KPI: Total paid (£)

```
gamesPaid = allGames.compactMap(\.pricePaid).reduce(0, +)
itemsPaid = allItems.compactMap(\.pricePaid).reduce(0, +)

primary = formatGBP(gamesPaid + itemsPaid)
caption = nil
```

Items pricePaid is already £; games pricePaid is also assumed £ (user input, same convention). No conversion.

### 4.4 KPI: Estimated value (£)

```
func valueOf<T>(games: [Game], items: [Item]) -> Double {
    let g = allGames.reduce(0.0) { acc, x in
        acc + (x.pricechartingPrice.map(usdToGBP) ?? x.pricePaid ?? 0)
    }
    let i = allItems.reduce(0.0) { acc, x in
        acc + (x.pricechartingPrice.map(usdToGBP) ?? x.pricePaid ?? 0)
    }
    return g + i
}

primary = formatGBP(estimatedValue)
caption = "current market"
```

Per-row fallback: prefer the USD→GBP converted pricecharting; if absent, fall back to what was paid; if both absent, contribute 0. This means an item missing both prices simply doesn't add to the headline (rather than skewing it down to zero) and matches the user's intent of "best-effort estimate".

### 4.5 `completionsByYear`

```
var byYear: [Int: Int] = [:]
for c in allCompletions {
    if let y = c.completionYear { byYear[y, default: 0] += 1 }
}
let sorted = byYear.sorted { $0.key < $1.key }
let data: [(year: Int, count: Int)] = sorted.map { ($0.key, $0.value) }
```

Empty when no completions logged → render an empty-state hint inside the chart's section (`ContentUnavailableView` style).

### 4.6 `gamesByPlatform` (top 8 + Other)

```
var byPlatform: [String: Int] = [:]
for g in allGames { byPlatform[g.platform, default: 0] += 1 }
let sorted = byPlatform.sorted { $0.value > $1.value }
let top = Array(sorted.prefix(8)).map { (platform: $0.key, count: $0.value) }
let otherCount = sorted.dropFirst(8).reduce(0) { $0 + $1.value }
let data = otherCount > 0 ? top + [(platform: "Other", count: otherCount)] : top
```

### 4.7 `recentGames`

```
allGames.sorted { $0.createdAt > $1.createdAt }.prefix(5)
```

Rendered as a horizontal scroll of `GameGridCell` instances (reused from `Views/Library/GameGridCell.swift`).

---

## Section 5: Currency-fix changes outside the Stats tab

### `GameDetailView.swift`

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

### `ItemDetailView.swift`

In the `pricing` computed view, change:

```swift
if let p = item?.pricechartingPrice {
    row(label: "Pricecharting value", value: "£\(format(p))")
}
```

to:

```swift
if let p = item?.pricechartingPrice {
    row(label: "Pricecharting value", value: formatGBP(usdToGBP(p)))
}
```

(`pricePaid` in `ItemDetailView` is already correctly labelled `£` and doesn't need conversion. The local `format(_:)` helper can stay for non-currency formatting if it's used elsewhere; for currency, the calls switch to `formatGBP`.)

---

## Section 6: Testing

**Unit tests** — none net-new required for view code. Optional small additions worth doing if scope allows:
- `MoneyTests.swift` — round-trip USD→GBP rate, format edge cases (zero, negative-not-expected, large values).
- `StatsAggregationTests.swift` — if the per-section computations get extracted into pure functions, test them with synthetic Game/Item/GameCompletion arrays.

For v1, the lean recommendation is **skip unit tests** for the view layer and only add a `MoneyTests.swift` if the implementer wants high confidence in the currency math. The aggregations are simple enough that a checkpoint walkthrough catches errors.

**Manual checkpoint** — one walkthrough after the tab is wired:

1. Tab bar shows **Library / Items / Completions / Stats / Settings**. Tap Stats. (No more placeholder.)
2. KPI strip renders 4 cards with sensible values (collection size matches what Library/Items show; completions count matches Completions tab).
3. KPI: **Total paid** shows a £ amount that's plausible (sum of all `pricePaid` you've ever entered).
4. KPI: **Estimated value** shows a £ amount that's plausible (sum of pricecharting where set, falling back to paid).
5. **Completions per year** chart renders bars for every year you have completions in. Current year is visually distinct.
6. **Games per platform** chart renders horizontal bars. If you have more than 8 platforms, an "Other" bar appears at the bottom.
7. **Recent additions** row shows 5 of your most recently added games (Library's grid cells).
8. Tap a tile in Recent additions → `GameDetailView` pushes.
9. Pull-to-refresh on the Stats tab triggers a sync.
10. **GameDetailView fixes:** open any game with a `pricePaid` set — label reads `£X.XX`, not `$X.XX`. Open any game with a `pricechartingPrice` set — value is the USD figure × 0.78, labelled `£`.
11. **ItemDetailView fixes:** open an item (e.g. PS Vita console) with `pricechartingPrice` set — value is the USD figure × 0.78, labelled `£`.
12. No regression on Library / Items / Completions / Settings.

---

## Section 7: Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| Swift Charts not available pre-iOS 16 | Low | Project targets iOS 26.5; Charts has been available since iOS 16. N/A in practice. |
| `@Query`ing all games/items/completions every render hurts performance at scale | Low | SwiftData uses lazy snapshots; at 883 games + N items + M completions, in-memory `reduce` and `Dictionary` builds are sub-millisecond. Profile if it ever becomes a problem. |
| USD→GBP constant drifts from reality | Low | Documented as approximate in the file's doc-comment. A future Settings entry can expose the rate to the user; the call sites already go through one helper so a refactor is mechanical. |
| Currency-fix regression breaks game/item display | Medium | Manual checkpoint step #10/#11 covers both; the change is mechanical (label + optional conversion) and limited to two view files. |
| KPIs feel wrong because a row has `pricecharting` in USD AND `pricePaid` in GBP, and we pick pricecharting | Medium | This is the user's stated preference ("convert and show £"). Adding an explicit "current market" caption on the Estimated-value KPI flags the difference. |
| Empty state for a fresh account with no completions yet | Low | `CompletionsByYearChart` renders an empty-state hint when `data.isEmpty`. KPIs all show 0 cleanly. |

---

## Open questions

None outstanding. All design choices captured above; remaining decisions (e.g. exact pbxproj wiring) are plan-writing-time concerns.
