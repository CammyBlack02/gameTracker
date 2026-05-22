# iOS Completions Tab Implementation Plan (Plan 3b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Spin placeholder tab in `RootTabView` with a real **Completions** tab — a flat, chronological log of every `GameCompletion` row across the user's library, with searchable add/edit/delete on the phone. The user can finally manage their completion log from iOS instead of the web app.

**Architecture:** Pure UI work. `GameCompletion` already exists as a `@Model`; `PushBuilder` and `ChangeApplier` already round-trip it through `/sync/changes` and `/sync/push`. Mirror the `LibraryView` shape: a `@Query`-backed reactive list with search, swipe-to-delete, `+` to add, tap to edit. The add/edit form embeds a `GamePickerSheet` so the user can attach a completion to any game in their library — important because the 883-game test account needs a searchable picker rather than a long static list.

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData (`@Model`, `@Query`), `SyncTrigger` (Plan 3a). No new networking. No server changes.

**Predecessor:** [2026-05-21-ios-library-and-game-flows.md](2026-05-21-ios-library-and-game-flows.md). Plan 3a's `RootTabView`, `SyncTrigger`, `CoverImage`, and `ConflictBannerView` are reused as-is.

**Execution rhythm:** Same per-feature checkpoint pattern as Plan 3a — pause the implementer queue after every commit that exposes new visible behaviour (marked **🛑 User checkpoint** below). Owner ⌘R's the sim, walks through the checks, confirms, then implementation resumes.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Start each task on a NEW branch `plan-3b-completions-tab`, branched off `main` (Plan 3a is now merged into main).
- **Pre-existing change:** `js/completions.js` has an old uncommitted whitespace edit. LEAVE ALONE in every commit.
- **iCloud Drive conflict files:** the repo's `.gitignore` now ignores `** [0-9].swift` siblings. If `xcodebuild` ever errors with "invalid redeclaration", check `find ios/GameTracker -name "* 2.swift"` and delete any stragglers before continuing.

---

## What this plan does NOT build (Plan 3c+ territory)

- **Items tab** — same shape as Library but for consoles/accessories. Separate plan.
- **Stats tab** — Swift Charts dashboards.
- **Settings appearance/theme + clear-image-cache.** Sign-out already shipped; the rest can wait.
- **Per-game "Add completion for this game" button** on `GameDetailView` (would pre-fill the game picker). Easy add later but not in v1.
- **Editing the existing read-only completion list** that lives in `GameDetailView`. We keep that as-is; tapping a row there does not navigate to the edit sheet (yet).
- **Filtering completions by year.** Search by title/notes substring is enough for v1.
- **`completion_year` field.** We populate it automatically from `dateCompleted` (so the web app's by-year groupings keep working), but the UI doesn't surface it as an editable field.
- **`dateStarted` field.** Not editable in v1; preserved when editing a synced row but not added through this UI.

---

## Server API surface this plan consumes (already deployed)

| Endpoint | Purpose |
|---|---|
| `GET /api/v2/sync/changes.php?since=…` | pulls `game_completions` rows that changed since `since` (already wired by `SyncEngine`) |
| `POST /api/v2/sync/push.php` | pushes locally-new / modified / deleted completions (already wired by `PushBuilder`) |

No new endpoints. No server-side changes.

---

## File structure

### New files (all under `ios/GameTracker/GameTracker/Views/Completions/`)

```
Completions/
├── CompletionsView.swift          — main tab: @Query list, search, +, sync, swipe-delete
├── CompletionsListRow.swift       — single row: cover thumb + title + date + time + notes preview
├── AddCompletionView.swift        — form sheet for a new completion
├── EditCompletionView.swift       — form sheet for an existing completion
├── CompletionFormBody.swift       — shared Form body used by Add + Edit
└── GamePickerSheet.swift          — searchable list of the user's games, returns a Game
```

### Modified

- `GameTracker/Views/Tabs/RootTabView.swift` — replace the `Spin` `PlaceholderTabView` with `CompletionsView`.

### Untouched

- `RootView.swift`, `GameTrackerApp.swift` — `CompletionsView` only needs `imagesAPI` + `syncEngine` + `syncTrigger` + `status`, all of which `RootTabView` already has as properties.

---

## Task 0: Branch + commit plan doc

**Files:** none

- [ ] **Step 0.1: Confirm main is current and tests pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git checkout main
git pull --ff-only
git status --short                                # only js/completions.js should appear
cd ios/GameTracker
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 | tail -3
```

Expected: `** TEST SUCCEEDED **`. (Plan 3a left us at 54 passing tests.)

- [ ] **Step 0.2: Create the branch**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git checkout -b plan-3b-completions-tab
git branch --show-current
```

Expected: `plan-3b-completions-tab`.

- [ ] **Step 0.3: Commit this plan doc on the new branch**

```bash
git add docs/superpowers/plans/2026-05-21-ios-completions-tab.md
git commit -m "Add Plan 3b (iOS completions tab) implementation plan"
```

---

## Task 1: `GamePickerSheet`

**Files:**
- Create: `GameTracker/Views/Completions/GamePickerSheet.swift`

A modal `NavigationStack` showing a searchable list of every non-deleted `Game` in the local store. Tapping a row calls the supplied `onPick` closure with the selected `Game` and dismisses the sheet. Reused by both the Add and Edit completion forms.

- [ ] **Step 1.1: Write `GamePickerSheet.swift`**

```swift
import SwiftUI
import SwiftData

/// Modal list of every non-deleted Game in the local store, searchable
/// by title or platform. Returns the chosen game via `onPick`.
struct GamePickerSheet: View {
    let onPick: (Game) -> Void
    let imagesAPI: ImagesAPI

    @Environment(\.dismiss) private var dismiss

    @Query(
        filter: #Predicate<Game> { $0.syncStateRaw != "local_deleted" },
        sort: \Game.title,
        order: .forward
    ) private var allGames: [Game]

    @State private var search = ""

    private var filtered: [Game] {
        guard !search.isEmpty else { return allGames }
        let s = search.lowercased()
        return allGames.filter {
            $0.title.lowercased().contains(s) || $0.platform.lowercased().contains(s)
        }
    }

    var body: some View {
        NavigationStack {
            List(filtered) { game in
                Button {
                    onPick(game)
                    dismiss()
                } label: {
                    HStack(spacing: 12) {
                        CoverImage(gameServerId: game.serverId, face: .front, size: .thumb, api: imagesAPI)
                            .frame(width: 36, height: 54)
                            .clipShape(RoundedRectangle(cornerRadius: 4))
                        VStack(alignment: .leading, spacing: 2) {
                            Text(game.title)
                                .font(.body.weight(.medium))
                                .lineLimit(2)
                            Text(game.platform)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                    }
                }
                .buttonStyle(.plain)
            }
            .listStyle(.plain)
            .searchable(text: $search, prompt: "Search title or platform")
            .navigationTitle("Pick a game")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
    }
}
```

- [ ] **Step 1.2: Build check**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```

Expected: `** BUILD SUCCEEDED **`.

(No commit yet — Tasks 1-6 are interdependent and ship together at the end of Task 7. This matches Plan 3a's Tasks 4-8 grouping.)

---

## Task 2: `CompletionFormBody`

**Files:**
- Create: `GameTracker/Views/Completions/CompletionFormBody.swift`

Shared `Form` content used by both Add and Edit views. Holds the bindable fields, the game picker trigger, and the date/time/notes editors. Keeping it in one place means Add/Edit can never drift apart.

- [ ] **Step 2.1: Write `CompletionFormBody.swift`**

The body wraps its three `Section`s in a `Group` so the `.sheet` modifier (used for the game picker) attaches cleanly to the whole body rather than to a single section. The picker's presentation state is private to this view.

```swift
import SwiftUI

/// Shared form fields for Add/Edit completion. The owning view supplies
/// bindings plus an `imagesAPI` for the picker's cover thumbs.
struct CompletionFormBody: View {
    @Binding var pickedGame: Game?
    @Binding var dateCompleted: Date
    @Binding var hasDate: Bool
    @Binding var timeTaken: String
    @Binding var notes: String
    let imagesAPI: ImagesAPI

    @State private var showGamePicker = false

    var body: some View {
        Group {
            Section("Game") {
                Button {
                    showGamePicker = true
                } label: {
                    HStack {
                        if let g = pickedGame {
                            CoverImage(gameServerId: g.serverId, face: .front, size: .thumb, api: imagesAPI)
                                .frame(width: 32, height: 48)
                                .clipShape(RoundedRectangle(cornerRadius: 4))
                            VStack(alignment: .leading, spacing: 2) {
                                Text(g.title).font(.body.weight(.medium)).lineLimit(2)
                                Text(g.platform).font(.caption).foregroundStyle(.secondary)
                            }
                        } else {
                            Image(systemName: "gamecontroller").foregroundStyle(.secondary)
                            Text("Choose a game…").foregroundStyle(.secondary)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }

            Section("When") {
                Toggle("Set a completion date", isOn: $hasDate)
                if hasDate {
                    DatePicker("Completed",
                               selection: $dateCompleted,
                               displayedComponents: .date)
                }
            }

            Section("Details") {
                TextField("Time taken (e.g. 20h 30m)", text: $timeTaken)
                TextField("Notes", text: $notes, axis: .vertical).lineLimit(3...10)
            }
        }
        .sheet(isPresented: $showGamePicker) {
            GamePickerSheet(onPick: { pickedGame = $0 }, imagesAPI: imagesAPI)
        }
    }
}
```

- [ ] **Step 2.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 3: `AddCompletionView`

**Files:**
- Create: `GameTracker/Views/Completions/AddCompletionView.swift`

Sheet that creates a new `GameCompletion` with `syncState = .localNew`, links it to a chosen game's `serverId` (or `nil` if the game itself is still local), and triggers a debounced sync.

- [ ] **Step 3.1: Write `AddCompletionView.swift`**

```swift
import SwiftUI
import SwiftData

struct AddCompletionView: View {
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var pickedGame: Game?
    @State private var dateCompleted: Date = Date()
    @State private var hasDate: Bool = true
    @State private var timeTaken: String = ""
    @State private var notes: String = ""

    private var canSave: Bool { pickedGame != nil }

    var body: some View {
        NavigationStack {
            Form {
                CompletionFormBody(pickedGame: $pickedGame,
                                   dateCompleted: $dateCompleted,
                                   hasDate: $hasDate,
                                   timeTaken: $timeTaken,
                                   notes: $notes,
                                   imagesAPI: imagesAPI)
            }
            .navigationTitle("Log a completion")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(!canSave)
                }
            }
        }
    }

    private func save() {
        guard let game = pickedGame else { return }
        let c = GameCompletion(title: game.title, syncState: .localNew)
        c.gameServerId   = game.serverId
        c.platform       = game.platform
        c.dateCompleted  = hasDate ? dateCompleted : nil
        // Keep completion_year in sync with dateCompleted so the web
        // app's by-year groupings still work without separate UI.
        c.completionYear = hasDate ? Calendar.current.component(.year, from: dateCompleted) : nil
        c.timeTaken      = timeTaken.isEmpty ? nil : timeTaken
        c.notes          = notes.isEmpty ? nil : notes
        context.insert(c)
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
```

- [ ] **Step 3.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 4: `EditCompletionView`

**Files:**
- Create: `GameTracker/Views/Completions/EditCompletionView.swift`

Mirrors `AddCompletionView` but binds against an existing `GameCompletion` identified by `PersistentIdentifier`. On save, marks the row `.localModified` (if it was `.synced`) and pings sync.

- [ ] **Step 4.1: Write `EditCompletionView.swift`**

```swift
import SwiftUI
import SwiftData

struct EditCompletionView: View {
    let completionID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var pickedGame: Game?
    @State private var dateCompleted: Date = Date()
    @State private var hasDate: Bool = false
    @State private var timeTaken: String = ""
    @State private var notes: String = ""
    @State private var loaded = false

    private var canSave: Bool { pickedGame != nil }

    var body: some View {
        NavigationStack {
            Form {
                CompletionFormBody(pickedGame: $pickedGame,
                                   dateCompleted: $dateCompleted,
                                   hasDate: $hasDate,
                                   timeTaken: $timeTaken,
                                   notes: $notes,
                                   imagesAPI: imagesAPI)
            }
            .navigationTitle("Edit completion")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(!canSave)
                }
            }
            .task { loadOnce() }
        }
    }

    private func loadOnce() {
        guard !loaded, let c: GameCompletion = context.model(for: completionID) as? GameCompletion else { return }
        // Resolve the linked game (if any) by serverId so the picker shows it.
        if let sid = c.gameServerId {
            let p = #Predicate<Game> { $0.serverId == sid }
            pickedGame = (try? context.fetch(FetchDescriptor(predicate: p)))?.first
        }
        if let d = c.dateCompleted {
            dateCompleted = d
            hasDate = true
        } else {
            hasDate = false
        }
        timeTaken = c.timeTaken ?? ""
        notes     = c.notes ?? ""
        loaded = true
    }

    private func save() {
        guard let c: GameCompletion = context.model(for: completionID) as? GameCompletion,
              let game = pickedGame else { return }
        c.title          = game.title
        c.platform       = game.platform
        c.gameServerId   = game.serverId
        c.dateCompleted  = hasDate ? dateCompleted : nil
        c.completionYear = hasDate ? Calendar.current.component(.year, from: dateCompleted) : nil
        c.timeTaken      = timeTaken.isEmpty ? nil : timeTaken
        c.notes          = notes.isEmpty ? nil : notes
        if c.syncState == .synced { c.syncState = .localModified }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
```

- [ ] **Step 4.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 5: `CompletionsListRow`

**Files:**
- Create: `GameTracker/Views/Completions/CompletionsListRow.swift`

Single row layout: cover thumb on the left, title + platform on top, date + time-taken on middle line, notes preview on bottom, sync-state badge on the right (reuses `SyncStateBadge` from Plan 3a's `GameListRow.swift`).

- [ ] **Step 5.1: Write `CompletionsListRow.swift`**

```swift
import SwiftUI

struct CompletionsListRow: View {
    let completion: GameCompletion
    let imagesAPI: ImagesAPI

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            CoverImage(gameServerId: completion.gameServerId,
                       face: .front,
                       size: .thumb,
                       api: imagesAPI)
                .frame(width: 40, height: 60)
                .clipShape(RoundedRectangle(cornerRadius: 4))

            VStack(alignment: .leading, spacing: 3) {
                Text(completion.title)
                    .font(.body.weight(.medium))
                    .lineLimit(2)

                HStack(spacing: 6) {
                    if let p = completion.platform, !p.isEmpty {
                        Text(p).font(.caption).foregroundStyle(.secondary)
                    }
                    if let d = completion.dateCompleted {
                        Text("·").font(.caption).foregroundStyle(.secondary)
                        Text(d.formatted(date: .abbreviated, time: .omitted))
                            .font(.caption).foregroundStyle(.secondary)
                    }
                    if let t = completion.timeTaken, !t.isEmpty {
                        Text("·").font(.caption).foregroundStyle(.secondary)
                        Text(t).font(.caption).foregroundStyle(.secondary)
                    }
                }

                if let n = completion.notes, !n.isEmpty {
                    Text(n)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                }
            }

            Spacer()

            SyncStateBadge(state: completion.syncState)
        }
        .padding(.vertical, 4)
    }
}
```

- [ ] **Step 5.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 6: `CompletionsView`

**Files:**
- Create: `GameTracker/Views/Completions/CompletionsView.swift`

The main tab. Reactive `@Query` of every non-deleted `GameCompletion`, sorted by `dateCompleted` descending (nils last). Search filters by title or notes substring. `+` opens `AddCompletionView`. Tapping a row opens `EditCompletionView`. Swipe-to-delete marks `.localDeleted` (or hard-deletes if `serverId == nil`). Pull-to-refresh triggers `syncEngine.runOnce()`. Empty state mirrors Plan 3a's pattern (wrapped in a `List` so the gesture still binds).

- [ ] **Step 6.1: Write `CompletionsView.swift`**

```swift
import SwiftUI
import SwiftData

struct CompletionsView: View {

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context

    @Query(filter: #Predicate<GameCompletion> { $0.syncStateRaw != "local_deleted" })
    private var allCompletions: [GameCompletion]

    @State private var search = ""
    @State private var showAdd = false
    @State private var showConflicts = false
    @State private var editingID: PersistentIdentifier?

    private var filtered: [GameCompletion] {
        // Sort: dateCompleted DESC, nils last. Then secondary sort by createdAt DESC.
        let sorted = allCompletions.sorted { a, b in
            switch (a.dateCompleted, b.dateCompleted) {
            case let (l?, r?): return l > r
            case (_?, nil):    return true
            case (nil, _?):    return false
            case (nil, nil):   return a.createdAt > b.createdAt
            }
        }
        guard !search.isEmpty else { return sorted }
        let s = search.lowercased()
        return sorted.filter {
            $0.title.lowercased().contains(s)
            || ($0.notes?.lowercased().contains(s) ?? false)
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }
                SyncStatusBannerView(status: status)
                content
            }
            .navigationTitle("Completions")
            .searchable(text: $search, prompt: "Search title or notes")
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button { showAdd = true } label: { Image(systemName: "plus") }
                }
            }
            .sheet(isPresented: $showAdd) {
                AddCompletionView(imagesAPI: imagesAPI, syncTrigger: syncTrigger)
            }
            .sheet(item: $editingID) { id in
                EditCompletionView(completionID: id,
                                   imagesAPI: imagesAPI,
                                   syncTrigger: syncTrigger)
            }
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
        }
    }

    @ViewBuilder
    private var content: some View {
        let rows = filtered
        if rows.isEmpty {
            // Wrap the empty state in a List so pull-to-refresh has a
            // scroll context to attach to even when there's no data.
            List {
                ContentUnavailableView("No completions",
                                       systemImage: "checkmark.seal",
                                       description: Text("Pull to sync, or tap + to log a finished game."))
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            }
            .listStyle(.plain)
        } else {
            List {
                ForEach(rows) { c in
                    Button {
                        editingID = c.persistentModelID
                    } label: {
                        CompletionsListRow(completion: c, imagesAPI: imagesAPI)
                    }
                    .buttonStyle(.plain)
                }
                .onDelete(perform: delete(at:))
            }
            .listStyle(.plain)
        }
    }

    private func delete(at offsets: IndexSet) {
        let rows = filtered
        for i in offsets {
            let c = rows[i]
            if c.serverId == nil {
                context.delete(c)
            } else {
                c.syncState = .localDeleted
            }
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
    }
}

// MARK: - PersistentIdentifier as an Identifiable sheet item
// `sheet(item:)` requires Identifiable; PersistentIdentifier already
// conforms via SwiftData but isn't tagged Identifiable. Wrap as needed.
extension PersistentIdentifier: @retroactive Identifiable {
    public var id: Self { self }
}
```

Note on `@retroactive Identifiable`: Swift 5.10+ requires the `@retroactive` annotation when adding a protocol conformance to a type you don't own. If the project targets earlier Swift, drop the annotation.

- [ ] **Step 6.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```

Expected: `** BUILD SUCCEEDED **`. (If the `@retroactive` line errors, remove it and retry.)

---

## Task 7: Wire into `RootTabView`

**Files:**
- Modify: `GameTracker/Views/Tabs/RootTabView.swift` (replace the Spin `PlaceholderTabView` with `CompletionsView`)

- [ ] **Step 7.1: Apply the swap**

Open `ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift`. Find the Spin placeholder block:

```swift
            PlaceholderTabView(title: "Spin",
                               systemImage: "dial.medium",
                               blurb: "Random game picker.")
                .tabItem { Label("Spin", systemImage: "dial.medium") }
```

Replace it with:

```swift
            CompletionsView(syncEngine: syncEngine,
                            syncTrigger: syncTrigger,
                            imagesAPI: imagesAPI,
                            status: status)
                .tabItem { Label("Completions", systemImage: "checkmark.seal") }
```

- [ ] **Step 7.2: Full build + tests**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | head -10
```

Expected: `** TEST SUCCEEDED **`. (No new tests; existing 54 still pass.)

- [ ] **Step 7.3: Commit Tasks 1-7 together**

These six new files plus the RootTabView modification ship in one commit (matches Plan 3a's bundling of interdependent UI work):

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Completions
git add ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift
git commit -m "Add Completions tab (replaces Spin placeholder) with add/edit/delete"
```

### 🛑 User checkpoint — Completions tab works end-to-end

Stop here. Tell the owner to ⌘R in Xcode (iPhone 17 sim) and verify:

1. Tab bar now reads **Library / Items / Completions / Stats / Settings** (no more Spin). Completions has a checkmark-seal icon.
2. Tapping the Completions tab shows either the existing completions pulled down from the server (sorted newest first) or the "No completions" empty state.
3. Pull-to-refresh works in both empty and populated states.
4. Tap **+** → "Log a completion" sheet opens.
5. Tap "Choose a game…" → game picker sheet opens with a searchable list. Pick a game.
6. Set a completion date. Add an optional time + notes. **Save**.
7. Sheet dismisses; the new row appears at the top of the list with a `new` badge; ~6 s later the badge clears (debounced sync).
8. Tap an existing row → edit sheet opens with the fields pre-filled. Change something. **Save**. Row updates; `edit` badge appears; ~6 s later clears.
9. Swipe a row → **Delete**. Row vanishes. Pull-to-refresh leaves it gone.
10. The web app shows the same changes (new completion appears, edits round-trip).
11. The other four tabs still behave (Library still works; Items / Stats placeholders still show "Coming soon"; Settings still has working sign-out).

Resume the implementer queue only after the owner confirms or reports a specific failure.

---

## Task 8: Manual smoke pass

**Files:** none

Run through every flow built above in the sim with the real server, end-to-end. This is the equivalent of Plan 3a's Task 12.

- [ ] **Step 8.1: ⌘R the app**

If the sim hangs, reboot the Mac.

- [ ] **Step 8.2: Run through the checklist**

| # | Action | Expected |
|---|---|---|
| 1 | Sign in to a multi-game account | Library populates; tab bar shows Completions |
| 2 | Open Completions | Server-side completions pulled down; sorted newest first |
| 3 | + → pick a game → save (no date / no time) | Row appears with no date stamp; `new` badge clears after sync |
| 4 | + → pick a game → set a date in 2024 → time "12h" → notes "great game" → save | Row shows the date + "12h" + notes preview; `new` badge clears |
| 5 | Search by title substring | List filters live |
| 6 | Search by a notes substring | List filters live |
| 7 | Clear search | Full list returns |
| 8 | Tap a row → edit the date → save | Row's date updates; `edit` badge clears after sync |
| 9 | Swipe a row → Delete | Row disappears |
| 10 | Pull-to-refresh | Row stays gone |
| 11 | Open the web app for the same user | Same completions visible; new ones from iOS round-trip |
| 12 | Open Library tab → tap a game with a known completion → scroll to "Completions" section | The completion is still listed (Plan 3a's read-only display still works) |

If any step fails, note the symptom and dig in. Don't push if Add/Edit/Delete round-trip is broken.

---

## Task 9: Push + open PR + wrap up

**Files:** none

- [ ] **Step 9.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only `js/completions.js` (pre-existing).

- [ ] **Step 9.2: Push**

```bash
git push -u origin plan-3b-completions-tab
```

- [ ] **Step 9.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-21-ios-completions-tab.md
git add docs/superpowers/plans/2026-05-21-ios-completions-tab.md
git commit -m "Mark Plan 3b (iOS completions tab) complete"
git push
```

- [ ] **Step 9.4: Open PR**

```bash
gh pr create --base main --head plan-3b-completions-tab \
  --title "Plan 3b: iOS Completions tab" \
  --body "$(cat <<'EOF'
## Summary

Replaces the Spin tab placeholder with a real **Completions** tab:

- Flat chronological list of every `GameCompletion` across the user's library.
- `@Query` reactive list (same pattern as Library after the Plan 3a sign-out branch fix).
- Searchable by title or notes substring.
- `+` adds via a sheet whose game picker is itself a searchable list of the user's games (needed for the 883-game account).
- Tap a row to edit; swipe to delete (soft-delete if `serverId` set, hard-delete otherwise).
- Pull-to-refresh + sync-status banner + debounced post-mutation sync, all reused from Plan 3a.

Tab bar is now: **Library / Items / Completions / Stats / Settings**.

## Test Plan

- [x] `xcodebuild test` — 54 tests still pass; no new tests added (model + sync layer already covered from Plan 2)
- [x] Manual smoke pass on iPhone 17 sim against the live server (logged-in 883-game account): list, search, add, edit, delete, web round-trip

## Not in scope

Items tab, Stats tab, per-game "add completion for this game" button, year-grouping in the list, editing the existing read-only completion list inside `GameDetailView`. Captured for Plan 3c+.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [ ] Every referenced symbol exists: `Game`, `GameCompletion`, `SyncState`, `SyncStatus`, `SyncEngine`, `SyncTrigger`, `ImagesAPI`, `CoverImage`, `ConflictBannerView`, `ConflictListView`, `SyncStateBadge`, `SyncStatusBannerView`. (All landed via Plan 2 / Plan 3a.)
- [ ] No file is referenced by two different names across tasks.
- [ ] `CompletionFormBody` is used identically by `AddCompletionView` and `EditCompletionView` (same property order and types).
- [ ] `editingID` binding in `CompletionsView` matches the `Identifiable` extension on `PersistentIdentifier`.
- [ ] Both Add and Edit set `completionYear` from `dateCompleted` consistently (year-int matches the calendar year of the date when present, `nil` otherwise).
- [ ] All commit messages cover the visible behaviour and bundle interdependent files together (matches Plan 3a's style).
- [ ] No "TBD" or "implement later" left anywhere.
