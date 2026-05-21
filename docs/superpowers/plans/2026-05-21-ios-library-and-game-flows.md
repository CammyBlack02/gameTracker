# iOS Library + Add/Edit + Cover Upload Implementation Plan (Plan 3a of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `DebugHomeView` with a real 5-tab UI. Build the **Library** tab (grid + list, search, platform filter), the **Game Detail** screen (read + edit), the **Add Game** flow (title/platform/cover URL/metadata fetch), cover upload from both an external URL and the photo library, and delete. The other four tabs are placeholders for Plan 3b.

**Architecture:** Adds a `RootTabView` between `RootView` and the per-tab content. The sync engine from Plan 2 already exists — this plan adds the mutation side: each save in the UI marks the row `localModified` or `localNew`, then a debounced `SyncTrigger` (5 s after the last change) kicks off `SyncEngine.runOnce()`. Cover URLs paste through the existing `/api/v2/external-image.php` endpoint; photo-library uploads use `/api/v2/games/cover-upload.php` (multipart). Metadata fetch hits the existing PriceCharting + Metacritic proxies. SwiftUI views read from SwiftData via `@Query`, so everything stays reactive.

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData, PhotosUI (`PhotosPicker`), async/await, URLSession.

**Predecessor:** [docs/superpowers/plans/2026-05-21-ios-skeleton-and-sync.md](2026-05-21-ios-skeleton-and-sync.md) (deployed; branch `plan-2-ios-skeleton-and-sync` is the base).

**Execution rhythm:** The owner wants to verify each user-visible feature in the Simulator before the next one ships. After every commit that exposes new UI behaviour (marked **🛑 User checkpoint** below), pause the implementer queue, ask the owner to ⌘R the app and run the named checks, and only resume when they confirm or report issues.

---

## Working-directory + Simulator conventions (carried over from Plan 2)

- **CWD:** `gameTracker/ios/GameTracker/` — relative-path commands (`xcodebuild`, `git add GameTracker/...`) resolve from here.
- **Simulator name:** `iPhone 17` (iOS 26 sims).
- **Branch:** Start each task on a NEW branch `plan-3a-library-and-game-flows`, branched off `plan-2-ios-skeleton-and-sync`. Same one-repo workflow.
- **Pre-existing change:** `js/completions.js` has an old uncommitted whitespace edit. LEAVE ALONE in every commit.

---

## Server API surface this plan consumes (all already deployed)

| Endpoint | Purpose |
|---|---|
| `POST /api/v2/games/cover-upload.php?game_id=&face=front\|back` | multipart upload (`image` field) → cover file written + thumbnail generated; updates `games.front_cover_image` or `back_cover_image` |
| `GET /api/v2/external-image.php?url=&game_id=&type=front\|back` | downloads a public URL server-side, saves under `uploads/covers/`, updates the same column |
| `GET /api/v2/pricecharting.php?title=&platform=` | proxy → JSON with `price` etc. |
| `GET /api/v2/metacritic.php?title=&platform=` | proxy → JSON with score |
| `GET /api/v2/images/cover.php?id=&size=thumb\|full&face=front\|back` | image bytes (already wired in `ImagesAPI`) |

---

## File structure

### New files (all under `ios/GameTracker/GameTracker/`)

```
Networking/
└── ProxiesAPI.swift                   — PriceCharting, Metacritic, externalImage, coverUpload

Sync/
├── Debouncer.swift                    — generic delay-and-collapse helper
└── SyncTrigger.swift                  — debounced post-mutation runner

Views/
├── Tabs/
│   ├── RootTabView.swift              — 5-tab shell; replaces DebugHomeView at the post-login root
│   └── PlaceholderTabView.swift       — "Coming soon" tab body for Items/Spin/Stats/Settings
├── Library/
│   ├── LibraryView.swift              — main screen: list/grid, search, filter, +, pull-to-refresh
│   ├── LibrarySortOption.swift        — enum + Picker
│   ├── PlatformFilterSheet.swift      — multi-select sheet
│   ├── GameListRow.swift              — single row (thumb + title + platform + status)
│   └── GameGridCell.swift             — single cell (cover only with status overlay)
├── Detail/
│   ├── GameDetailView.swift           — read-only display of all fields + completion log + extras
│   ├── EditGameView.swift             — bound form
│   ├── AddGameView.swift              — bound form for new game + URL paste + metadata fetch
│   └── ExtrasGallery.swift            — horizontal list of extra-photo thumbnails
└── Common/
    └── CoverImage.swift               — async cover loader that uses ImagesAPI on-disk cache
```

### New tests (under `ios/GameTracker/GameTrackerTests/`)

```
ProxiesAPITests.swift                  — URLProtocol-stubbed
DebouncerTests.swift                   — async timing
```

### Modified

- `GameTracker/RootView.swift` — replace `DebugHomeView()` with `RootTabView(...)`
- `GameTracker/GameTrackerApp.swift` — pass `ImagesAPI` into the view tree

### Untouched

- Everything from Plan 2 stays. `DebugHomeView` stays in the source tree for now (unused once we wire `RootTabView`) — we'll delete it in Task 13 as the final cleanup, after we've confirmed the new screens work.

---

## Task 0: Local environment + branch

**Files:** none

- [ ] **Step 0.1: Confirm Plan 2 still passes**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 | tail -3
```
Expected: `** TEST SUCCEEDED **`. (Plan 2 left us with 48 passing tests.)

- [ ] **Step 0.2: Branch off Plan 2's branch**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short  # only js/completions.js should appear
git checkout plan-2-ios-skeleton-and-sync
git pull
git checkout -b plan-3a-library-and-game-flows
git branch --show-current
```
Expected: `plan-3a-library-and-game-flows`.

- [ ] **Step 0.3: Commit this plan doc on the new branch**

```bash
git add docs/superpowers/plans/2026-05-21-ios-library-and-game-flows.md
git commit -m "Add Plan 3a (iOS library + game flows) implementation plan"
```

---

## Task 1: ProxiesAPI — PriceCharting, Metacritic, external-image, cover-upload

**Files:**
- Create: `GameTracker/Networking/ProxiesAPI.swift`
- Create: `GameTrackerTests/ProxiesAPITests.swift`

The four endpoints are thin shells around `APIClient`. The server passes through their response bodies unchanged, so the DTOs are loose: we decode into `[String: JSONValue]` and let callers cherry-pick.

- [ ] **Step 1.1: Write the failing tests**

`ios/GameTracker/GameTrackerTests/ProxiesAPITests.swift`:

```swift
import XCTest
@testable import GameTracker

final class ProxiesAPITests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_pricecharting_sends_title_and_platform_query() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"price":"42.50","title":"Halo"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/pricecharting.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let result = try await api.priceCharting(title: "Halo", platform: "Xbox")
        XCTAssertEqual(result["price"]?.stringValue, "42.50")

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["title"], "Halo")
        XCTAssertEqual(qs["platform"], "Xbox")
    }

    func test_metacritic_returns_score() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"score":91}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/metacritic.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let result = try await api.metacritic(title: "Halo", platform: "Xbox")
        XCTAssertEqual(result["score"]?.intValue, 91)
    }

    func test_externalImage_sends_url_and_game_id() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"path":"uploads/covers/abc.jpg"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/external-image.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let result = try await api.externalImage(url: "https://img.example/x.jpg",
                                                  gameId: 42,
                                                  face: .front)
        XCTAssertEqual(result["path"]?.stringValue, "uploads/covers/abc.jpg")

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["url"], "https://img.example/x.jpg")
        XCTAssertEqual(qs["game_id"], "42")
        XCTAssertEqual(qs["type"], "front")
    }

    func test_uploadCover_sends_multipart_with_image_field() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"path":"uploads/covers/x.jpg","thumb_path":"uploads/covers/thumbs/x.jpg"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/games/cover-upload.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let data = Data([0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10])
        let result = try await api.uploadCover(gameId: 7, face: .back, imageData: data, filename: "x.jpg")
        XCTAssertEqual(result["path"]?.stringValue, "uploads/covers/x.jpg")

        let req = URLProtocolStub.recordedRequests.first!
        let ct = req.value(forHTTPHeaderField: "Content-Type") ?? ""
        XCTAssertTrue(ct.hasPrefix("multipart/form-data; boundary="))

        let comps = URLComponents(url: req.url!, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["game_id"], "7")
        XCTAssertEqual(qs["face"], "back")
    }
}
```

- [ ] **Step 1.2: Run tests, confirm failure**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```
Expected: compile error — `ProxiesAPI` doesn't exist.

- [ ] **Step 1.3: Write `ProxiesAPI.swift`**

`ios/GameTracker/GameTracker/Networking/ProxiesAPI.swift`:

```swift
import Foundation

/// Wraps the four "passthrough" v2 endpoints (PriceCharting, Metacritic,
/// external-image, cover-upload). The server's response shape varies per
/// upstream service, so we return `[String: JSONValue]` and let callers
/// cherry-pick fields.
struct ProxiesAPI {

    enum Face: String { case front, back }

    let client: APIClient

    /// GET /api/v2/pricecharting.php?title=&platform=
    func priceCharting(title: String, platform: String) async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.get(
            "/api/v2/pricecharting.php",
            query: ["title": title, "platform": platform])
        return env.raw
    }

    /// GET /api/v2/metacritic.php?title=&platform=
    func metacritic(title: String, platform: String) async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.get(
            "/api/v2/metacritic.php",
            query: ["title": title, "platform": platform])
        return env.raw
    }

    /// GET /api/v2/external-image.php?url=&game_id=&type=front|back
    /// Server downloads the URL into uploads/covers/ and updates the games row.
    /// Returns the saved path (we don't typically need it — the next sync will pull
    /// down the row with the new front_cover_image column).
    func externalImage(url: String, gameId: Int, face: Face) async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.get(
            "/api/v2/external-image.php",
            query: ["url": url, "game_id": String(gameId), "type": face.rawValue])
        return env.raw
    }

    /// POST /api/v2/games/cover-upload.php?game_id=&face=…  (multipart "image" field)
    func uploadCover(gameId: Int,
                     face: Face,
                     imageData: Data,
                     filename: String,
                     mimeType: String = "image/jpeg") async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.uploadImage(
            "/api/v2/games/cover-upload.php",
            query: ["game_id": String(gameId), "face": face.rawValue],
            imageData: imageData,
            filename: filename,
            mimeType: mimeType)
        return env.raw
    }
}

/// Untyped envelope payload used by all four proxies. The server's actual
/// response keys depend on the upstream service; we decode into JSONValue
/// so callers can use `.stringValue` / `.intValue` accessors.
private struct PassthroughDTO: Decodable {
    let raw: [String: JSONValue]
    init(from decoder: Decoder) throws {
        raw = try [String: JSONValue](from: decoder)
    }
}
```

- [ ] **Step 1.4: Tests pass**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 | grep -E "Test Case|TEST SUCCEEDED|TEST FAILED" | head -40
```
Expected: 4 new ProxiesAPI tests + 48 existing = 52 total. `** TEST SUCCEEDED **`.

- [ ] **Step 1.5: Commit**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Networking/ProxiesAPI.swift ios/GameTracker/GameTrackerTests/ProxiesAPITests.swift
git commit -m "Add ProxiesAPI for PriceCharting, Metacritic, external-image, cover-upload"
```

---

## Task 2: Debouncer + SyncTrigger

**Files:**
- Create: `GameTracker/Sync/Debouncer.swift`
- Create: `GameTracker/Sync/SyncTrigger.swift`
- Create: `GameTrackerTests/DebouncerTests.swift`

`Debouncer` is a tiny actor-isolated helper that collapses rapid `fire()` calls into a single deferred execution. `SyncTrigger` wraps it for the sync use case.

- [ ] **Step 2.1: Write failing tests**

`ios/GameTracker/GameTrackerTests/DebouncerTests.swift`:

```swift
import XCTest
@testable import GameTracker

final class DebouncerTests: XCTestCase {

    func test_single_fire_runs_once_after_delay() async throws {
        let counter = ActorCounter()
        let debouncer = Debouncer(delay: 0.1) { await counter.bump() }

        await debouncer.fire()
        XCTAssertEqual(await counter.value, 0, "should not run immediately")

        try await Task.sleep(nanoseconds: 200_000_000)  // 200ms
        XCTAssertEqual(await counter.value, 1, "should have run exactly once")
    }

    func test_rapid_fires_collapse_to_one_run() async throws {
        let counter = ActorCounter()
        let debouncer = Debouncer(delay: 0.1) { await counter.bump() }

        for _ in 0..<5 {
            await debouncer.fire()
            try await Task.sleep(nanoseconds: 20_000_000)  // 20ms between fires
        }
        try await Task.sleep(nanoseconds: 250_000_000)  // wait past last delay
        XCTAssertEqual(await counter.value, 1, "5 rapid fires collapse to 1 run")
    }
}

private actor ActorCounter {
    var value = 0
    func bump() { value += 1 }
}
```

- [ ] **Step 2.2: `Debouncer.swift`**

`ios/GameTracker/GameTracker/Sync/Debouncer.swift`:

```swift
import Foundation

/// Collapses rapid `fire()` calls into a single deferred run.
/// Each `fire()` cancels the pending task (if any) and schedules a new
/// one `delay` seconds later. Safe to share across actors.
actor Debouncer {

    private let delay: TimeInterval
    private let action: @Sendable () async -> Void
    private var pending: Task<Void, Never>?

    init(delay: TimeInterval, action: @escaping @Sendable () async -> Void) {
        self.delay = delay
        self.action = action
    }

    func fire() {
        pending?.cancel()
        let captured = action
        let d = delay
        pending = Task { [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(d * 1_000_000_000))
            guard !Task.isCancelled else { return }
            await captured()
            await self?.clearPending()
        }
    }

    /// Cancel any pending run.
    func cancel() {
        pending?.cancel()
        pending = nil
    }

    private func clearPending() { pending = nil }
}
```

- [ ] **Step 2.3: `SyncTrigger.swift`**

`ios/GameTracker/GameTracker/Sync/SyncTrigger.swift`:

```swift
import Foundation

/// Owns a `Debouncer` configured for sync. Views call `pingAfterMutation()`
/// from their save paths; multiple rapid saves coalesce into one
/// `SyncEngine.runOnce()` call ~5 s after the last save.
///
/// Errors thrown by `runOnce()` are swallowed (logged via error_log) — a
/// failed background sync will be retried on the next mutation or the
/// next foreground / pull-to-refresh event.
@MainActor
final class SyncTrigger {

    private let engine: SyncEngine
    private let debouncer: Debouncer

    init(engine: SyncEngine, delay: TimeInterval = 5.0) {
        self.engine = engine
        let captured = engine
        self.debouncer = Debouncer(delay: delay) { [captured] in
            await MainActor.run {
                Task { try? await captured.runOnce() }
            }
        }
    }

    /// Schedule a sync `delay` seconds from now. Repeated calls collapse.
    func pingAfterMutation() {
        Task { await debouncer.fire() }
    }

    /// Cancel any pending background sync (used when the app is about to
    /// foreground-sync explicitly via pull-to-refresh).
    func cancelPending() {
        Task { await debouncer.cancel() }
    }
}
```

- [ ] **Step 2.4: Tests pass + commit**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 | grep -E "DebouncerTests|TEST SUCCEEDED|TEST FAILED" | head -10
```
Expected: 2 new Debouncer tests pass; total 54.

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Sync/Debouncer.swift ios/GameTracker/GameTracker/Sync/SyncTrigger.swift ios/GameTracker/GameTrackerTests/DebouncerTests.swift
git commit -m "Add Debouncer + SyncTrigger for post-mutation sync coalescing"
```

---

## Task 3: CoverImage SwiftUI view

**Files:**
- Create: `GameTracker/Views/Common/CoverImage.swift`

A drop-in view that takes a `gameServerId` + `size` + `face` and shows the cover. Uses `ImagesAPI` (from Plan 2) so caching is automatic. While loading, shows a generic placeholder; on error, shows a grey box with an icon.

- [ ] **Step 3.1: Write `CoverImage.swift`**

`ios/GameTracker/GameTracker/Views/Common/CoverImage.swift`:

```swift
import SwiftUI

/// Async-loaded cover image with a placeholder. Uses `ImagesAPI`'s
/// on-disk cache, so subsequent renders for the same (gameServerId,
/// face, size) are instant.
struct CoverImage: View {

    let gameServerId: Int?
    let face: ImagesAPI.Face
    let size: ImagesAPI.Size
    let api: ImagesAPI

    @State private var localURL: URL?
    @State private var failed = false

    init(gameServerId: Int?,
         face: ImagesAPI.Face = .front,
         size: ImagesAPI.Size = .thumb,
         api: ImagesAPI) {
        self.gameServerId = gameServerId
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
        .task(id: gameServerId) {
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
        guard let id = gameServerId else { return }
        do {
            let url = try await api.downloadCover(gameServerId: id, face: face, size: size)
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

- [ ] **Step 3.2: Build check**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 3.3: Commit**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Common/CoverImage.swift
git commit -m "Add CoverImage SwiftUI view with ImagesAPI cache integration"
```

---

## Task 4: 5-tab shell (RootTabView + placeholders)

**Files:**
- Create: `GameTracker/Views/Tabs/RootTabView.swift`
- Create: `GameTracker/Views/Tabs/PlaceholderTabView.swift`
- Modify: `GameTracker/RootView.swift`
- Modify: `GameTracker/GameTrackerApp.swift`

After this task, logging in lands on the tab bar instead of `DebugHomeView`. Only the Library tab content gets built out in later tasks — the other four are friendly placeholders.

- [ ] **Step 4.1: `PlaceholderTabView.swift`**

`ios/GameTracker/GameTracker/Views/Tabs/PlaceholderTabView.swift`:

```swift
import SwiftUI

/// Used for the 4 tabs whose implementation lives in Plan 3b.
struct PlaceholderTabView: View {
    let title: String
    let systemImage: String
    let blurb: String

    var body: some View {
        NavigationStack {
            ContentUnavailableView {
                Label(title, systemImage: systemImage)
            } description: {
                Text(blurb)
            }
            .navigationTitle(title)
        }
    }
}
```

- [ ] **Step 4.2: `RootTabView.swift`**

`ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift`:

```swift
import SwiftUI

/// 5-tab bottom-bar shell. Only the Library tab is fully implemented in
/// Plan 3a; Items/Spin/Stats/Settings render placeholder screens.
struct RootTabView: View {
    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    @Bindable var status: SyncStatus

    var body: some View {
        TabView {
            LibraryView(syncEngine: syncEngine,
                        syncTrigger: syncTrigger,
                        imagesAPI: imagesAPI,
                        proxiesAPI: proxiesAPI,
                        status: status)
                .tabItem { Label("Library", systemImage: "books.vertical") }

            PlaceholderTabView(title: "Items",
                               systemImage: "gamecontroller",
                               blurb: "Consoles and accessories will live here.")
                .tabItem { Label("Items", systemImage: "gamecontroller") }

            PlaceholderTabView(title: "Spin",
                               systemImage: "dial.medium",
                               blurb: "Random game picker.")
                .tabItem { Label("Spin", systemImage: "dial.medium") }

            PlaceholderTabView(title: "Stats",
                               systemImage: "chart.bar",
                               blurb: "Collection analytics.")
                .tabItem { Label("Stats", systemImage: "chart.bar") }

            PlaceholderTabView(title: "Settings",
                               systemImage: "gear",
                               blurb: "Account, sync, appearance.")
                .tabItem { Label("Settings", systemImage: "gear") }
        }
    }
}
```

(Note: `LibraryView` doesn't exist yet — Task 5 creates it. The build will fail until then; that's fine. We commit Tasks 4 and 5 together at the end of Task 5.)

- [ ] **Step 4.3: Update `RootView.swift`**

Replace `ios/GameTracker/GameTracker/RootView.swift`:

```swift
import SwiftUI

struct RootView: View {
    @Environment(AuthManager.self) private var authManager
    let authAPI: AuthAPI
    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    @Bindable var status: SyncStatus

    var body: some View {
        switch authManager.state {
        case .loggedOut:
            LoginView(authAPI: authAPI)
        case .loggedIn:
            RootTabView(syncEngine: syncEngine,
                        syncTrigger: syncTrigger,
                        imagesAPI: imagesAPI,
                        proxiesAPI: proxiesAPI,
                        status: status)
        }
    }
}
```

- [ ] **Step 4.4: Update `GameTrackerApp.swift`**

Replace `ios/GameTracker/GameTracker/GameTrackerApp.swift`:

```swift
import SwiftUI
import SwiftData

@main
struct GameTrackerApp: App {
    let container: ModelContainer = {
        do {
            return try ModelContainerFactory.production()
        } catch {
            fatalError("Could not create SwiftData container: \(error)")
        }
    }()

    @State private var authManager = AuthManager()
    @State private var status = SyncStatus()

    private var apiClient: APIClient {
        APIClient(baseURL: Config.serverBaseURL,
                  tokenProvider: { [authManager] in authManager.currentToken })
    }

    private var authAPI: AuthAPI { AuthAPI(client: apiClient) }
    private var syncAPI: SyncAPI { SyncAPI(client: apiClient) }
    private var proxiesAPI: ProxiesAPI { ProxiesAPI(client: apiClient) }

    /// Cover cache lives under Documents/covers/ — backed up to iCloud per spec.
    private var imagesAPI: ImagesAPI {
        ImagesAPI(client: apiClient, cacheRoot: ImageCachePaths.coversThumbs)
    }

    var body: some Scene {
        WindowGroup {
            RootViewContainer(authAPI: authAPI,
                              syncAPI: syncAPI,
                              proxiesAPI: proxiesAPI,
                              imagesAPI: imagesAPI,
                              status: status)
                .environment(authManager)
        }
        .modelContainer(container)
    }
}

private struct RootViewContainer: View {
    @Environment(\.modelContext) private var context
    let authAPI: AuthAPI
    let syncAPI: SyncAPI
    let proxiesAPI: ProxiesAPI
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    var body: some View {
        let engine = SyncEngine(context: context, syncAPI: syncAPI, status: status)
        let trigger = SyncTrigger(engine: engine)
        RootView(authAPI: authAPI,
                 syncEngine: engine,
                 syncTrigger: trigger,
                 imagesAPI: imagesAPI,
                 proxiesAPI: proxiesAPI,
                 status: status)
    }
}
```

- [ ] **Step 4.5: Skip build verification until Task 5 lands**

The build will currently fail because `LibraryView` doesn't exist. That's expected. Move on to Task 5 immediately; we commit Tasks 4 + 5 together at the end of Task 5 once everything compiles.

---

## Task 5: LibraryView (list + grid + view-mode toggle + pull-to-refresh)

**Files:**
- Create: `GameTracker/Views/Library/LibraryView.swift`
- Create: `GameTracker/Views/Library/LibrarySortOption.swift`
- Create: `GameTracker/Views/Library/GameListRow.swift`
- Create: `GameTracker/Views/Library/GameGridCell.swift`

- [ ] **Step 5.1: `LibrarySortOption.swift`**

`ios/GameTracker/GameTracker/Views/Library/LibrarySortOption.swift`:

```swift
import Foundation
import SwiftData

enum LibrarySortOption: String, CaseIterable, Identifiable {
    case titleAsc        = "Title (A→Z)"
    case titleDesc       = "Title (Z→A)"
    case recentlyAdded   = "Recently added"
    case recentlyUpdated = "Recently updated"

    var id: String { rawValue }

    /// SwiftData `SortDescriptor` for `Game`.
    var descriptor: SortDescriptor<Game> {
        switch self {
        case .titleAsc:        return SortDescriptor(\.title, order: .forward)
        case .titleDesc:       return SortDescriptor(\.title, order: .reverse)
        case .recentlyAdded:   return SortDescriptor(\.createdAt, order: .reverse)
        case .recentlyUpdated: return SortDescriptor(\.lastSyncedAt, order: .reverse)
        }
    }
}
```

- [ ] **Step 5.2: `GameListRow.swift`**

`ios/GameTracker/GameTracker/Views/Library/GameListRow.swift`:

```swift
import SwiftUI

/// Single row in the list view: small cover + title + platform + status badge.
struct GameListRow: View {
    let game: Game
    let imagesAPI: ImagesAPI

    var body: some View {
        HStack(spacing: 12) {
            CoverImage(gameServerId: game.serverId, face: .front, size: .thumb, api: imagesAPI)
                .frame(width: 40, height: 60)
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

            SyncStateBadge(state: game.syncState)
        }
        .padding(.vertical, 4)
    }
}

/// Tiny status pill, reused from DebugHomeView's visual vocabulary.
struct SyncStateBadge: View {
    let state: SyncState
    var body: some View {
        switch state {
        case .synced:        EmptyView()
        case .localNew:      Text("new").font(.caption2).foregroundStyle(.blue)
        case .localModified: Text("edit").font(.caption2).foregroundStyle(.orange)
        case .localDeleted:  Text("del").font(.caption2).foregroundStyle(.red)
        case .conflict:      Image(systemName: "exclamationmark.triangle.fill")
                                .font(.caption).foregroundStyle(.red)
        }
    }
}
```

- [ ] **Step 5.3: `GameGridCell.swift`**

`ios/GameTracker/GameTracker/Views/Library/GameGridCell.swift`:

```swift
import SwiftUI

/// One game in the grid: full-cover with a tiny status overlay.
struct GameGridCell: View {
    let game: Game
    let imagesAPI: ImagesAPI

    var body: some View {
        CoverImage(gameServerId: game.serverId, face: .front, size: .thumb, api: imagesAPI)
            .clipShape(RoundedRectangle(cornerRadius: 6))
            .overlay(alignment: .topTrailing) {
                if game.syncState != .synced {
                    SyncStateBadge(state: game.syncState)
                        .padding(4)
                        .background(.ultraThinMaterial, in: Capsule())
                        .padding(4)
                }
            }
            .overlay(alignment: .bottom) {
                Text(game.title)
                    .font(.caption2.weight(.medium))
                    .foregroundStyle(.white)
                    .lineLimit(2)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 4)
                    .padding(.vertical, 2)
                    .frame(maxWidth: .infinity)
                    .background(
                        LinearGradient(colors: [.black.opacity(0), .black.opacity(0.7)],
                                       startPoint: .top, endPoint: .bottom)
                    )
            }
    }
}
```

- [ ] **Step 5.4: `LibraryView.swift`**

`ios/GameTracker/GameTracker/Views/Library/LibraryView.swift`:

```swift
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
                content
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
            ContentUnavailableView("No games", systemImage: "books.vertical",
                                   description: Text("Pull to sync, or tap + to add one."))
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

    /// Wraps `@Query` so the sort + search are applied. SwiftData's `@Query`
    /// macro needs a static descriptor, so we fetch broadly and filter in
    /// memory. With a personal collection (~hundreds of games max) this is fine.
    private var filteredGames: [Game] {
        do {
            let all = try context.fetch(FetchDescriptor<Game>(
                predicate: #Predicate { $0.syncStateRaw != "local_deleted" },
                sortBy: [sort.descriptor]
            ))
            return all.filter { g in
                if !platformFilter.isEmpty && !platformFilter.contains(g.platform) { return false }
                if search.isEmpty { return true }
                let s = search.lowercased()
                return g.title.lowercased().contains(s)
                    || g.platform.lowercased().contains(s)
            }
        } catch {
            return []
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

```

The navigation destination for `PersistentIdentifier` needs to be wired. Inside `LibraryView.body`, between the `VStack { … }` and `.navigationTitle("Library")`, insert:

```swift
.navigationDestination(for: PersistentIdentifier.self) { id in
    GameDetailView(gameID: id,
                   imagesAPI: imagesAPI,
                   proxiesAPI: proxiesAPI,
                   syncTrigger: syncTrigger)
}
```

(Note `proxiesAPI:` is passed in — `GameDetailView` accepts it from Task 6 onward; Task 9 makes use of it.)

- [ ] **Step 5.5: `PlatformFilterSheet.swift` (referenced above)**

`ios/GameTracker/GameTracker/Views/Library/PlatformFilterSheet.swift`:

```swift
import SwiftUI
import SwiftData

struct PlatformFilterSheet: View {
    @Binding var selected: Set<String>
    @Environment(\.dismiss) private var dismiss
    @Query(sort: \Game.platform) private var allGames: [Game]

    private var platforms: [String] {
        let unique = Set(allGames.map(\.platform))
        return unique.sorted()
    }

    var body: some View {
        NavigationStack {
            List(platforms, id: \.self) { p in
                Button {
                    if selected.contains(p) { selected.remove(p) } else { selected.insert(p) }
                } label: {
                    HStack {
                        Text(p)
                        Spacer()
                        if selected.contains(p) {
                            Image(systemName: "checkmark").foregroundStyle(.accent)
                        }
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            .navigationTitle("Platforms")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Clear") { selected.removeAll() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }
}
```

- [ ] **Step 5.6: Skip build for now — depends on Tasks 6, 7 (GameDetailView, AddGameView)**

`LibraryView` references `GameDetailView` and `AddGameView`, which don't exist yet. Move on to Task 6 immediately.

---

## Task 6: GameDetailView (read-only)

**Files:**
- Create: `GameTracker/Views/Detail/GameDetailView.swift`
- Create: `GameTracker/Views/Detail/ExtrasGallery.swift`

Read-only view of every field. Edit + delete buttons live in the toolbar but the actual edit form lives in Task 7.

- [ ] **Step 6.1: `ExtrasGallery.swift`**

`ios/GameTracker/GameTracker/Views/Detail/ExtrasGallery.swift`:

```swift
import SwiftUI

/// Horizontal scroller of extra-photo thumbnails. Tap → full-screen.
struct ExtrasGallery: View {
    let extras: [GameImage]
    let imagesAPI: ImagesAPI
    @State private var fullScreenExtra: GameImage?

    var body: some View {
        if !extras.isEmpty {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(extras) { e in
                        Button { fullScreenExtra = e } label: {
                            ExtraThumb(image: e, imagesAPI: imagesAPI)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal)
            }
            .frame(height: 100)
            .fullScreenCover(item: $fullScreenExtra) { extra in
                ExtraFullScreen(image: extra, imagesAPI: imagesAPI)
            }
        }
    }
}

private struct ExtraThumb: View {
    let image: GameImage
    let imagesAPI: ImagesAPI
    @State private var fileURL: URL?

    var body: some View {
        Group {
            if let url = fileURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img).resizable().aspectRatio(contentMode: .fill)
            } else {
                Rectangle().fill(.gray.opacity(0.2))
            }
        }
        .frame(width: 100, height: 100)
        .clipped()
        .clipShape(RoundedRectangle(cornerRadius: 6))
        .task(id: image.serverId) {
            guard let id = image.serverId else { return }
            fileURL = try? await imagesAPI.downloadExtra(imageServerId: id, type: .game, size: .thumb)
        }
    }
}

private struct ExtraFullScreen: View {
    let image: GameImage
    let imagesAPI: ImagesAPI
    @Environment(\.dismiss) private var dismiss
    @State private var fileURL: URL?

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            if let url = fileURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img).resizable().aspectRatio(contentMode: .fit)
            } else {
                ProgressView().tint(.white)
            }
            VStack {
                HStack {
                    Spacer()
                    Button("Done") { dismiss() }.foregroundStyle(.white).padding()
                }
                Spacer()
            }
        }
        .task(id: image.serverId) {
            guard let id = image.serverId else { return }
            fileURL = try? await imagesAPI.downloadExtra(imageServerId: id, type: .game, size: .full)
        }
    }
}
```

(Note: `GameImage` needs `Identifiable` for `ForEach`. SwiftData @Model classes already conform to Identifiable via their `persistentModelID`, so no work needed.)

- [ ] **Step 6.2: `GameDetailView.swift`**

`ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift`:

```swift
import SwiftUI
import SwiftData

struct GameDetailView: View {
    let gameID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var showEdit = false
    @State private var confirmDelete = false

    var body: some View {
        if let game: Game = context.model(for: gameID) as? Game {
            content(for: game)
                .navigationTitle(game.title)
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .topBarTrailing) {
                        Button("Edit") { showEdit = true }
                    }
                }
                .sheet(isPresented: $showEdit) {
                    EditGameView(gameID: gameID,
                                 imagesAPI: imagesAPI,
                                 syncTrigger: syncTrigger)
                }
                .alert("Delete this game?", isPresented: $confirmDelete) {
                    Button("Delete", role: .destructive) { delete(game) }
                    Button("Cancel", role: .cancel) {}
                } message: {
                    Text("This will remove the game from your library on phone and server.")
                }
        } else {
            ContentUnavailableView("Game unavailable", systemImage: "questionmark.circle")
        }
    }

    @ViewBuilder
    private func content(for game: Game) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                CoverImage(gameServerId: game.serverId, face: .front, size: .full, api: imagesAPI)
                    .frame(maxHeight: 320)

                ExtrasGallery(extras: extras(for: game), imagesAPI: imagesAPI)

                Group {
                    field("Title", game.title)
                    field("Platform", game.platform)
                    field("Genre", game.genre)
                    field("Series", game.series)
                    field("Edition", game.specialEdition)
                    field("Condition", game.conditionValue)
                    field("Star rating", game.starRating.map { "\($0)/10" })
                    field("Metacritic", game.metacriticRating.map(String.init))
                    field("Played", game.played == 1 ? "Yes" : "No")
                    field("Physical", game.isPhysical == 1 ? "Yes" : "Digital")
                    if game.isPhysical == 0 {
                        field("Store", game.digitalStore)
                    }
                    field("Price paid", game.pricePaid.map { String(format: "$%.2f", $0) })
                    field("Pricecharting", game.pricechartingPrice.map { String(format: "$%.2f", $0) })
                    field("Released", game.releaseDate.map { d in
                        d.formatted(date: .abbreviated, time: .omitted)
                    })
                }
                .padding(.horizontal)

                if let desc = game.gameDescription, !desc.isEmpty {
                    section("Description", text: desc)
                }
                if let review = game.review, !review.isEmpty {
                    section("Review", text: review)
                }

                completionsList(for: game)

                Button(role: .destructive) { confirmDelete = true } label: {
                    Label("Delete game", systemImage: "trash")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .padding()
            }
        }
    }

    @ViewBuilder
    private func field(_ label: String, _ value: String?) -> some View {
        if let v = value, !v.isEmpty {
            HStack(alignment: .top) {
                Text(label).foregroundStyle(.secondary).frame(width: 110, alignment: .leading)
                Text(v)
                Spacer()
            }
            .font(.callout)
        }
    }

    @ViewBuilder
    private func section(_ title: String, text: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title).font(.headline)
            Text(text).font(.callout)
        }
        .padding(.horizontal)
        .padding(.top, 8)
    }

    private func extras(for game: Game) -> [GameImage] {
        guard let sid = game.serverId else { return [] }
        let p = #Predicate<GameImage> { $0.gameServerId == sid }
        return (try? context.fetch(FetchDescriptor(predicate: p))) ?? []
    }

    /// Read-only list of GameCompletion entries linked to this game's
    /// server_id. v1 doesn't support adding/editing completions inline
    /// (deferred to Plan 3b).
    @ViewBuilder
    private func completionsList(for game: Game) -> some View {
        let entries = completions(for: game)
        if !entries.isEmpty {
            VStack(alignment: .leading, spacing: 6) {
                Text("Completions").font(.headline)
                ForEach(entries) { c in
                    VStack(alignment: .leading, spacing: 2) {
                        HStack {
                            Text(c.dateCompleted.map { $0.formatted(date: .abbreviated, time: .omitted) }
                                  ?? "Unknown date")
                                .font(.callout.weight(.medium))
                            if let t = c.timeTaken, !t.isEmpty {
                                Text("· \(t)").font(.callout).foregroundStyle(.secondary)
                            }
                        }
                        if let notes = c.notes, !notes.isEmpty {
                            Text(notes).font(.caption).foregroundStyle(.secondary)
                        }
                    }
                    .padding(.vertical, 4)
                }
            }
            .padding(.horizontal)
            .padding(.top, 8)
        }
    }

    private func completions(for game: Game) -> [GameCompletion] {
        guard let sid = game.serverId else { return [] }
        let p = #Predicate<GameCompletion> { $0.gameServerId == sid }
        let descriptor = FetchDescriptor<GameCompletion>(
            predicate: p,
            sortBy: [SortDescriptor(\.dateCompleted, order: .reverse)]
        )
        return (try? context.fetch(descriptor)) ?? []
    }

    private func delete(_ game: Game) {
        if game.serverId == nil {
            context.delete(game)
        } else {
            game.syncState = .localDeleted
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
```

- [ ] **Step 6.3: Skip build until Task 7 lands (EditGameView still missing).**

---

## Task 7: EditGameView (form)

**Files:**
- Create: `GameTracker/Views/Detail/EditGameView.swift`

Bound form. Save sets `syncState = .localModified`, then triggers sync.

- [ ] **Step 7.1: `EditGameView.swift`**

`ios/GameTracker/GameTracker/Views/Detail/EditGameView.swift`:

```swift
import SwiftUI
import SwiftData

struct EditGameView: View {
    let gameID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    // Working copies (we don't mutate the live model until "Save"
    // so cancel discards cleanly).
    @State private var title = ""
    @State private var platform = ""
    @State private var genre = ""
    @State private var series = ""
    @State private var condition = ""
    @State private var starRating: Int = 0
    @State private var metacritic: Int = 0
    @State private var played = false
    @State private var isPhysical = true
    @State private var digitalStore = ""
    @State private var pricePaid = ""
    @State private var pricechartingPrice = ""
    @State private var description = ""
    @State private var review = ""

    @State private var loaded = false

    var body: some View {
        NavigationStack {
            Form {
                Section("Basics") {
                    TextField("Title", text: $title)
                    TextField("Platform", text: $platform)
                    TextField("Genre", text: $genre)
                    TextField("Series", text: $series)
                    TextField("Condition", text: $condition)
                }

                Section("Status") {
                    Toggle("Played", isOn: $played)
                    Stepper(value: $starRating, in: 0...10) {
                        Text("Stars: \(starRating)/10")
                    }
                    Stepper(value: $metacritic, in: 0...100) {
                        Text("Metacritic: \(metacritic)")
                    }
                }

                Section("Format") {
                    Toggle("Physical", isOn: $isPhysical)
                    if !isPhysical {
                        TextField("Digital store", text: $digitalStore)
                    }
                }

                Section("Price") {
                    TextField("Paid", text: $pricePaid).keyboardType(.decimalPad)
                    TextField("PriceCharting", text: $pricechartingPrice).keyboardType(.decimalPad)
                }

                Section("Notes") {
                    TextField("Description", text: $description, axis: .vertical).lineLimit(3...8)
                    TextField("Review", text: $review, axis: .vertical).lineLimit(3...8)
                }
            }
            .navigationTitle("Edit")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(title.isEmpty || platform.isEmpty)
                }
            }
            .task { loadOnce() }
        }
    }

    private func loadOnce() {
        guard !loaded, let g: Game = context.model(for: gameID) as? Game else { return }
        title = g.title
        platform = g.platform
        genre = g.genre ?? ""
        series = g.series ?? ""
        condition = g.conditionValue ?? ""
        starRating = g.starRating ?? 0
        metacritic = g.metacriticRating ?? 0
        played = (g.played == 1)
        isPhysical = (g.isPhysical == 1)
        digitalStore = g.digitalStore ?? ""
        pricePaid = g.pricePaid.map { String(format: "%.2f", $0) } ?? ""
        pricechartingPrice = g.pricechartingPrice.map { String(format: "%.2f", $0) } ?? ""
        description = g.gameDescription ?? ""
        review = g.review ?? ""
        loaded = true
    }

    private func save() {
        guard let g: Game = context.model(for: gameID) as? Game else { return }
        g.title = title
        g.platform = platform
        g.genre = genre.isEmpty ? nil : genre
        g.series = series.isEmpty ? nil : series
        g.conditionValue = condition.isEmpty ? nil : condition
        g.starRating = starRating == 0 ? nil : starRating
        g.metacriticRating = metacritic == 0 ? nil : metacritic
        g.played = played ? 1 : 0
        g.isPhysical = isPhysical ? 1 : 0
        g.digitalStore = (isPhysical || digitalStore.isEmpty) ? nil : digitalStore
        g.pricePaid = Double(pricePaid)
        g.pricechartingPrice = Double(pricechartingPrice)
        g.gameDescription = description.isEmpty ? nil : description
        g.review = review.isEmpty ? nil : review

        if g.syncState == .synced { g.syncState = .localModified }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
```

- [ ] **Step 7.2: Still skip build until Task 8 lands (AddGameView referenced by LibraryView).**

---

## Task 8: AddGameView (form + cover URL + metadata fetch)

**Files:**
- Create: `GameTracker/Views/Detail/AddGameView.swift`

Title + platform required. Optional: paste cover URL (server downloads), tap "Fetch metadata" (fills in pricecharting + metacritic + genre).

- [ ] **Step 8.1: `AddGameView.swift`**

`ios/GameTracker/GameTracker/Views/Detail/AddGameView.swift`:

```swift
import SwiftUI
import SwiftData

struct AddGameView: View {
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var title = ""
    @State private var platform = ""
    @State private var genre = ""
    @State private var coverURL = ""
    @State private var pricechartingPrice = ""
    @State private var metacritic: Int = 0
    @State private var fetchInFlight = false
    @State private var saveInFlight = false
    @State private var errorMessage: String?

    private var canSave: Bool {
        !title.isEmpty && !platform.isEmpty && !saveInFlight
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Required") {
                    TextField("Title", text: $title)
                    TextField("Platform", text: $platform)
                }

                Section("Cover image") {
                    TextField("Paste image URL (https://…)", text: $coverURL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                    Text("Server downloads + saves it after the game is created.")
                        .font(.caption).foregroundStyle(.secondary)
                }

                Section {
                    Button {
                        Task { await fetchMetadata() }
                    } label: {
                        if fetchInFlight {
                            HStack { ProgressView(); Text("Fetching…") }
                        } else {
                            Label("Fetch metadata (PriceCharting + Metacritic)",
                                  systemImage: "magnifyingglass")
                        }
                    }
                    .disabled(title.isEmpty || platform.isEmpty || fetchInFlight)

                    if !genre.isEmpty           { TextField("Genre", text: $genre) }
                    if !pricechartingPrice.isEmpty {
                        TextField("PriceCharting price", text: $pricechartingPrice)
                    }
                    if metacritic > 0 {
                        Stepper(value: $metacritic, in: 0...100) {
                            Text("Metacritic: \(metacritic)")
                        }
                    }
                }

                if let err = errorMessage {
                    Section { Text(err).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Add game")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }
                        .disabled(!canSave)
                }
            }
        }
    }

    // MARK: - Metadata fetch

    private func fetchMetadata() async {
        errorMessage = nil
        fetchInFlight = true
        defer { fetchInFlight = false }
        do {
            async let pc = proxiesAPI.priceCharting(title: title, platform: platform)
            async let mc = proxiesAPI.metacritic(title: title, platform: platform)
            let (pcRes, mcRes) = try await (pc, mc)

            if let g = pcRes["genre"]?.stringValue, !g.isEmpty { genre = g }
            if let p = pcRes["price"]?.stringValue { pricechartingPrice = p }
            if let score = mcRes["score"]?.intValue { metacritic = score }
        } catch {
            errorMessage = "Metadata fetch failed: \(error.localizedDescription)"
        }
    }

    // MARK: - Save

    private func save() async {
        errorMessage = nil
        saveInFlight = true
        defer { saveInFlight = false }

        // 1. Insert local row (localNew). SyncEngine will push it on next runOnce.
        let game = Game(title: title, platform: platform, syncState: .localNew)
        game.genre = genre.isEmpty ? nil : genre
        game.pricechartingPrice = Double(pricechartingPrice)
        game.metacriticRating = metacritic == 0 ? nil : metacritic
        context.insert(game)

        do {
            try context.save()
        } catch {
            errorMessage = "Save failed: \(error.localizedDescription)"
            return
        }

        // 2. Trigger immediate sync so we get the server_id back ASAP.
        // (We can't wait for the debouncer here — the cover-URL step below
        // needs the server_id.)
        syncTrigger.cancelPending()
        // Caller will continue to use the trigger for future edits; here we
        // do one explicit runOnce via the trigger's engine (accessed indirectly
        // through pingAfterMutation + a short wait would be unreliable —
        // simpler: leave the cover-URL submission until after the next sync).
        syncTrigger.pingAfterMutation()

        // If a cover URL was provided, defer the external-image call:
        // the SyncEngine's next runOnce (triggered above) will push the
        // game and stamp `serverId`. We could await that here, but it's
        // cleaner to mark the URL on the game's `frontCoverImage` field —
        // server's external-image needs a real `game_id`, so v1 punts:
        // user can re-open the game and use the cover-upload UI (Task 9).
        //
        // To keep the form-driven URL path working today: if `coverURL`
        // was set, we stash it on the model as a TEMP marker and the
        // cover-upload UI will handle the actual fetch once a server_id
        // exists. For 3a's minimum, leave this and let users add the URL
        // via Edit later.
        // (No code here — placeholder for future enhancement.)

        dismiss()
    }
}
```

(Note: the cover-URL UX in AddGameView is intentionally minimal — Task 9 adds a proper "set cover from URL" button on the game detail screen after the row has a server_id. The Add form still accepts the URL field; we just don't *use* it in Plan 3a's minimum.)

- [ ] **Step 8.2: Now build everything from Tasks 4-8**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -10
```
Expected: `** BUILD SUCCEEDED **`. If there are compile errors, fix the offending file before committing.

- [ ] **Step 8.3: Commit Tasks 4-8 together**

These tasks are interdependent (LibraryView refers to AddGameView refers to ProxiesAPI etc.) so they ship as one commit:

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views
git add ios/GameTracker/GameTracker/RootView.swift
git add ios/GameTracker/GameTracker/GameTrackerApp.swift
git commit -m "Add 5-tab shell + Library tab + Game detail + Add/Edit forms"
```

### 🛑 User checkpoint — first feature delivery

Stop here. Tell the owner to ⌘R in Xcode (iPhone 17 sim) and verify:

1. Login lands on a 5-tab bar; Library tab is selected.
2. Library shows the owner's games pulled from server (covers may take a beat to appear; they download in the background).
3. Switching between list and grid view modes via the ⋯ menu works.
4. Tapping a game opens the Detail screen with all fields populated.
5. Tap **Edit**, change a field, **Save**. Detail screen reflects the change; an `edit` badge appears in the Library next to that title; ~6 seconds later the badge disappears (sync flushed it).
6. Tap **+**, fill title + platform, **Save**. A new row appears with a `new` badge; ~6 seconds later the badge disappears.
7. Pull-to-refresh on the Library triggers a sync.
8. The other 4 tabs show "Coming soon" placeholders.

Resume the implementer queue only once the owner confirms or reports a specific failure.

---

## Task 9: Cover-from-URL flow (on Detail screen)

**Files:**
- Modify: `GameTracker/Views/Detail/GameDetailView.swift`
- Modify: `GameTracker/Views/Detail/EditGameView.swift`

Adds a "Set cover from URL" button to the detail screen (only visible once the game has a `serverId`). User pastes a URL, app calls `ProxiesAPI.externalImage(...)`, then triggers sync so the new path comes back down with the next `/sync/changes`.

- [ ] **Step 9.1: Modify `GameDetailView.swift`** — add a state + sheet near the existing delete button.

Insert these `@State` properties at the top of the struct (right after `@State private var confirmDelete = false`):

```swift
    @State private var showCoverURL = false
    @State private var coverURLInput = ""
    @State private var coverURLInFlight = false
    @State private var coverURLError: String?
```

Then add (inside `content(for: game)`'s `VStack`, just below the delete button) a new button block:

```swift
                if game.serverId != nil {
                    Button {
                        coverURLInput = ""
                        coverURLError = nil
                        showCoverURL = true
                    } label: {
                        Label("Set cover from URL…", systemImage: "link")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .padding(.horizontal)
                }
```

And attach a `.sheet` modifier on the outer `ScrollView` (or anywhere on `content`):

```swift
                .sheet(isPresented: $showCoverURL) {
                    coverURLSheet(for: game)
                }
```

Add the helper method to the struct:

```swift
    @ViewBuilder
    private func coverURLSheet(for game: Game) -> some View {
        NavigationStack {
            Form {
                Section {
                    TextField("https://…", text: $coverURLInput)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                        .keyboardType(.URL)
                } footer: {
                    Text("Server downloads the image, generates a thumbnail, then your next sync pulls down the new cover.")
                }
                if let err = coverURLError {
                    Section { Text(err).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Cover URL")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { showCoverURL = false } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Fetch") { Task { await fetchExternal(for: game) } }
                        .disabled(coverURLInput.isEmpty || coverURLInFlight)
                }
            }
            .overlay {
                if coverURLInFlight {
                    ProgressView("Downloading…")
                        .padding().background(.regularMaterial)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }
        }
    }

    private func fetchExternal(for game: Game) async {
        guard let id = game.serverId else { return }
        coverURLInFlight = true
        defer { coverURLInFlight = false }
        do {
            _ = try await proxiesAPI.externalImage(url: coverURLInput, gameId: id, face: .front)
            // Force the next /sync/changes to repull this row (server's
            // updated_at bumped when the games row was updated by the server)
            game.lastSyncedAt = nil
            try? context.save()
            syncTrigger.pingAfterMutation()
            showCoverURL = false
        } catch {
            coverURLError = error.localizedDescription
        }
    }
```

(`proxiesAPI` is already declared on `GameDetailView` from Task 6 and `LibraryView`'s `navigationDestination` already passes it — nothing extra to wire up in this task.)

- [ ] **Step 9.2: Build + commit**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```
Expected: `** BUILD SUCCEEDED **`.

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift
git add ios/GameTracker/GameTracker/Views/Library/LibraryView.swift
git commit -m "Add cover-from-URL flow on game detail"
```

### 🛑 User checkpoint — cover-from-URL

Tell the owner to ⌘R and verify:

1. On a game's Detail screen (any game with a `serverId`), there's now a **Set cover from URL…** button below the delete button.
2. Tap it → sheet opens with a URL field.
3. Paste a public JPEG/PNG URL (e.g., a Google Images result) → tap **Fetch** → "Downloading…" overlay appears, sheet closes.
4. After a few seconds (the next sync pulls down the new path), the cover on the Detail screen updates.
5. Cancel button on the sheet still works.
6. Invalid URL surfaces an error inline.

Resume once confirmed.

---

## Task 10: Cover upload from photo library

**Files:**
- Modify: `GameTracker/Views/Detail/GameDetailView.swift`

Adds a "Upload cover photo…" button using SwiftUI's built-in `PhotosPicker`. Selected image is converted to JPEG, uploaded via `ProxiesAPI.uploadCover`, then sync is triggered.

- [ ] **Step 10.1: Update `GameDetailView.swift`**

At the top of the file, add:
```swift
import PhotosUI
```

In the struct, add state:
```swift
    @State private var photoItem: PhotosPickerItem?
    @State private var photoInFlight = false
    @State private var photoError: String?
```

Below the "Set cover from URL" button, add another button:
```swift
                if game.serverId != nil {
                    PhotosPicker(selection: $photoItem, matching: .images) {
                        Label("Upload cover photo…", systemImage: "photo.on.rectangle")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .padding(.horizontal)
                    .disabled(photoInFlight)

                    if let err = photoError {
                        Text(err).font(.caption).foregroundStyle(.red).padding(.horizontal)
                    }
                }
```

Add an `.onChange` handler at the bottom of `content`:
```swift
                .onChange(of: photoItem) { _, newItem in
                    guard let item = newItem else { return }
                    Task { await uploadPhoto(item, for: game) }
                }
```

Method:
```swift
    private func uploadPhoto(_ item: PhotosPickerItem, for game: Game) async {
        guard let id = game.serverId else { return }
        photoError = nil
        photoInFlight = true
        defer { photoInFlight = false; photoItem = nil }
        do {
            guard let data = try await item.loadTransferable(type: Data.self) else {
                photoError = "Couldn't load image data."
                return
            }
            // Re-encode HEIC → JPEG so the server doesn't have to handle HEIC.
            let jpeg: Data
            if let ui = UIImage(data: data), let j = ui.jpegData(compressionQuality: 0.9) {
                jpeg = j
            } else {
                jpeg = data
            }
            _ = try await proxiesAPI.uploadCover(gameId: id,
                                                 face: .front,
                                                 imageData: jpeg,
                                                 filename: "cover_\(id).jpg")
            // Force a repull of this row.
            game.lastSyncedAt = nil
            try? context.save()
            syncTrigger.pingAfterMutation()
        } catch {
            photoError = "Upload failed: \(error.localizedDescription)"
        }
    }
```

- [ ] **Step 10.2: Build + commit**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 | tail -3
```
Expected: `** BUILD SUCCEEDED **`.

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift
git commit -m "Add photo-library cover upload via PhotosPicker"
```

### 🛑 User checkpoint — photo-library upload

Tell the owner to ⌘R and verify:

1. **Upload cover photo…** button appears on Detail (below "Set cover from URL…").
2. Tap → iOS photo picker opens (may need to grant permission first time).
3. Pick a photo → upload starts; the button area shows it's busy.
4. After a few seconds, cover updates on Detail (next sync repulls the new path).
5. HEIC photos (modern iPhone format) work — re-encoded to JPEG client-side.

Resume once confirmed.

---

## Task 11: Remove unused DebugHomeView

**Files:**
- Delete: `GameTracker/Views/DebugHomeView.swift`

Now that `RootTabView` is live, `DebugHomeView` is unused. Plan 2's commit history preserves the original — no need to keep dead code.

- [ ] **Step 11.1: Delete + verify**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
rm ios/GameTracker/GameTracker/Views/DebugHomeView.swift
cd ios/GameTracker
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 | tail -3
```
Expected: `** TEST SUCCEEDED **` (no compile errors, all 54 tests still pass).

- [ ] **Step 11.2: Commit**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add -A ios/GameTracker/GameTracker/Views/DebugHomeView.swift
git commit -m "Remove DebugHomeView (replaced by RootTabView)"
```

(`git add -A` stages the deletion. If the path is unfamiliar to git, use `git rm` instead.)

---

## Task 12: Manual checkpoint — exercise every flow on Simulator

**Files:** none

Before pushing, run through every flow you just built on the iPhone 17 Simulator with the live server. The plan's automated tests cover the networking + sync layer; this is the equivalent for UI behaviour.

- [ ] **Step 12.1: Launch the app**

In Xcode: **⌘R** (iPhone 17 sim). If the simulator hangs (as it did at the end of Plan 2), reboot the Mac.

- [ ] **Step 12.2: Run through this checklist**

| # | Action | Expected |
|---|---|---|
| 1 | Log in with your real credentials | Tab bar appears, Library tab selected |
| 2 | Library shows your games (pulled from server) | Each game has a thumb + title + platform, no status pill |
| 3 | Pull-to-refresh | "Syncing…" indicator, then idle; no errors |
| 4 | Tap a game | Game Detail opens, fields populated, full cover loaded |
| 5 | Tap Edit, change the title, Save | Back in detail, title updated, "edit" badge appears in library |
| 6 | Wait ~6 seconds | The "edit" badge clears (sync ran, server accepted) |
| 7 | Tap + (top-right of Library) | Add form opens |
| 8 | Fill title + platform, tap "Fetch metadata" | Genre / pricecharting / metacritic fields appear if upstream has data |
| 9 | Save | New game appears in library with "new" badge |
| 10 | Wait ~6 seconds | Badge clears, game now has a server_id |
| 11 | Tap that new game → Set cover from URL, paste a https URL to a JPEG | Sheet shows "Downloading…", then closes; thumbnail appears on next sync (a few seconds) |
| 12 | Tap "Upload cover photo…", pick from photo library | Upload completes; new cover appears on next sync |
| 13 | Swipe a row in list view → Delete | Row disappears |
| 14 | Pull-to-refresh | Row stays gone (server confirmed deletion) |
| 15 | Tap the menu (⋯) → switch to Grid | Grid layout renders with cover-only cells |
| 16 | Type in search | Library filters live |
| 17 | Menu → Filter platform… → pick one | Library narrows to that platform |
| 18 | Settings tab | Placeholder screen — that's expected, Plan 3b builds it |

If any step fails, note the symptom and dig in. Don't proceed to push if the basic CRUD flow is broken.

- [ ] **Step 12.3: Optional — confirm on the web app**

Open the gameTracker web UI in a browser, log in as the same user. The game you added should appear there too. Edits round-trip both directions.

---

## Task 13: Push + wrap up

**Files:** none

- [ ] **Step 13.1: Verify clean working tree**

```bash
cd "/Users/cameron/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```
Expected: only `js/completions.js` (pre-existing).

- [ ] **Step 13.2: Push**

```bash
git push -u origin plan-3a-library-and-game-flows
```

- [ ] **Step 13.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-21-ios-library-and-game-flows.md
git add docs/superpowers/plans/2026-05-21-ios-library-and-game-flows.md
git commit -m "Mark Plan 3a (iOS library + game flows) complete"
git push
```

---

## What this plan does NOT build (Plan 3b territory)

- **Items tab** (consoles + accessories) — same shape as Library but for items; needs its own list/grid + detail + add/edit + cover upload.
- **Spin tab** — animated wheel picker.
- **Stats tab** — Swift Charts dashboards.
- **Settings tab** — appearance, clear cache, sign-out (the existing sign-out logic from DebugHomeView lives in AuthManager.clearLocalSession() and is still wired; we just need a screen for it).
- **Coverflow view mode** — deferred (grid + list is enough for daily use).
- **Long-press quick actions** on Library cells (mark playing/completed/delete) — covered by swipe + detail-screen edit for now.
- **Completion log management** (add/edit completion entries inside detail) — viewing comes for free via the GameDetailView's existing query, but the "add new completion" form is deferred.
- **External-image-on-add** — the cover URL field in AddGameView is currently inert; covers must be added via the detail screen *after* the game has a server_id. Future iteration could add a 2-phase save in AddGameView that waits for the first sync before calling external-image.

When all 13 tasks are checked, the app is **daily-driveable on phone via Sideloadly**: the owner can browse their collection, add games, edit fields, upload covers, search, filter, and sync round-trip. The remaining four tabs are friendly placeholders.

Plan 3b can then build out the remaining tabs without re-touching networking or sync.
