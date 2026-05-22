# iOS Items Tab Implementation Plan (Plan 3c)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `Items` placeholder tab in `RootTabView` with a working Items surface — a single searchable list of every console and accessory the user owns, with category filtering, full add / edit / delete, and a per-item detail view that supports real front/back cover images.

**Architecture:** Pure UI work mirroring Plan 3a's `LibraryView` shape, plus one ~12-line extension to the existing server cover endpoint and a minimal generalization of `CoverImage` / `ImagesAPI` so item covers go through the same cached-image pipeline as game covers. Sync layer is untouched — `Item` and `ItemImage` already round-trip through `SyncEngine`, `PushBuilder`, and `ChangeApplier` from Plan 2.

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData (`@Model`, `@Query`), existing `APIClient` / `ImagesAPI` / `SyncTrigger`. Server: PHP 8.x, MySQL, the existing `/api/v2/images/cover.php` endpoint.

**Predecessors:** Plans [3a (Library + game flows)](2026-05-21-ios-library-and-game-flows.md) and [3b (Completions tab)](2026-05-21-ios-completions-tab.md). The spec for this plan lives at [`docs/superpowers/specs/2026-05-22-ios-items-tab-design.md`](../specs/2026-05-22-ios-items-tab-design.md).

**Execution rhythm:** Per-feature checkpoint pattern (memory: `feedback_per_feature_checkpoints`). Two visible commits in this plan:

1. After **Task 1** (server cover.php extension) — invisible from iOS, no checkpoint, but the owner should deploy to the live server before the iOS checkpoint can verify item images.
2. After **Task 11** (full iOS tab wired into RootTabView) — **🛑 User checkpoint**. Owner ⌘Rs the sim, walks the verification list, confirms or flags a specific failure.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git` and server files.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-3c-items-tab`, branched off `main` (Plan 3b is merged: commit `d07219c`).
- **Pre-existing changes to leave alone in every commit:**
  - `js/completions.js` — old uncommitted whitespace edit.
  - `scripts/generate-thumbnails 2.php` + `tests/v2/*2.sh` — iCloud Drive conflict copies of shell scripts.
- **iCloud Drive Swift conflict files:** The repo's `.gitignore` ignores `** [0-9].swift` siblings, but copies still appear on disk and break `xcodebuild` with "invalid redeclaration". Before each build/test pass, run:

  ```bash
  find ios/GameTracker -name "* [0-9].swift" -print -delete
  ```

  Plan 3b deleted ten of these before starting; expect more by now.

---

## What this plan does NOT build (Plan 3d+ territory)

- **Stats tab** — Swift Charts dashboards. Most natural Plan 3d.
- **Image upload from iOS** — camera / photo-library picker + multipart POST. Existing image strings round-trip via sync on edit; no new uploads from iOS yet.
- **The `item_images` extras gallery** on `ItemDetailView`. Single front/back cover only.
- **Per-game "add completion for this game" button** on `GameDetailView`. Still deferred.
- **Per-platform / per-category stats** on the Items tab itself.
- **Items sort menu.** Hardcode title A→Z; add a sort menu in a follow-up plan if needed.
- **Year-grouping or other Completions-tab refinements.**

---

## Server API surface this plan consumes

| Endpoint | Purpose | Plan 3c work |
|---|---|---|
| `GET /api/v2/sync/changes.php?since=…` | Pulls changed `Item` rows | None — already wired |
| `POST /api/v2/sync/push.php` | Pushes locally-new / modified / deleted items | None — already wired |
| `GET /api/v2/images/cover.php?id=…&face=…&size=…` | Streams cover image bytes | **Extended in Task 1** to accept `?type=game\|item` |

No new endpoints. No database changes. No client-side networking changes beyond a single new method on `ImagesAPI`.

---

## File structure

### New iOS files (all under `ios/GameTracker/GameTracker/Views/Items/`)

```
Items/
├── ItemCategory.swift      — small enum + display helpers, shared by every Items view
├── ItemsView.swift         — main tab: @Query list, search, category filter, +, view-mode menu, swipe-delete
├── ItemsListRow.swift      — single row (list mode): cover thumb + title + meta + quantity badge
├── ItemsGridCell.swift     — single cell (grid mode): square thumb + title overlay + badges
├── ItemDetailView.swift    — detail page: cover, full metadata, Edit button
├── AddItemView.swift       — form sheet for a new item
├── EditItemView.swift      — form sheet for an existing item
└── ItemFormBody.swift      — shared Form body used by Add + Edit
```

### Modified iOS files

- `Views/Tabs/RootTabView.swift` — replace the `Items` `PlaceholderTabView` with `ItemsView`.
- `Networking/ImagesAPI.swift` — add `downloadCover(itemServerId:face:size:)` next to the existing `downloadCover(gameServerId:…)`.
- `Views/Common/CoverImage.swift` — add a `Subject` discriminator and a parallel `init(itemServerId:…)`; existing `init(gameServerId:…)` stays as a convenience init so every existing call site keeps compiling unchanged.

### Modified server files

- `api/v2/images/cover.php` — accept optional `?type=game|item` (default `game` preserves all current iOS behaviour). When `type=item`, look up `items.front_image` / `items.back_image` by item ID; otherwise unchanged.

### Untouched

- Sync layer (`SyncEngine`, `PushBuilder`, `ChangeApplier`, `SyncAPI`, `DTOs.swift`) — `Item` and `ItemImage` already round-trip.
- `Item` model — already carries every field this UI needs.
- `RootView.swift`, `GameTrackerApp.swift` — `ItemsView` only needs `imagesAPI` + `syncEngine` + `syncTrigger` + `status`, all of which `RootTabView` already holds as properties.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-22-ios-items-tab.md` (this file)

- [ ] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current        # → plan-3c-items-tab
git log --oneline -3              # → spec corrections + spec + 3b merge
git status --short                # only pre-existing junk (js/completions.js + iCloud .sh/.php copies)
```

Expected: branch is `plan-3c-items-tab`; recent commits include the design spec and a corrections commit; working tree shows only pre-existing junk.

- [ ] **Step 0.2: Clear iCloud Swift conflict files (if any)**

```bash
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

Expected: prints any stragglers and deletes them, or prints nothing if clean.

- [ ] **Step 0.3: Baseline test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -5
```

Expected: `** TEST SUCCEEDED **`.

- [ ] **Step 0.4: Commit this plan doc**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add docs/superpowers/plans/2026-05-22-ios-items-tab.md
git commit -m "Add Plan 3c (iOS Items tab) implementation plan"
```

---

## Task 1: Server-side `cover.php` extension

**Files:**
- Modify: `api/v2/images/cover.php`

The endpoint currently hard-codes the `games` table. Extend it to accept an optional `?type=game|item` query parameter, defaulting to `game` for back-compat. When `type=item`, query the `items` table's `front_image` / `back_image` columns instead. The downstream format dispatch (data URI / external HTTPS / bare filename) is unchanged.

- [ ] **Step 1.1: Edit `api/v2/images/cover.php`**

Find this block near the top:

```php
$userId = v2_require_auth($pdo);

$gameId = (int)($_GET['id'] ?? 0);
$size   = $_GET['size'] ?? 'full';
$face   = $_GET['face'] ?? 'front';

if ($gameId <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}
if (!in_array($face, ['front', 'back'], true)) {
    v2_error('bad_request', 'face must be front or back', 400);
}

$col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
$stmt = $pdo->prepare("SELECT $col AS path FROM games WHERE id = ? AND user_id = ?");
$stmt->execute([$gameId, $userId]);
```

Replace it with:

```php
$userId = v2_require_auth($pdo);

$id   = (int)($_GET['id'] ?? 0);
$size = $_GET['size'] ?? 'full';
$face = $_GET['face'] ?? 'front';
$type = $_GET['type'] ?? 'game';   // 'game' (default, back-compat) or 'item'

if ($id <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}
if (!in_array($face, ['front', 'back'], true)) {
    v2_error('bad_request', 'face must be front or back', 400);
}
if (!in_array($type, ['game', 'item'], true)) {
    v2_error('bad_request', 'type must be game or item', 400);
}

// Resolve which table + columns to look up.
if ($type === 'item') {
    $col = $face === 'back' ? 'back_image' : 'front_image';
    $stmt = $pdo->prepare("SELECT $col AS path FROM items WHERE id = ? AND user_id = ?");
} else {
    $col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
    $stmt = $pdo->prepare("SELECT $col AS path FROM games WHERE id = ? AND user_id = ?");
}
$stmt->execute([$id, $userId]);
```

Everything below `$stmt->execute(...)` is unchanged — the row-fetch, `not_found` guard, data-URI/HTTPS/bare-filename dispatch, and `readfile` all operate on `$row['path']` exactly the same way for both tables.

- [ ] **Step 1.2: Sanity-check the file still parses**

```bash
php -l api/v2/images/cover.php
```

Expected: `No syntax errors detected in api/v2/images/cover.php`.

- [ ] **Step 1.3: Commit**

```bash
git add api/v2/images/cover.php
git commit -m "v2 cover endpoint: accept ?type=item to serve item front/back images

Default remains type=game so existing iOS clients keep working
unchanged. The downstream format dispatch (data URI / HTTPS /
bare filename) is identical for both tables."
```

- [ ] **Step 1.4: Deploy to the live server**

The iOS checkpoint at the end of this plan can't verify item images until this change is live on the server. Deploy via whatever flow the owner uses (manual git pull on the server, rsync, etc.). If unsure, stop here and confirm with the owner before continuing.

---

## Task 2: `ImagesAPI` — add `downloadCover(itemServerId:…)`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Networking/ImagesAPI.swift`

Add a sibling method to the existing `downloadCover(gameServerId:face:size:)` that hits the same endpoint but with `type=item`. Distinct cache filename prefix (`item_…` vs `cover_…`) prevents collisions between a game and an item with the same server ID.

- [ ] **Step 2.1: Edit `ImagesAPI.swift`**

Locate the existing `downloadCover` method:

```swift
/// Returns a local file URL pointing at the cached cover image.
/// Downloads if not already on disk.
func downloadCover(gameServerId: Int, face: Face, size: Size) async throws -> URL {
    let filename = "cover_\(gameServerId)_\(face.rawValue)_\(size.rawValue).jpg"
    let dest = cacheRoot.appendingPathComponent(filename)
    if FileManager.default.fileExists(atPath: dest.path) { return dest }

    let data = try await client.downloadData(
        "/api/v2/images/cover.php",
        query: ["id": String(gameServerId), "face": face.rawValue, "size": size.rawValue]
    )
    try data.write(to: dest, options: .atomic)
    return dest
}
```

Add immediately after it (before `downloadExtra`):

```swift
/// Mirror of `downloadCover(gameServerId:…)` but hits the same
/// endpoint with `type=item`, looking up `items.front_image` /
/// `items.back_image`. Cache filename is namespaced with `item_`
/// so a game and item sharing a server ID never collide on disk.
func downloadCover(itemServerId: Int, face: Face, size: Size) async throws -> URL {
    let filename = "item_\(itemServerId)_\(face.rawValue)_\(size.rawValue).jpg"
    let dest = cacheRoot.appendingPathComponent(filename)
    if FileManager.default.fileExists(atPath: dest.path) { return dest }

    let data = try await client.downloadData(
        "/api/v2/images/cover.php",
        query: ["id": String(itemServerId), "type": "item", "face": face.rawValue, "size": size.rawValue]
    )
    try data.write(to: dest, options: .atomic)
    return dest
}
```

Leave the original `downloadCover(gameServerId:…)` untouched — it omits the `type` param, the server defaults to `game`, all existing callers keep working.

- [ ] **Step 2.2: Build check**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`. (No commit yet — Tasks 2–10 are interdependent and ship together at the end of Task 11.)

---

## Task 3: `CoverImage` — accept item server IDs

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Common/CoverImage.swift`

Generalize the view to load either a game cover or an item cover via a private `Subject` discriminator. Two convenience inits — `init(gameServerId:…)` (existing) and `init(itemServerId:…)` (new) — set the right subject internally. Every existing call site keeps working byte-for-byte.

- [ ] **Step 3.1: Rewrite `CoverImage.swift`**

Replace the file's entire contents with:

```swift
import SwiftUI

/// Async-loaded cover image with a placeholder. Uses `ImagesAPI`'s
/// on-disk cache, so subsequent renders for the same (subject,
/// face, size) are instant.
///
/// Supports both game covers and item covers via two convenience
/// inits — internally the view discriminates with the `Subject` enum
/// and dispatches to the matching `ImagesAPI.downloadCover` overload.
struct CoverImage: View {

    /// Which kind of resource to fetch. `nil` ID means "no image yet"
    /// (typically the row is still `.localNew` and unpushed) — the
    /// view renders the empty placeholder.
    private enum Subject: Equatable {
        case game(Int?)
        case item(Int?)
    }

    private let subject: Subject
    let face: ImagesAPI.Face
    let size: ImagesAPI.Size
    let api: ImagesAPI

    @State private var localURL: URL?
    @State private var failed = false

    init(gameServerId: Int?,
         face: ImagesAPI.Face = .front,
         size: ImagesAPI.Size = .thumb,
         api: ImagesAPI) {
        self.subject = .game(gameServerId)
        self.face = face
        self.size = size
        self.api = api
    }

    init(itemServerId: Int?,
         face: ImagesAPI.Face = .front,
         size: ImagesAPI.Size = .thumb,
         api: ImagesAPI) {
        self.subject = .item(itemServerId)
        self.face = face
        self.size = size
        self.api = api
    }

    var body: some View {
        Group {
            if let url = localURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img)
                    .resizable()
                    .aspectRatio(contentMode: .fit)
            } else if failed {
                placeholder(systemName: "photo.badge.exclamationmark")
            } else {
                placeholder(systemName: "photo")
            }
        }
        .task(id: subject) {
            await load()
        }
    }

    private func placeholder(systemName: String) -> some View {
        Rectangle()
            .fill(Color.gray.opacity(0.2))
            .overlay {
                Image(systemName: systemName)
                    .font(.title)
                    .foregroundStyle(.secondary)
            }
            .aspectRatio(2.0/3.0, contentMode: .fit)
    }

    private func load() async {
        do {
            let url: URL?
            switch subject {
            case .game(let id):
                guard let id else { return }
                url = try await api.downloadCover(gameServerId: id, face: face, size: size)
            case .item(let id):
                guard let id else { return }
                url = try await api.downloadCover(itemServerId: id, face: face, size: size)
            }
            await MainActor.run {
                self.localURL = url
                self.failed = false
            }
        } catch {
            await MainActor.run { self.failed = true }
        }
    }
}
```

Key changes from the prior file:
- Added private `Subject` enum.
- `init(gameServerId:…)` retained exactly, plus a sibling `init(itemServerId:…)`.
- The `.task(id:)` modifier now keys off `subject` (which conforms to `Equatable`), so a change in either the game ID or the item ID re-fetches as before.
- `load()` switches on the subject and calls the matching `ImagesAPI.downloadCover` overload.

- [ ] **Step 3.2: Build check (still compiles for every existing call site)**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`. (If any existing call site of `CoverImage(gameServerId: …)` fails to compile, the signature drift was introduced in error — re-check the new init's parameter names.)

---

## Task 4: `ItemCategory` enum

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/ItemCategory.swift`

Shared enum used by every Items view: the filter chip, list rows, grid cells, form bodies, and detail view all need to map between the model's `String` category and a small typed enum with display labels + system icons.

- [ ] **Step 4.1: Write `ItemCategory.swift`**

```swift
import Foundation

/// Maps to `Item.category` (`"console"` / `"accessory"`). The model
/// stores a String so the value flows opaquely through sync; this
/// enum is purely a UI convenience.
enum ItemCategory: String, CaseIterable, Identifiable {
    case console
    case accessory

    var id: String { rawValue }

    var displayName: String {
        switch self {
        case .console:   return "Console"
        case .accessory: return "Accessory"
        }
    }

    /// SF Symbol shown next to the platform on rows and in the form.
    var systemImage: String {
        switch self {
        case .console:   return "gamecontroller.fill"
        case .accessory: return "cable.connector"
        }
    }

    /// Best-effort parse from a model string. Falls back to `.console`
    /// for any unrecognised value — defensive only; the web app
    /// enforces the two-value enum on its side.
    init(rawString: String?) {
        switch rawString {
        case "accessory": self = .accessory
        default:          self = .console
        }
    }
}
```

- [ ] **Step 4.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 5: `ItemFormBody`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/ItemFormBody.swift`

Shared Form body used by both Add and Edit. All bindings owned by the parent view (lesson from Plan 3b: no nested sheets, parents own state and presentation).

- [ ] **Step 5.1: Write `ItemFormBody.swift`**

```swift
import SwiftUI

/// Shared form fields for Add/Edit item. The owning view supplies
/// every binding; this struct contains no `@State` of its own — that
/// keeps the Form's sheet-anchor stable (Plan 3b learning).
struct ItemFormBody: View {
    @Binding var title: String
    @Binding var category: ItemCategory
    @Binding var platform: String
    @Binding var condition: String
    @Binding var pricePaid: String
    @Binding var pricechartingPrice: String
    @Binding var quantity: Int
    @Binding var description: String
    @Binding var notes: String

    var body: some View {
        Group {
            Section("Title & category") {
                TextField("Title", text: $title)
                Picker("Category", selection: $category) {
                    ForEach(ItemCategory.allCases) { c in
                        Label(c.displayName, systemImage: c.systemImage).tag(c)
                    }
                }
                .pickerStyle(.segmented)
            }

            Section("Platform & condition") {
                TextField("Platform (e.g. PlayStation 5)", text: $platform)
                TextField("Condition (e.g. Good, Boxed, CIB)", text: $condition)
            }

            Section("Price") {
                TextField("Price paid (£)", text: $pricePaid)
                    .keyboardType(.decimalPad)
                TextField("Pricecharting value (£)", text: $pricechartingPrice)
                    .keyboardType(.decimalPad)
            }

            Section("Quantity") {
                Stepper(value: $quantity, in: 1...99) {
                    Text("Quantity: \(quantity)")
                }
            }

            Section("Description") {
                TextField("Description", text: $description, axis: .vertical)
                    .lineLimit(3...10)
            }

            Section("Notes") {
                TextField("Notes", text: $notes, axis: .vertical)
                    .lineLimit(3...10)
            }
        }
    }
}
```

- [ ] **Step 5.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 6: `AddItemView`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/AddItemView.swift`

Sheet that creates a new `Item` with `syncState = .localNew` and triggers a debounced sync.

- [ ] **Step 6.1: Write `AddItemView.swift`**

```swift
import SwiftUI
import SwiftData

struct AddItemView: View {
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var title: String = ""
    @State private var category: ItemCategory = .console
    @State private var platform: String = ""
    @State private var condition: String = ""
    @State private var pricePaid: String = ""
    @State private var pricechartingPrice: String = ""
    @State private var quantity: Int = 1
    @State private var description: String = ""
    @State private var notes: String = ""

    private var canSave: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes)
            }
            .navigationTitle("Add an item")
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
        let item = Item(title: title.trimmingCharacters(in: .whitespaces),
                        category: category.rawValue,
                        syncState: .localNew)
        item.platform           = platform.isEmpty ? nil : platform
        item.conditionValue     = condition.isEmpty ? nil : condition
        item.pricePaid          = Double(pricePaid)
        item.pricechartingPrice = Double(pricechartingPrice)
        item.quantity           = quantity
        item.itemDescription    = description.isEmpty ? nil : description
        item.notes              = notes.isEmpty ? nil : notes
        context.insert(item)
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
```

- [ ] **Step 6.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 7: `EditItemView`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/EditItemView.swift`

Same form, bound to an existing `Item` via `PersistentIdentifier`. On save: transitions `.synced → .localModified` only (so untouched-then-synced rows don't get re-pushed).

- [ ] **Step 7.1: Write `EditItemView.swift`**

```swift
import SwiftUI
import SwiftData

struct EditItemView: View {
    let itemID: PersistentIdentifier
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var title: String = ""
    @State private var category: ItemCategory = .console
    @State private var platform: String = ""
    @State private var condition: String = ""
    @State private var pricePaid: String = ""
    @State private var pricechartingPrice: String = ""
    @State private var quantity: Int = 1
    @State private var description: String = ""
    @State private var notes: String = ""
    @State private var loaded = false

    private var canSave: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes)
            }
            .navigationTitle("Edit item")
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
        guard !loaded, let i: Item = context.model(for: itemID) as? Item else { return }
        title              = i.title
        category           = ItemCategory(rawString: i.category)
        platform           = i.platform ?? ""
        condition          = i.conditionValue ?? ""
        pricePaid          = i.pricePaid.map { String($0) } ?? ""
        pricechartingPrice = i.pricechartingPrice.map { String($0) } ?? ""
        quantity           = max(1, i.quantity)
        description        = i.itemDescription ?? ""
        notes              = i.notes ?? ""
        loaded = true
    }

    private func save() {
        guard let i: Item = context.model(for: itemID) as? Item else { return }
        i.title              = title.trimmingCharacters(in: .whitespaces)
        i.category           = category.rawValue
        i.platform           = platform.isEmpty ? nil : platform
        i.conditionValue     = condition.isEmpty ? nil : condition
        i.pricePaid          = Double(pricePaid)
        i.pricechartingPrice = Double(pricechartingPrice)
        i.quantity           = quantity
        i.itemDescription    = description.isEmpty ? nil : description
        i.notes              = notes.isEmpty ? nil : notes
        if i.syncState == .synced { i.syncState = .localModified }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
```

- [ ] **Step 7.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 8: `ItemsListRow`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/ItemsListRow.swift`

Single row (list mode): cover thumb + title + caption (icon + platform + condition) + quantity badge if > 1 + sync badge. Mirrors `GameListRow`.

- [ ] **Step 8.1: Write `ItemsListRow.swift`**

```swift
import SwiftUI

struct ItemsListRow: View {
    let item: Item
    let imagesAPI: ImagesAPI

    private var category: ItemCategory { ItemCategory(rawString: item.category) }

    var body: some View {
        HStack(spacing: 12) {
            CoverImage(itemServerId: item.serverId, face: .front, size: .thumb, api: imagesAPI)
                .frame(width: 40, height: 60)
                .clipShape(RoundedRectangle(cornerRadius: 4))

            VStack(alignment: .leading, spacing: 3) {
                Text(item.title)
                    .font(.body.weight(.medium))
                    .lineLimit(2)

                HStack(spacing: 6) {
                    Image(systemName: category.systemImage)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    if let p = item.platform, !p.isEmpty {
                        Text(p).font(.caption).foregroundStyle(.secondary)
                    }
                    if let c = item.conditionValue, !c.isEmpty {
                        if item.platform?.isEmpty == false {
                            Text("·").font(.caption).foregroundStyle(.secondary)
                        }
                        Text(c).font(.caption).foregroundStyle(.secondary)
                    }
                }
            }

            Spacer()

            if item.quantity > 1 {
                Text("×\(item.quantity)")
                    .font(.caption2.monospacedDigit().weight(.medium))
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(Color.gray.opacity(0.15), in: Capsule())
                    .foregroundStyle(.secondary)
            }

            SyncStateBadge(state: item.syncState)
        }
        .padding(.vertical, 4)
    }
}
```

- [ ] **Step 8.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 9: `ItemsGridCell`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/ItemsGridCell.swift`

Square cover with title overlay, sync badge top-left, quantity badge top-right when > 1. Mirrors `GameGridCell`'s shape.

- [ ] **Step 9.1: Write `ItemsGridCell.swift`**

```swift
import SwiftUI

struct ItemsGridCell: View {
    let item: Item
    let imagesAPI: ImagesAPI

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            CoverImage(itemServerId: item.serverId, face: .front, size: .thumb, api: imagesAPI)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .clipShape(RoundedRectangle(cornerRadius: 6))

            // Bottom gradient + title overlay
            LinearGradient(colors: [.black.opacity(0.75), .clear],
                           startPoint: .bottom, endPoint: .center)
                .clipShape(RoundedRectangle(cornerRadius: 6))

            Text(item.title)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.white)
                .lineLimit(2)
                .padding(.horizontal, 6)
                .padding(.bottom, 4)

            // Top-left sync badge
            VStack {
                HStack {
                    SyncStateBadge(state: item.syncState)
                        .padding(4)
                        .background(Color.black.opacity(0.4), in: Capsule())
                    Spacer()
                    // Top-right quantity badge
                    if item.quantity > 1 {
                        Text("×\(item.quantity)")
                            .font(.caption2.monospacedDigit().weight(.semibold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.black.opacity(0.55), in: Capsule())
                    }
                }
                Spacer()
            }
            .padding(4)
        }
    }
}
```

- [ ] **Step 9.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 10: `ItemDetailView`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/ItemDetailView.swift`

Pushed onto the nav stack from `ItemsView`. Shows the front cover (tap to swap to back if both images are present), full metadata sections, and an Edit button that opens `EditItemView` as a sheet.

- [ ] **Step 10.1: Write `ItemDetailView.swift`**

```swift
import SwiftUI
import SwiftData

struct ItemDetailView: View {
    let itemID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context

    @State private var showEdit = false
    @State private var showingBack = false

    private var item: Item? {
        context.model(for: itemID) as? Item
    }

    private var category: ItemCategory {
        ItemCategory(rawString: item?.category)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                cover
                header
                if hasPricing { pricing }
                if hasConditionOrQuantity { conditionAndQuantity }
                if let d = item?.itemDescription, !d.isEmpty { descriptionSection(d) }
                if let n = item?.notes, !n.isEmpty { notesSection(n) }
            }
            .padding(.horizontal)
            .padding(.bottom, 24)
        }
        .navigationTitle(item?.title ?? "Item")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button("Edit") { showEdit = true }
            }
        }
        .sheet(isPresented: $showEdit) {
            EditItemView(itemID: itemID, syncTrigger: syncTrigger)
        }
    }

    // MARK: - Sections

    @ViewBuilder
    private var cover: some View {
        let face: ImagesAPI.Face = showingBack ? .back : .front
        CoverImage(itemServerId: item?.serverId, face: face, size: .full, api: imagesAPI)
            .frame(maxWidth: .infinity)
            .frame(height: 280)
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .onTapGesture {
                // Only flip if back image is set (we don't know without fetching;
                // tap-to-flip is best-effort, falling back to placeholder if not).
                showingBack.toggle()
            }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(item?.title ?? "")
                .font(.title2.weight(.semibold))
            HStack(spacing: 6) {
                Image(systemName: category.systemImage).foregroundStyle(.secondary)
                Text(category.displayName).font(.subheadline).foregroundStyle(.secondary)
                if let p = item?.platform, !p.isEmpty {
                    Text("·").foregroundStyle(.secondary)
                    Text(p).font(.subheadline).foregroundStyle(.secondary)
                }
            }
        }
    }

    private var hasPricing: Bool {
        item?.pricePaid != nil || item?.pricechartingPrice != nil
    }

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

    private var hasConditionOrQuantity: Bool {
        (item?.conditionValue?.isEmpty == false) || (item?.quantity ?? 0) > 0
    }

    private var conditionAndQuantity: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Condition & quantity").font(.headline)
            if let c = item?.conditionValue, !c.isEmpty {
                row(label: "Condition", value: c)
            }
            row(label: "Quantity", value: "\(item?.quantity ?? 1)")
        }
    }

    private func descriptionSection(_ d: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Description").font(.headline)
            Text(d).font(.body).foregroundStyle(.primary)
        }
    }

    private func notesSection(_ n: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Notes").font(.headline)
            Text(n).font(.body).foregroundStyle(.primary)
        }
    }

    // MARK: - Helpers

    private func row(label: String, value: String) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            Text(value).foregroundStyle(.primary)
        }
    }

    private func format(_ value: Double) -> String {
        String(format: "%.2f", value)
    }
}
```

- [ ] **Step 10.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: `** BUILD SUCCEEDED **`.

---

## Task 11: `ItemsView` (main tab) + wire into `RootTabView`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Items/ItemsView.swift`
- Modify: `ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift`

The tab itself. Reactive `@Query` of every non-deleted `Item`, sorted title A→Z. Inline segmented category filter at the top of the content area. Search by title or platform. Toolbar `+` opens `AddItemView`; toolbar `ellipsis.circle` Menu hosts the list/grid toggle. Tapping a row pushes `ItemDetailView`. Swipe-to-delete soft-deletes if `serverId` is set; hard-deletes otherwise.

- [ ] **Step 11.1: Write `ItemsView.swift`**

```swift
import SwiftUI
import SwiftData

struct ItemsView: View {

    enum ViewMode: String, CaseIterable, Identifiable {
        case list, grid
        var id: String { rawValue }
        var systemImage: String { self == .list ? "list.bullet" : "square.grid.2x2" }
    }

    enum CategoryFilter: String, CaseIterable, Identifiable {
        case all, console, accessory
        var id: String { rawValue }
        var displayName: String {
            switch self {
            case .all:       return "All"
            case .console:   return "Consoles"
            case .accessory: return "Accessories"
            }
        }
    }

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context

    @Query(filter: #Predicate<Item> { $0.syncStateRaw != "local_deleted" },
           sort: \Item.title,
           order: .forward)
    private var allItems: [Item]

    @State private var search = ""
    @State private var viewMode: ViewMode = .list
    @State private var categoryFilter: CategoryFilter = .all
    @State private var showAdd = false
    @State private var showConflicts = false

    private var filtered: [Item] {
        var rows = allItems
        switch categoryFilter {
        case .all:       break
        case .console:   rows = rows.filter { $0.category == "console" }
        case .accessory: rows = rows.filter { $0.category == "accessory" }
        }
        guard !search.isEmpty else { return rows }
        let s = search.lowercased()
        return rows.filter {
            $0.title.lowercased().contains(s)
            || ($0.platform?.lowercased().contains(s) ?? false)
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }
                SyncStatusBannerView(status: status)
                categoryPicker
                content
            }
            .navigationDestination(for: PersistentIdentifier.self) { id in
                ItemDetailView(itemID: id, imagesAPI: imagesAPI, syncTrigger: syncTrigger)
            }
            .navigationTitle("Items")
            .searchable(text: $search, prompt: "Search title or platform")
            .toolbar { toolbarContent }
            .sheet(isPresented: $showAdd) {
                AddItemView(syncTrigger: syncTrigger)
            }
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .navigationBarTrailing) {
            Button { showAdd = true } label: { Image(systemName: "plus") }
        }
        ToolbarItem(placement: .navigationBarTrailing) {
            Menu {
                Picker("View", selection: $viewMode) {
                    ForEach(ViewMode.allCases) { mode in
                        Label(mode.rawValue.capitalized, systemImage: mode.systemImage).tag(mode)
                    }
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
        }
    }

    private var categoryPicker: some View {
        Picker("Category", selection: $categoryFilter) {
            ForEach(CategoryFilter.allCases) { c in
                Text(c.displayName).tag(c)
            }
        }
        .pickerStyle(.segmented)
        .padding(.horizontal)
        .padding(.vertical, 8)
    }

    @ViewBuilder
    private var content: some View {
        let rows = filtered
        if rows.isEmpty {
            List {
                ContentUnavailableView("No items",
                                       systemImage: "shippingbox",
                                       description: Text("Pull to sync, or tap + to add a console or accessory."))
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            }
            .listStyle(.plain)
        } else {
            switch viewMode {
            case .list:
                List {
                    ForEach(rows) { item in
                        NavigationLink(value: item.persistentModelID) {
                            ItemsListRow(item: item, imagesAPI: imagesAPI)
                        }
                    }
                    .onDelete(perform: delete(at:))
                }
                .listStyle(.plain)
            case .grid:
                ScrollView {
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 110), spacing: 12)],
                              spacing: 12) {
                        ForEach(rows) { item in
                            NavigationLink(value: item.persistentModelID) {
                                ItemsGridCell(item: item, imagesAPI: imagesAPI)
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

    private func delete(at offsets: IndexSet) {
        let rows = filtered
        for i in offsets {
            let item = rows[i]
            if item.serverId == nil {
                context.delete(item)
            } else {
                item.syncState = .localDeleted
            }
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
    }
}
```

- [ ] **Step 11.2: Edit `RootTabView.swift`**

Find this block:

```swift
            PlaceholderTabView(title: "Items",
                               systemImage: "gamecontroller",
                               blurb: "Consoles and accessories will live here.")
                .tabItem { Label("Items", systemImage: "gamecontroller") }
```

Replace it with:

```swift
            ItemsView(syncEngine: syncEngine,
                      syncTrigger: syncTrigger,
                      imagesAPI: imagesAPI,
                      status: status)
                .tabItem { Label("Items", systemImage: "shippingbox") }
```

(Tab icon changes from `gamecontroller` — which clashes visually with the console category icon — to `shippingbox`, evoking "collection / boxed things".)

- [ ] **Step 11.3: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: `** TEST SUCCEEDED **`. No new tests; the model + sync layer is already covered from Plan 2, and the view layer is exercised manually via the checkpoint.

- [ ] **Step 11.4: Commit Tasks 2–11 together**

These nine new files plus the `RootTabView` modification plus the `ImagesAPI` / `CoverImage` extensions all ship in one commit (matches Plan 3b's bundling of interdependent UI work):

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Items \
        ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift \
        ios/GameTracker/GameTracker/Views/Common/CoverImage.swift \
        ios/GameTracker/GameTracker/Networking/ImagesAPI.swift
git commit -m "Add Items tab (replaces placeholder) with detail view and full CRUD"
```

### 🛑 User checkpoint — Items tab works end-to-end

Stop here. The owner should ⌘R in Xcode (iPhone 17 sim) and verify:

1. Tab bar now reads **Library / Items / Completions / Stats / Settings**. The Items icon is `shippingbox` (no longer the placeholder gamepad).
2. Tapping Items shows the user's existing consoles + accessories pulled down from the server, sorted A→Z. Or the "No items" empty state with a `shippingbox` icon.
3. **Real images** load on rows (server change from Task 1 is deployed). If a row shows a `photo.badge.exclamationmark` placeholder for an item that has an image on the web, the server change isn't deployed yet — deploy first, then ⌘R again.
4. The inline segmented chip at the top narrows to **All / Consoles / Accessories** correctly.
5. Search filters live by title or platform.
6. The `ellipsis.circle` Menu in the top-right toggles between **List** and **Grid** views; both render correctly.
7. Tap a row → detail view pushes; full metadata (title, category, platform, pricing, condition, quantity, description, notes) renders. Tapping the cover flips to the back image if one is set.
8. From the detail view, **Edit** opens a sheet pre-filled with every field. Change something → Save → row updates; `edit` badge appears then clears.
9. From the tab, `+` opens "Add an item". Fill in title + category + a couple of fields → Save → row appears at the right place in alphabetical order with a `new` badge that clears after sync.
10. Swipe-to-delete on a row → it disappears. Pull-to-refresh leaves it gone.
11. The web app shows the same changes (new items appear, edits round-trip, deletes are reflected).
12. **Quantity > 1** items show an `×N` badge on both list rows and grid cells.
13. Library, Completions, Settings still behave (Stats still placeholder).

Resume the implementer queue only after the owner confirms or reports a specific failure.

---

## Task 12: Manual smoke pass

**Files:** none

Walk every flow end-to-end against the live server. This is the equivalent of Plan 3b's Task 8 (and the user has been comfortable folding this into the checkpoint when coverage overlaps — confirm with them).

- [ ] **Step 12.1: ⌘R the app**

If the sim hangs, reboot the Mac.

- [ ] **Step 12.2: Run through the checklist**

| # | Action | Expected |
|---|---|---|
| 1 | Sign in to a multi-item account | Tab bar shows Items; tapping it shows server-side items A→Z |
| 2 | Tap "All / Consoles / Accessories" chip | List filters correctly each time |
| 3 | Search by title substring | List filters live |
| 4 | Search by platform substring | List filters live |
| 5 | Clear search | Full list returns |
| 6 | Switch to Grid via the ellipsis menu | Items render as 110pt-min adaptive grid cells |
| 7 | Switch back to List | List renders correctly |
| 8 | Tap an item with both front + back images on the web app | Detail view loads; tap cover flips face |
| 9 | Tap an item without images | Placeholder shown; no crash |
| 10 | + → fill in everything → Save | New row appears; badge clears after sync |
| 11 | Edit a row → change title → Save | Row updates; badge clears |
| 12 | Edit a row → change quantity from 1 to 3 → Save | `×3` badge appears on row |
| 13 | Swipe → Delete a row | Row disappears |
| 14 | Pull-to-refresh after delete | Row stays gone |
| 15 | Open the web app for the same user | Same items visible; new ones from iOS round-trip |
| 16 | Open the Library tab | Library still works (no regression from CoverImage refactor) |

If any step fails, note the symptom and dig in. Don't push if Add/Edit/Delete round-trip is broken or if game covers regress.

---

## Task 13: Push + open PR + wrap up

**Files:** none

- [ ] **Step 13.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk (`js/completions.js`, iCloud `.sh`/`.php` conflict copies).

- [ ] **Step 13.2: Push**

```bash
git push -u origin plan-3c-items-tab
```

- [ ] **Step 13.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-22-ios-items-tab.md
git add docs/superpowers/plans/2026-05-22-ios-items-tab.md
git commit -m "Mark Plan 3c (iOS Items tab) complete"
git push
```

- [ ] **Step 13.4: Open PR**

```bash
gh pr create --base main --head plan-3c-items-tab \
  --title "Plan 3c: iOS Items tab" \
  --body "$(cat <<'EOF'
## Summary

Replaces the **Items** placeholder tab with a working Items surface: a single searchable list of every console and accessory the user owns, with full CRUD via add/edit sheets and a per-item detail view.

- `@Query`-backed reactive list, sorted title A→Z (same default as Library).
- Inline segmented chip: **All / Consoles / Accessories**.
- Search by title or platform.
- `+` opens Add sheet; tap a row pushes `ItemDetailView`; swipe to delete (soft-delete when `serverId` is set, hard-delete otherwise).
- View-mode toggle (List / Grid) in the toolbar ellipsis menu, matching LibraryView.
- Real cover images via a small server-side parity extension to `cover.php` (now accepts `?type=game|item`) and a generalization of `CoverImage` / `ImagesAPI` to dispatch to either kind.
- `×N` quantity badge on rows where N > 1.

Tab bar after this PR: **Library / Items / Completions / Stats / Settings**. Stats is still a placeholder; Plan 3d candidate.

## Server change

`api/v2/images/cover.php` accepts a new optional `?type=game|item` query param, defaulting to `game` (back-compat). When `type=item`, it queries `items.front_image` / `items.back_image` by item ID instead of the games columns. The downstream format dispatch (data URI / external HTTPS / bare filename) is unchanged. **Deployed to the live server before iOS checkpoint.**

## Test Plan

- [x] `xcodebuild test` on iPhone 17 sim — full suite still passes
- [x] `php -l api/v2/images/cover.php` — no parse errors
- [x] Manual checkpoint against the live server: list, grid, search, filter, add, edit, delete, web round-trip, image rendering, back-cover flip
- [x] No regression on Library tab (CoverImage refactor verified via existing game cover renders)

## Not in scope

Image upload from iOS, `item_images` extras gallery on the detail view, Stats tab, per-game "add completion" button on `GameDetailView`, Items sort menu, Completions year-grouping. Captured for Plan 3d+.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [ ] Every referenced symbol exists: `Item`, `ItemImage`, `SyncState`, `SyncStatus`, `SyncEngine`, `SyncTrigger`, `ImagesAPI`, `APIClient`, `CoverImage`, `ConflictBannerView`, `ConflictListView`, `SyncStateBadge`, `SyncStatusBannerView`, `PlaceholderTabView`. (All landed via Plan 2 / 3a / 3b.)
- [ ] No file is referenced by two different names across tasks.
- [ ] `ItemFormBody` has the same property order and types when called by `AddItemView` (Task 6) and `EditItemView` (Task 7).
- [ ] `ItemsView` filter, sort, and search compose correctly: query is sorted by title; filter narrows in memory by category; search narrows further by title or platform substring.
- [ ] Every existing `CoverImage(gameServerId: …)` call site still compiles after the Task 3 rewrite. (LibraryView, GameDetailView, CompletionsView, CompletionFormBody, CompletionsListRow, GamePickerSheet.)
- [ ] Server-side `cover.php` extension keeps `?type=game` as default — existing iOS clients (every current call site of `downloadCover(gameServerId:…)`) keep working without modification.
- [ ] Cache filename namespace: `cover_<id>_…` for games (unchanged) vs `item_<id>_…` for items (new). No collision possible.
- [ ] All commit messages cover the visible behaviour and bundle interdependent files together (matches Plan 3b's style).
- [ ] No "TBD" or "implement later" left anywhere.
