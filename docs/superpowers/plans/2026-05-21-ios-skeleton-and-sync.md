# iOS Skeleton + Sync Engine Implementation Plan (Plan 2 of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a native iOS app under `gameTracker/ios/` (single-repo layout) that authenticates against the deployed `/api/v2/` endpoints, holds a full offline copy of the user's collection in SwiftData, and performs bidirectional delta sync with conflict resolution — without any of the polished UI tabs (those land in Plan 3).

**Architecture:** A fresh SwiftUI app targeting iOS 17. SwiftData provides the on-device store; a `Sync/` layer reconciles it with the server's `/api/v2/sync/changes` and `/api/v2/sync/push` endpoints. Bearer tokens live in the Keychain. A small "Debug" screen exists to verify sync works end-to-end before Plan 3 builds real tabs on top.

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData, XCTest, URLSession, Security framework (Keychain). iOS deployment target: 17.0. Build tooling: Xcode (latest stable).

**Spec:** [docs/superpowers/specs/2026-05-15-ios-app-design.md](../specs/2026-05-15-ios-app-design.md)
**Predecessor:** [docs/superpowers/plans/2026-05-15-server-foundation.md](2026-05-15-server-foundation.md) (server deployed)

---

## Server API Surface (cheat-sheet)

The endpoints below are already live. iOS code must match these shapes exactly.

| Endpoint | Method | Purpose | Auth |
|---|---|---|---|
| `/api/v2/auth/token.php` | POST | login (form: `username`, `password`, `device_name`) | — |
| `/api/v2/auth/revoke.php` | POST | logout (current token) | Bearer |
| `/api/v2/sync/changes.php?since=<ISO8601>` | GET | rows changed since timestamp | Bearer |
| `/api/v2/sync/push.php` | POST | upload local changes (JSON body) | Bearer |
| `/api/v2/images/cover.php?id=<game_id>&size=thumb\|full&face=front\|back` | GET | cover bytes | Bearer |
| `/api/v2/images/extra.php?id=<image_id>&type=game\|item&size=thumb\|full` | GET | extra photo bytes | Bearer |
| `/api/v2/games/cover-upload.php?game_id=<id>&face=front\|back` | POST | upload cover (multipart `image` field) | Bearer |
| `/api/v2/pricecharting.php?title=&platform=` | GET | proxy | Bearer |
| `/api/v2/metacritic.php?title=&platform=` | GET | proxy | Bearer |
| `/api/v2/external-image.php?url=&game_id=&type=front\|back` | GET | download external image | Bearer |

All responses follow `{"data": {...}}` on success or `{"error": "code", "message": "..."}` on failure.

### Server tables that sync (with writable columns)

| Table | Writable columns (per server's `push.php`) |
|---|---|
| `games` | title, platform, genre, description, series, special_edition, condition, review, star_rating, metacritic_rating, played, price_paid, pricecharting_price, is_physical, digital_store, front_cover_image, back_cover_image, release_date |
| `items` | title, platform, category, description, condition, price_paid, pricecharting_price, front_image, back_image, notes, quantity |
| `game_completions` | game_id, title, platform, time_taken, date_started, date_completed, completion_year, notes |
| `game_images` | game_id, image_path |
| `item_images` | item_id, image_path |

### Push request shape

```json
{
  "games": {
    "new":      [ { "client_id": "<uuid>", "title": "...", "platform": "...", ... } ],
    "modified": [ { "server_id": 42, "last_synced_at": "2026-05-21T10:30:00Z", "title": "...", ... } ],
    "deleted":  [ { "server_id": 42 } ]
  },
  "items":            { ... },
  "game_completions": { ... },
  "game_images":      { ... },
  "item_images":      { ... }
}
```

### Push response shape (per row)

- `{ client_id?, server_id, updated_at, result: "accepted" }` — applied
- `{ server_id, server_version: {...full row...}, result: "conflict" }` — server's newer version wins (or user resolves)
- `{ server_id, result: "not_found" }` — server deleted it; phone should also delete
- `{ client_id?, server_id?, result: "rejected", reason: "..." }` — error

---

## File Structure

### New subdirectory in the existing repo: `gameTracker/ios/`

Single-repo workflow: the iOS app lives as a subdirectory of the existing `gameTracker` web-app repo so both Macs see the source via iCloud, there's one git history to maintain, and the same `git push` ships both server and app changes. Xcode build artifacts (`xcuserdata`, `DerivedData`, `*.xcuserstate`) are appended to the existing root `.gitignore`.

### Project layout (paths relative to the existing `gameTracker/` repo root)

```
gameTracker/                             ← existing repo root
├── api/, database/, includes/, ...      ← unchanged web-app code
├── docs/superpowers/plans/              ← THIS plan lives here
└── ios/                                 ← NEW: everything below is iOS code
    ├── GameTracker.xcodeproj/
    ├── GameTracker/
│   ├── GameTrackerApp.swift              — @main entry, environment wiring
│   ├── RootView.swift                    — picks LoginView or DebugHomeView based on auth
│   ├── Config.swift                      — server base URL, debug flags
│   │
│   ├── Models/
│   │   ├── SyncState.swift               — enum: synced / localModified / localNew / localDeleted / conflict
│   │   ├── Game.swift                    — @Model
│   │   ├── Item.swift                    — @Model
│   │   ├── GameCompletion.swift          — @Model
│   │   ├── GameImage.swift               — @Model
│   │   ├── ItemImage.swift               — @Model
│   │   ├── SyncMetadata.swift            — @Model holding global last_synced_at
│   │   └── ModelContainerFactory.swift   — builds the SwiftData container/schema
│   │
│   ├── Networking/
│   │   ├── APIClient.swift               — URLSession wrapper + Bearer + error decode
│   │   ├── APIError.swift                — typed errors mirroring server error codes
│   │   ├── DTOs.swift                    — Codable structs for JSON request/response
│   │   ├── AuthAPI.swift                 — login, revoke
│   │   ├── SyncAPI.swift                 — fetchChanges, push
│   │   └── ImagesAPI.swift               — downloadCover, downloadExtra (with on-disk cache)
│   │
│   ├── Auth/
│   │   ├── KeychainTokenStore.swift      — store/load/delete Bearer token
│   │   └── AuthManager.swift             — @Observable; current token + user_id
│   │
│   ├── Sync/
│   │   ├── SyncEngine.swift              — orchestrator: changes → apply → push → apply response
│   │   ├── ChangeApplier.swift           — upserts server rows into SwiftData
│   │   ├── PushBuilder.swift             — builds the push payload from dirty rows
│   │   └── SyncStatus.swift              — @Observable: idle/syncing/error/pending counts
│   │
│   ├── Views/
│   │   ├── LoginView.swift               — username/password form
│   │   ├── DebugHomeView.swift           — temp landing: list games, sync now, sign out
│   │   ├── ConflictBannerView.swift      — red banner shown when conflict count > 0
│   │   ├── ConflictListView.swift        — list of conflicted rows
│   │   └── ConflictDetailView.swift      — phone-vs-server picker per row
│   │
│   └── Assets.xcassets/
│       ├── AppIcon.appiconset/           — placeholder icon (real icon in Plan 4)
│       └── AccentColor.colorset/
└── GameTrackerTests/
    ├── ModelTests.swift                  — SwiftData round-trip
    ├── APIClientTests.swift              — URLProtocol stubs
    ├── AuthAPITests.swift
    ├── SyncAPITests.swift
    ├── ChangeApplierTests.swift          — in-memory container
    ├── PushBuilderTests.swift
    ├── SyncEngineTests.swift             — full pipeline w/ stubbed API
    └── Helpers/
        ├── URLProtocolStub.swift
        └── InMemoryContainer.swift
```

### Untouched (Plan 3+ territory)
- Library/Items/Spin/Stats tabs and all their sub-views
- Game Detail / Item Detail screens
- Currently Playing widget target (Plan 4)
- Cover upload UI (the API client lands here, the screen lands in Plan 3)

### Modified in the existing `gameTracker/` repo (outside `ios/`)
- Append iOS/Xcode patterns to the existing root `.gitignore`
- Optional one-line pointer in the root `README.md`

---

## Task 0: Environment prerequisites

**Files:** none

- [ ] **Step 0.1: Verify Xcode is installed and recent**

Run:
```bash
xcode-select -p
xcodebuild -version
```

Expected: prints a path (e.g., `/Applications/Xcode.app/Contents/Developer`) and `Xcode 15.x` or newer.

If not installed: install from the Mac App Store (~15 GB), then run `sudo xcode-select -s /Applications/Xcode.app/Contents/Developer` and `sudo xcodebuild -license accept`.

- [ ] **Step 0.2: Verify a free Apple ID is signed into Xcode**

Open Xcode → Settings (⌘,) → Accounts. Confirm the owner's Apple ID is listed. If not, click `+` → "Apple ID" → sign in.

This step is GUI-only; no command. Report back when done.

- [ ] **Step 0.3: Verify a working Simulator runtime is available**

Run:
```bash
xcrun simctl list runtimes | grep -i ios
```

Expected: at least one entry like `iOS 17.x` or `iOS 18.x`. If none, open Xcode → Settings → Platforms and install the latest iOS runtime.

- [ ] **Step 0.4: Confirm the existing repo's git state is clean**

Run:
```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status
```

Expected: working tree clean (or only this plan doc as uncommitted). Stash or commit anything stale before adding the `ios/` subdirectory.

- [ ] **Step 0.5: Verify the server is reachable from the work Mac**

Decide the server base URL the iOS app will hit. The spec says DuckDNS; for now we'll use whatever the owner reports as the live server URL. Save it for Task 2.

Run (replace `<HOST>` with the owner-provided hostname):
```bash
curl -sS -o /dev/null -w "%{http_code}\n" "https://<HOST>/api/v2/auth/token.php"
```

Expected: `405` (Method Not Allowed — the endpoint exists but requires POST). Any 4xx that isn't 404 means routing works.

If 404: nginx isn't routing `/api/v2/` correctly — fix server-side before proceeding.

---

> **Working directory convention from Task 1 onward:** all `xcodebuild`, `git add`, and other relative-path commands assume current working directory is `gameTracker/ios/`. The `git add <foo>` paths in commit blocks resolve to `gameTracker/ios/<foo>` in the index. If the executing agent prefers absolute paths, prepend `ios/` to every `git add` target instead.

## Task 1: Create the Xcode project under `ios/`

**Files:**
- Create: `ios/` subdirectory inside the existing `gameTracker/` repo
- Create: `ios/GameTracker.xcodeproj/` + initial skeleton files (via Xcode)
- Modify: root `.gitignore` (append iOS/Xcode patterns)
- Modify: root `README.md` (one-line pointer to `ios/`)

This task is mostly Xcode GUI. The Xcode project is created **inside** the existing web-app repo — no separate `git init`.

- [ ] **Step 1.1: Create the `ios/` subdirectory**

Run:
```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
mkdir -p ios
ls ios   # should be empty
```

- [ ] **Step 1.2: Create the Xcode project inside `ios/`**

Open Xcode → File → New → Project. Choose:

| Field | Value |
|---|---|
| Platform | iOS |
| Template | App |
| Product Name | `GameTracker` |
| Team | (free Apple ID) |
| Organization Identifier | `com.cameron` (or any reverse-DNS you prefer) |
| Interface | SwiftUI |
| Language | Swift |
| Storage | **None** (we'll add SwiftData manually in Task 3 — Xcode's auto-generated SwiftData scaffolding is geared to a single-model Item example and just creates noise) |
| Include Tests | ✓ (checked) |

Save location: `…/Personal-Projects/gameTracker/ios/` (the directory created in Step 1.1).

Source Control: **uncheck** "Create Git repository" — the existing repo already covers this directory.

After creation, the directory should look like:
```
gameTracker/ios/
├── GameTracker.xcodeproj/
├── GameTracker/
│   ├── GameTrackerApp.swift
│   ├── ContentView.swift
│   └── Assets.xcassets/
└── GameTrackerTests/
    └── GameTrackerTests.swift
```

- [ ] **Step 1.3: Verify the project builds for Simulator**

Run:
```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' build 2>&1 | tail -5
```

Expected: ends with `** BUILD SUCCEEDED **`. If the scheme name differs, list with `xcodebuild -list -project GameTracker.xcodeproj`.

- [ ] **Step 1.4: Append iOS/Xcode patterns to the root `.gitignore`**

Read the current root `.gitignore` first to avoid duplicating any existing entries, then append the following block to the end (replacing entries that already exist with adjustments rather than duplicates):

```gitignore

# iOS / Xcode (added for ios/ subdirectory)
**/xcuserdata/
**/*.xcuserstate
**/DerivedData/
ios/build/
*.xcscmblueprint
*.xccheckout
.swiftpm/
ios/Pods/
ios/Carthage/Build/
*.xcresult/
```

- [ ] **Step 1.5: Add one-line pointer to the root README**

If the root `README.md` exists, append:

```markdown

## iOS app

Source for the iPhone client lives under [`ios/`](ios/). Build with Xcode 15+
(iOS 17 deployment target). See `docs/superpowers/plans/` for the
implementation plans.
```

If no root `README.md` exists, skip — don't create one just for this.

- [ ] **Step 1.6: Stage and commit the iOS skeleton**

Run:
```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status   # sanity check — should show new ios/ files + updated .gitignore (+ README maybe)
git add .gitignore README.md ios/
git status   # confirm no xcuserdata/, no DerivedData/ are staged
git commit -m "Add iOS app Xcode skeleton under ios/"
```

Expected: commit succeeds. If `git status` shows any `xcuserdata` or `DerivedData` entries, the `.gitignore` edit didn't take — fix before committing.

- [ ] **Step 1.7: Push (optional — same as any web-app commit)**

If you normally push web-app commits immediately:
```bash
git push
```

Otherwise this can wait until the end of the plan.

---

## Task 2: Project configuration (deployment target, ATS, Config.swift)

**Files:**
- Modify: project settings (deployment target) — done in Xcode GUI
- Modify: `GameTracker/Info.plist` (created on demand) — ATS exception if server uses self-signed cert
- Create: `GameTracker/Config.swift`

- [ ] **Step 2.1: Set deployment target to iOS 17.0**

Xcode → click the project root in the left navigator → target `GameTracker` → tab "General" → Deployment Info → iOS Deployment Target → **17.0**.

Repeat for the `GameTrackerTests` target.

Verify:
```bash
grep -r "IPHONEOS_DEPLOYMENT_TARGET" GameTracker.xcodeproj/project.pbxproj | sort -u
```
Expected: every result shows `IPHONEOS_DEPLOYMENT_TARGET = 17.0;`.

- [ ] **Step 2.2: Decide whether ATS exception is needed**

If the server uses a valid public TLS cert (Let's Encrypt on DuckDNS), no ATS exception is needed and you can skip to Step 2.3.

If the server uses a self-signed cert or plain HTTP, add `Info.plist` ATS exception. Xcode → target → tab "Info" → add row "App Transport Security Settings" (a dictionary) → inside it add row "Exception Domains" → inside that add a row with the server hostname → inside that add `NSExceptionAllowsInsecureHTTPLoads` (Boolean, YES) and/or `NSIncludesSubdomains` (Boolean, YES).

Document the resulting plist. The raw XML should contain something like:
```xml
<key>NSAppTransportSecurity</key>
<dict>
    <key>NSExceptionDomains</key>
    <dict>
        <key>your-server.duckdns.org</key>
        <dict>
            <key>NSIncludesSubdomains</key><true/>
            <key>NSExceptionAllowsInsecureHTTPLoads</key><true/>
        </dict>
    </dict>
</dict>
```

- [ ] **Step 2.3: Write `Config.swift`**

Write `GameTracker/Config.swift`:

```swift
import Foundation

/// Compile-time / launch-time configuration. Single source of truth so
/// individual call sites don't hard-code the server URL.
enum Config {
    /// Base URL for the deployed `/api/v2/` endpoints. Override at runtime
    /// by setting `GT_SERVER_BASE_URL` in the scheme's environment.
    static var serverBaseURL: URL {
        if let override = ProcessInfo.processInfo.environment["GT_SERVER_BASE_URL"],
           let url = URL(string: override) {
            return url
        }
        // TODO(plan-execution): replace with the live DuckDNS hostname.
        return URL(string: "https://your-server.duckdns.org")!
    }

    /// Convenience helper for building `/api/v2/...` URLs.
    static func v2(_ path: String) -> URL {
        serverBaseURL.appendingPathComponent("api/v2").appendingPathComponent(path)
    }

    /// Toggle verbose URLSession logging in debug builds.
    static let verboseNetworking = true
}
```

Note: the `your-server.duckdns.org` placeholder is intentional — the executing agent should replace it with the owner-supplied hostname during execution.

- [ ] **Step 2.4: Wire `Config.swift` into the project**

Drag `Config.swift` into Xcode (under the `GameTracker` group). Confirm it's added to the `GameTracker` target (the target membership inspector on the right shows the box checked).

Verify by building:
```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' build 2>&1 | tail -5
```
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 2.5: Replace the auto-generated ContentView with a placeholder**

Open `GameTracker/ContentView.swift` and replace its body with:

```swift
import SwiftUI

struct ContentView: View {
    var body: some View {
        VStack(spacing: 12) {
            Text("gameTracker")
                .font(.largeTitle.weight(.bold))
            Text("Configured for: \(Config.serverBaseURL.host ?? "—")")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding()
    }
}

#Preview { ContentView() }
```

Build + run in Simulator (⌘R). Verify the configured hostname is displayed.

- [ ] **Step 2.6: Commit**

```bash
git add GameTracker/Config.swift GameTracker/ContentView.swift GameTracker.xcodeproj
git commit -m "Configure iOS 17 deployment target, add Config.swift, replace ContentView placeholder"
```

---

## Task 3: SwiftData models and sync-state enum

**Files:**
- Create: `GameTracker/Models/SyncState.swift`
- Create: `GameTracker/Models/Game.swift`
- Create: `GameTracker/Models/Item.swift`
- Create: `GameTracker/Models/GameCompletion.swift`
- Create: `GameTracker/Models/GameImage.swift`
- Create: `GameTracker/Models/ItemImage.swift`
- Create: `GameTracker/Models/SyncMetadata.swift`
- Create: `GameTracker/Models/ModelContainerFactory.swift`
- Create: `GameTrackerTests/ModelTests.swift`
- Create: `GameTrackerTests/Helpers/InMemoryContainer.swift`
- Modify: `GameTracker/GameTrackerApp.swift` (attach the model container)

Each synced model carries three extra columns beyond what the server stores:

- `serverId: Int?` — server's PK, `nil` until first successful push
- `clientId: UUID` — stable phone-side identifier, set at creation (used in push response correlation)
- `lastSyncedAt: Date?` — server's `updated_at` when phone last received this row; powers conflict detection
- `syncState: SyncState` — current state in the sync state machine

- [ ] **Step 3.1: Write `SyncState.swift`**

Write `GameTracker/Models/SyncState.swift`:

```swift
import Foundation

/// State of a local row with respect to the server.
/// Stored as a raw `String` so SwiftData persists it as TEXT.
enum SyncState: String, Codable, CaseIterable {
    /// Row matches the server's last-seen version exactly.
    case synced
    /// Row was edited locally since last sync; pending push.
    case localModified = "local_modified"
    /// Row was created on phone; has no `serverId` yet.
    case localNew = "local_new"
    /// Row was deleted locally; pending delete-push.
    /// (We keep a tombstone row rather than actually deleting so we
    /// can communicate the deletion to the server on next sync.)
    case localDeleted = "local_deleted"
    /// Push response said this row conflicts; awaiting user resolution.
    case conflict
}
```

- [ ] **Step 3.2: Write `Game.swift`**

Write `GameTracker/Models/Game.swift`:

```swift
import Foundation
import SwiftData

@Model
final class Game {
    // Sync metadata
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    // Server columns
    var title: String
    var platform: String
    var genre: String?
    var gameDescription: String?    // 'description' is reserved on NSObject; the JSON key is still 'description'
    var series: String?
    var specialEdition: String?
    var conditionValue: String?     // 'condition' is fine as a property; renamed for readability
    var review: String?
    var starRating: Int?
    var metacriticRating: Int?
    var played: Int
    var pricePaid: Double?
    var pricechartingPrice: Double?
    var isPhysical: Int
    var digitalStore: String?
    var frontCoverImage: String?
    var backCoverImage: String?
    var releaseDate: Date?

    /// When the row was created on the phone (or first synced down). Used for
    /// stable ordering and tombstone cleanup.
    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(title: String, platform: String, syncState: SyncState = .localNew) {
        self.clientId = UUID()
        self.title = title
        self.platform = platform
        self.played = 0
        self.isPhysical = 1
        self.createdAt = Date()
        self.syncStateRaw = syncState.rawValue
    }
}
```

- [ ] **Step 3.3: Write `Item.swift`**

Write `GameTracker/Models/Item.swift`:

```swift
import Foundation
import SwiftData

@Model
final class Item {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    var title: String
    var platform: String?
    var category: String           // "console" or "accessory"
    var itemDescription: String?
    var conditionValue: String?
    var pricePaid: Double?
    var pricechartingPrice: Double?
    var frontImage: String?
    var backImage: String?
    var notes: String?
    var quantity: Int

    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(title: String, category: String, syncState: SyncState = .localNew) {
        self.clientId = UUID()
        self.title = title
        self.category = category
        self.quantity = 1
        self.createdAt = Date()
        self.syncStateRaw = syncState.rawValue
    }
}
```

- [ ] **Step 3.4: Write `GameCompletion.swift`**

Write `GameTracker/Models/GameCompletion.swift`:

```swift
import Foundation
import SwiftData

@Model
final class GameCompletion {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    /// Server-side foreign key to `games.id`. May be `nil` if the parent
    /// game is itself still `localNew` and unpushed.
    var gameServerId: Int?

    var title: String
    var platform: String?
    var timeTaken: String?
    var dateStarted: Date?
    var dateCompleted: Date?
    var completionYear: Int?
    var notes: String?

    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(title: String, syncState: SyncState = .localNew) {
        self.clientId = UUID()
        self.title = title
        self.createdAt = Date()
        self.syncStateRaw = syncState.rawValue
    }
}
```

- [ ] **Step 3.5: Write `GameImage.swift` and `ItemImage.swift`**

Write `GameTracker/Models/GameImage.swift`:

```swift
import Foundation
import SwiftData

/// Extra photo attached to a game (the "extras" table on the server).
/// Phone only stores the metadata; the actual JPEG is fetched on demand
/// via `/api/v2/images/extra.php`.
@Model
final class GameImage {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    var gameServerId: Int?
    /// Server's stored filename, e.g. "abc123.jpg". Used to build cache paths.
    var imagePath: String

    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(imagePath: String, gameServerId: Int? = nil) {
        self.clientId = UUID()
        self.imagePath = imagePath
        self.gameServerId = gameServerId
        self.createdAt = Date()
        self.syncStateRaw = SyncState.localNew.rawValue
    }
}
```

Write `GameTracker/Models/ItemImage.swift`:

```swift
import Foundation
import SwiftData

@Model
final class ItemImage {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    var itemServerId: Int?
    var imagePath: String

    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(imagePath: String, itemServerId: Int? = nil) {
        self.clientId = UUID()
        self.imagePath = imagePath
        self.itemServerId = itemServerId
        self.createdAt = Date()
        self.syncStateRaw = SyncState.localNew.rawValue
    }
}
```

- [ ] **Step 3.6: Write `SyncMetadata.swift`**

A single-row store for sync-wide state (the global `last_synced_at`). Modelled as a SwiftData entity so it persists across launches without separate UserDefaults plumbing.

Write `GameTracker/Models/SyncMetadata.swift`:

```swift
import Foundation
import SwiftData

@Model
final class SyncMetadata {
    /// Server's `server_now` from the last successful `/sync/changes` response.
    /// Used as the `since` parameter on the next call. `nil` means full pull.
    var lastSyncedAt: Date?

    /// ID of the logged-in user (returned by `/auth/token`). Stored so a
    /// stale local DB belonging to a different user can be wiped on login.
    var userId: Int?

    init(lastSyncedAt: Date? = nil, userId: Int? = nil) {
        self.lastSyncedAt = lastSyncedAt
        self.userId = userId
    }
}
```

- [ ] **Step 3.7: Write `ModelContainerFactory.swift`**

Write `GameTracker/Models/ModelContainerFactory.swift`:

```swift
import Foundation
import SwiftData

/// Builds the SwiftData container that's attached to the app's environment.
/// Centralised so the production code and tests use the same schema.
enum ModelContainerFactory {
    /// All `@Model` types in the app. SwiftData uses this list to build
    /// the underlying SQLite schema.
    static let schema = Schema([
        Game.self,
        Item.self,
        GameCompletion.self,
        GameImage.self,
        ItemImage.self,
        SyncMetadata.self,
    ])

    /// On-disk container for production.
    static func production() throws -> ModelContainer {
        let config = ModelConfiguration(schema: schema, isStoredInMemoryOnly: false)
        return try ModelContainer(for: schema, configurations: [config])
    }

    /// In-memory container for unit tests. Each call returns a fresh DB.
    static func inMemory() throws -> ModelContainer {
        let config = ModelConfiguration(schema: schema, isStoredInMemoryOnly: true)
        return try ModelContainer(for: schema, configurations: [config])
    }
}
```

- [ ] **Step 3.8: Attach the container to the app**

Edit `GameTracker/GameTrackerApp.swift`:

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

    var body: some Scene {
        WindowGroup {
            ContentView()
        }
        .modelContainer(container)
    }
}
```

- [ ] **Step 3.9: Write the test helper**

Make sure the `GameTrackerTests/Helpers/` group exists in Xcode (right-click `GameTrackerTests` → New Group → "Helpers"). Then write `GameTrackerTests/Helpers/InMemoryContainer.swift`:

```swift
import Foundation
import SwiftData
@testable import GameTracker

enum InMemoryContainer {
    /// A fresh in-memory container + a `ModelContext` ready to use.
    /// Each call returns isolated storage — tests can't interfere with each other.
    static func make() throws -> (ModelContainer, ModelContext) {
        let container = try ModelContainerFactory.inMemory()
        let context = ModelContext(container)
        return (container, context)
    }
}
```

- [ ] **Step 3.10: Write `ModelTests.swift`**

Write `GameTrackerTests/ModelTests.swift`:

```swift
import XCTest
import SwiftData
@testable import GameTracker

final class ModelTests: XCTestCase {

    func test_game_roundtrips_through_swiftData() throws {
        let (_, context) = try InMemoryContainer.make()
        let g = Game(title: "Halo: Reach", platform: "Xbox 360")
        g.starRating = 9
        context.insert(g)
        try context.save()

        let fetched = try context.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(fetched.count, 1)
        XCTAssertEqual(fetched.first?.title, "Halo: Reach")
        XCTAssertEqual(fetched.first?.starRating, 9)
        XCTAssertEqual(fetched.first?.syncState, .localNew)
    }

    func test_sync_state_persists_as_raw_string() throws {
        let (_, context) = try InMemoryContainer.make()
        let g = Game(title: "A", platform: "B")
        g.syncState = .conflict
        context.insert(g)
        try context.save()

        let fetched = try context.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(fetched.first?.syncStateRaw, "conflict")
        XCTAssertEqual(fetched.first?.syncState, .conflict)
    }

    func test_clientId_is_unique() throws {
        let (_, context) = try InMemoryContainer.make()
        let g1 = Game(title: "A", platform: "B")
        let g2 = Game(title: "C", platform: "D")
        XCTAssertNotEqual(g1.clientId, g2.clientId)
    }

    func test_sync_metadata_singleton_round_trips() throws {
        let (_, context) = try InMemoryContainer.make()
        let meta = SyncMetadata(lastSyncedAt: Date(timeIntervalSince1970: 1_700_000_000),
                                userId: 42)
        context.insert(meta)
        try context.save()

        let fetched = try context.fetch(FetchDescriptor<SyncMetadata>())
        XCTAssertEqual(fetched.count, 1)
        XCTAssertEqual(fetched.first?.userId, 42)
        XCTAssertEqual(fetched.first?.lastSyncedAt?.timeIntervalSince1970, 1_700_000_000)
    }
}
```

- [ ] **Step 3.11: Add Models to the Xcode project + run tests**

In Xcode, drag the new `Models/` folder + `Helpers/` folder into the project navigator (or use File → Add Files To "GameTracker"…). Ensure model files belong to the `GameTracker` target; helpers belong to the `GameTrackerTests` target.

Run:
```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' test 2>&1 | tail -20
```

Expected: `Test Suite 'ModelTests' passed`. All 4 tests pass.

- [ ] **Step 3.12: Commit**

```bash
git add GameTracker GameTrackerTests
git commit -m "Add SwiftData models for games, items, completions, images, and sync metadata"
```

---

## Task 4: Codable DTOs matching server JSON

**Files:**
- Create: `GameTracker/Networking/DTOs.swift`
- Create: `GameTrackerTests/DTOTests.swift`

These are wire-format types — not the SwiftData `@Model` classes. They're plain Swift structs that decode directly from the server's JSON.

- [ ] **Step 4.1: Write the failing DTO test first**

Write `GameTrackerTests/DTOTests.swift`:

```swift
import XCTest
@testable import GameTracker

final class DTOTests: XCTestCase {

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601WithFractional
        return d
    }()

    func test_decode_token_response() throws {
        let json = #"""
        { "data": { "token": "abc123", "user_id": 7, "username": "cam" } }
        """#.data(using: .utf8)!
        let env = try decoder.decode(APIEnvelope<TokenResponseDTO>.self, from: json)
        XCTAssertEqual(env.data.token, "abc123")
        XCTAssertEqual(env.data.userId, 7)
        XCTAssertEqual(env.data.username, "cam")
    }

    func test_decode_error_response() throws {
        let json = #"""
        { "error": "invalid_credentials", "message": "Username or password is incorrect" }
        """#.data(using: .utf8)!
        let err = try decoder.decode(APIErrorDTO.self, from: json)
        XCTAssertEqual(err.error, "invalid_credentials")
        XCTAssertEqual(err.message, "Username or password is incorrect")
    }

    func test_decode_changes_response_with_empty_arrays() throws {
        let json = #"""
        {
          "data": {
            "games": [], "items": [], "game_completions": [],
            "game_images": [], "item_images": [],
            "deletions": [],
            "server_now": "2026-05-21T10:30:00Z"
          }
        }
        """#.data(using: .utf8)!
        let env = try decoder.decode(APIEnvelope<ChangesResponseDTO>.self, from: json)
        XCTAssertEqual(env.data.games.count, 0)
        XCTAssertEqual(env.data.serverNow.timeIntervalSince1970, 1747823400, accuracy: 1)
    }

    func test_decode_push_response_with_mixed_results() throws {
        let json = #"""
        {
          "data": {
            "games": [
              { "client_id": "abc", "server_id": 1, "updated_at": "2026-05-21T10:30:00Z", "result": "accepted" },
              { "server_id": 2, "server_version": {"id": 2, "title": "S", "platform": "X"}, "result": "conflict" },
              { "server_id": 3, "result": "not_found" }
            ],
            "items": [], "game_completions": [], "game_images": [], "item_images": []
          }
        }
        """#.data(using: .utf8)!
        let env = try decoder.decode(APIEnvelope<PushResponseDTO>.self, from: json)
        XCTAssertEqual(env.data.games.count, 3)
        XCTAssertEqual(env.data.games[0].result, "accepted")
        XCTAssertEqual(env.data.games[1].result, "conflict")
        XCTAssertNotNil(env.data.games[1].serverVersion)
    }
}
```

Run:
```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' test 2>&1 | tail -20
```

Expected: compilation fails — none of these types exist yet.

- [ ] **Step 4.2: Write `DTOs.swift`**

Write `GameTracker/Networking/DTOs.swift`:

```swift
import Foundation

// MARK: - Envelope types

/// All v2 success responses are wrapped in `{ "data": ... }`.
struct APIEnvelope<T: Decodable>: Decodable {
    let data: T
}

/// All v2 error responses share this shape.
struct APIErrorDTO: Decodable, Error {
    let error: String
    let message: String?
}

// MARK: - Auth

struct TokenResponseDTO: Decodable {
    let token: String
    let userId: Int
    let username: String

    enum CodingKeys: String, CodingKey {
        case token
        case userId = "user_id"
        case username
    }
}

struct RevokeResponseDTO: Decodable {
    let revoked: Bool
}

// MARK: - Sync

/// Row representations returned by /sync/changes. Mirrors the server's
/// MySQL columns. We decode unknown / future columns leniently by not
/// requiring fields that may be absent.
struct GameDTO: Decodable {
    let id: Int
    let userId: Int?
    let title: String
    let platform: String
    let genre: String?
    let description: String?
    let series: String?
    let specialEdition: String?
    let condition: String?
    let review: String?
    let starRating: Int?
    let metacriticRating: Int?
    let played: Int?
    let pricePaid: Double?
    let pricechartingPrice: Double?
    let isPhysical: Int?
    let digitalStore: String?
    let frontCoverImage: String?
    let backCoverImage: String?
    let releaseDate: String?     // server returns "YYYY-MM-DD" or NULL
    let createdAt: String?
    let updatedAt: String        // ISO-ish; parsed in ChangeApplier

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case title, platform, genre, description, series
        case specialEdition = "special_edition"
        case condition, review
        case starRating = "star_rating"
        case metacriticRating = "metacritic_rating"
        case played
        case pricePaid = "price_paid"
        case pricechartingPrice = "pricecharting_price"
        case isPhysical = "is_physical"
        case digitalStore = "digital_store"
        case frontCoverImage = "front_cover_image"
        case backCoverImage = "back_cover_image"
        case releaseDate = "release_date"
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }
}

struct ItemDTO: Decodable {
    let id: Int
    let userId: Int?
    let title: String
    let platform: String?
    let category: String
    let description: String?
    let condition: String?
    let pricePaid: Double?
    let pricechartingPrice: Double?
    let frontImage: String?
    let backImage: String?
    let notes: String?
    let quantity: Int?
    let createdAt: String?
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case title, platform, category, description, condition
        case pricePaid = "price_paid"
        case pricechartingPrice = "pricecharting_price"
        case frontImage = "front_image"
        case backImage = "back_image"
        case notes, quantity
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }
}

struct GameCompletionDTO: Decodable {
    let id: Int
    let userId: Int?
    let gameId: Int?
    let title: String
    let platform: String?
    let timeTaken: String?
    let dateStarted: String?
    let dateCompleted: String?
    let completionYear: Int?
    let notes: String?
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case gameId = "game_id"
        case title, platform
        case timeTaken = "time_taken"
        case dateStarted = "date_started"
        case dateCompleted = "date_completed"
        case completionYear = "completion_year"
        case notes
        case updatedAt = "updated_at"
    }
}

struct GameImageDTO: Decodable {
    let id: Int
    let gameId: Int
    let imagePath: String
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case gameId = "game_id"
        case imagePath = "image_path"
        case updatedAt = "updated_at"
    }
}

struct ItemImageDTO: Decodable {
    let id: Int
    let itemId: Int
    let imagePath: String
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case itemId = "item_id"
        case imagePath = "image_path"
        case updatedAt = "updated_at"
    }
}

struct DeletionDTO: Decodable {
    let tableName: String
    let serverId: Int
    let deletedAt: String

    enum CodingKeys: String, CodingKey {
        case tableName = "table_name"
        case serverId = "server_id"
        case deletedAt = "deleted_at"
    }
}

struct ChangesResponseDTO: Decodable {
    let games: [GameDTO]
    let items: [ItemDTO]
    let gameCompletions: [GameCompletionDTO]
    let gameImages: [GameImageDTO]
    let itemImages: [ItemImageDTO]
    let deletions: [DeletionDTO]
    let serverNow: Date

    enum CodingKeys: String, CodingKey {
        case games, items, deletions
        case gameCompletions = "game_completions"
        case gameImages = "game_images"
        case itemImages = "item_images"
        case serverNow = "server_now"
    }
}

// MARK: - Push

/// One row in the per-table result array returned by /sync/push.
/// Fields are optional because the shape varies by `result`:
/// - "accepted":  client_id?, server_id, updated_at
/// - "conflict":  server_id, server_version (raw JSON object)
/// - "not_found": server_id
/// - "rejected":  client_id?, server_id?, reason
struct PushRowResultDTO: Decodable {
    let clientId: String?
    let serverId: Int?
    let updatedAt: String?
    let serverVersion: PushServerVersionDTO?
    let result: String
    let reason: String?

    enum CodingKeys: String, CodingKey {
        case clientId = "client_id"
        case serverId = "server_id"
        case updatedAt = "updated_at"
        case serverVersion = "server_version"
        case result, reason
    }
}

/// Untyped server row carried back in a conflict response. We decode it
/// to `[String: JSONValue]` rather than a per-table type because all
/// five tables can show up here.
struct PushServerVersionDTO: Decodable {
    let raw: [String: JSONValue]
    init(from decoder: Decoder) throws {
        raw = try [String: JSONValue](from: decoder)
    }
}

struct PushResponseDTO: Decodable {
    let games: [PushRowResultDTO]
    let items: [PushRowResultDTO]
    let gameCompletions: [PushRowResultDTO]
    let gameImages: [PushRowResultDTO]
    let itemImages: [PushRowResultDTO]

    enum CodingKeys: String, CodingKey {
        case games, items
        case gameCompletions = "game_completions"
        case gameImages = "game_images"
        case itemImages = "item_images"
    }
}

/// A polymorphic JSON value, used when we need to round-trip server data
/// without committing to a static struct. Limited to the shapes MySQL
/// actually returns (string, int, double, bool, null).
enum JSONValue: Codable {
    case string(String), int(Int), double(Double), bool(Bool), null

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null; return }
        if let v = try? c.decode(Bool.self) { self = .bool(v); return }
        if let v = try? c.decode(Int.self) { self = .int(v); return }
        if let v = try? c.decode(Double.self) { self = .double(v); return }
        if let v = try? c.decode(String.self) { self = .string(v); return }
        throw DecodingError.dataCorruptedError(in: c, debugDescription: "Unsupported JSON value")
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let v): try c.encode(v)
        case .int(let v):    try c.encode(v)
        case .double(let v): try c.encode(v)
        case .bool(let v):   try c.encode(v)
        case .null:          try c.encodeNil()
        }
    }

    var stringValue: String? { if case .string(let v) = self { return v } else { return nil } }
    var intValue: Int? {
        switch self {
        case .int(let v):    return v
        case .double(let v): return Int(v)
        case .string(let v): return Int(v)
        default:             return nil
        }
    }
}

// MARK: - JSONDecoder extension

extension JSONDecoder.DateDecodingStrategy {
    /// Accepts both `2026-05-21T10:30:00Z` and `2026-05-21T10:30:00.123+00:00`.
    /// Matches the variants the server emits in `sync/changes` responses.
    static var iso8601WithFractional: JSONDecoder.DateDecodingStrategy {
        .custom { decoder in
            let s = try decoder.singleValueContainer().decode(String.self)
            for fmt in [
                "yyyy-MM-dd'T'HH:mm:ss'Z'",
                "yyyy-MM-dd'T'HH:mm:ss.SSSXXXXX",
                "yyyy-MM-dd'T'HH:mm:ssXXXXX",
            ] {
                let f = DateFormatter()
                f.locale = Locale(identifier: "en_US_POSIX")
                f.timeZone = TimeZone(identifier: "UTC")
                f.dateFormat = fmt
                if let d = f.date(from: s) { return d }
            }
            // Fall back to ISO8601DateFormatter for anything we haven't seen.
            let iso = ISO8601DateFormatter()
            iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
            if let d = iso.date(from: s) { return d }
            throw DecodingError.dataCorruptedError(in: try decoder.singleValueContainer(),
                                                   debugDescription: "Could not parse date: \(s)")
        }
    }
}
```

- [ ] **Step 4.3: Add file to Xcode + run tests**

Drag `Networking/` group into Xcode. Add `DTOs.swift` to the `GameTracker` target and `DTOTests.swift` to the `GameTrackerTests` target.

Run:
```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' test 2>&1 | tail -20
```

Expected: all 4 DTO tests pass.

- [ ] **Step 4.4: Commit**

```bash
git add GameTracker/Networking GameTrackerTests/DTOTests.swift
git commit -m "Add Codable DTOs for v2 API responses"
```

---

## Task 5: APIClient with Bearer-token support

**Files:**
- Create: `GameTracker/Networking/APIError.swift`
- Create: `GameTracker/Networking/APIClient.swift`
- Create: `GameTrackerTests/Helpers/URLProtocolStub.swift`
- Create: `GameTrackerTests/APIClientTests.swift`

- [ ] **Step 5.1: Write the URLProtocol stub helper**

`URLProtocolStub` lets tests intercept `URLSession` traffic and return canned responses without a real server. Standard Apple pattern.

Write `GameTrackerTests/Helpers/URLProtocolStub.swift`:

```swift
import Foundation

/// Test-only URLProtocol that returns a fixed (status, body, headers) for any
/// URL matching `predicate`. Call `register(handler:)` to install before
/// constructing a URLSession with `URLProtocolStub` in its configuration's
/// `protocolClasses`.
final class URLProtocolStub: URLProtocol {

    struct Stub {
        let statusCode: Int
        let body: Data
        let headers: [String: String]
        let predicate: (URLRequest) -> Bool
    }

    static var stubs: [Stub] = []
    static var recordedRequests: [URLRequest] = []

    static func register(_ stub: Stub) { stubs.append(stub) }
    static func reset() { stubs.removeAll(); recordedRequests.removeAll() }

    /// Build a `URLSession` whose traffic this protocol intercepts.
    static func session() -> URLSession {
        let config = URLSessionConfiguration.ephemeral
        config.protocolClasses = [URLProtocolStub.self]
        return URLSession(configuration: config)
    }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        URLProtocolStub.recordedRequests.append(request)
        guard let stub = URLProtocolStub.stubs.first(where: { $0.predicate(request) }) else {
            client?.urlProtocol(self, didFailWithError: URLError(.unsupportedURL))
            return
        }
        let response = HTTPURLResponse(url: request.url!,
                                       statusCode: stub.statusCode,
                                       httpVersion: "HTTP/1.1",
                                       headerFields: stub.headers)!
        client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
        client?.urlProtocol(self, didLoad: stub.body)
        client?.urlProtocolDidFinishLoading(self)
    }

    override func stopLoading() {}
}
```

- [ ] **Step 5.2: Write the failing APIClient tests**

Write `GameTrackerTests/APIClientTests.swift`:

```swift
import XCTest
@testable import GameTracker

final class APIClientTests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_get_sends_bearer_header_when_token_set() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"pong":true,"user_id":1}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))

        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "TEST_TOKEN" })
        struct Ping: Decodable { let pong: Bool; let userId: Int
            enum CodingKeys: String, CodingKey { case pong; case userId = "user_id" } }
        let result: Ping = try await client.get("/api/v2/_ping.php")

        XCTAssertTrue(result.pong)
        XCTAssertEqual(URLProtocolStub.recordedRequests.first?.value(forHTTPHeaderField: "Authorization"),
                       "Bearer TEST_TOKEN")
    }

    func test_get_omits_bearer_header_when_token_nil() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { nil })
        struct Empty: Decodable {}
        _ = try await client.get("/api/v2/auth/token.php") as Empty

        XCTAssertNil(URLProtocolStub.recordedRequests.first?.value(forHTTPHeaderField: "Authorization"))
    }

    func test_http_4xx_decodes_into_APIErrorDTO_thrown() async {
        URLProtocolStub.register(.init(
            statusCode: 401,
            body: #"{"error":"invalid_credentials","message":"Username or password is incorrect"}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { nil })
        struct Empty: Decodable {}
        do {
            _ = try await client.get("/api/v2/anything") as Empty
            XCTFail("should have thrown")
        } catch let err as APIError {
            guard case .server(let code, let message, let status) = err else {
                XCTFail("expected .server, got \(err)"); return
            }
            XCTAssertEqual(code, "invalid_credentials")
            XCTAssertEqual(message, "Username or password is incorrect")
            XCTAssertEqual(status, 401)
        } catch {
            XCTFail("expected APIError, got \(error)")
        }
    }

    func test_postForm_sends_url_encoded_body() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"token":"x","user_id":1,"username":"cam"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { nil })
        let result: TokenResponseDTO = try await client.postForm(
            "/api/v2/auth/token.php",
            fields: ["username": "cam", "password": "secret"]
        )

        XCTAssertEqual(result.token, "x")
        let req = URLProtocolStub.recordedRequests.first!
        XCTAssertEqual(req.value(forHTTPHeaderField: "Content-Type"),
                       "application/x-www-form-urlencoded; charset=utf-8")
        // URLSession reads body from a stream when set via httpBodyStream;
        // when set via httpBody it shows up directly.
        let bodyData = req.httpBody ?? req.bodyStreamData() ?? Data()
        let bodyString = String(data: bodyData, encoding: .utf8) ?? ""
        XCTAssertTrue(bodyString.contains("username=cam"))
        XCTAssertTrue(bodyString.contains("password=secret"))
    }

    func test_postJSON_sends_json_body() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        struct Body: Encodable { let foo: String }
        struct Empty: Decodable {}
        _ = try await client.postJSON("/api/v2/x", body: Body(foo: "bar")) as Empty

        let req = URLProtocolStub.recordedRequests.first!
        XCTAssertEqual(req.value(forHTTPHeaderField: "Content-Type"), "application/json; charset=utf-8")
        let bodyData = req.httpBody ?? req.bodyStreamData() ?? Data()
        XCTAssertEqual(String(data: bodyData, encoding: .utf8), #"{"foo":"bar"}"#)
    }
}

private extension URLRequest {
    /// URLSession copies the body into an InputStream for any non-trivial
    /// request — read it out here so tests can assert on it.
    func bodyStreamData() -> Data? {
        guard let stream = httpBodyStream else { return nil }
        stream.open()
        defer { stream.close() }
        var data = Data()
        let buf = UnsafeMutablePointer<UInt8>.allocate(capacity: 4096)
        defer { buf.deallocate() }
        while stream.hasBytesAvailable {
            let n = stream.read(buf, maxLength: 4096)
            if n <= 0 { break }
            data.append(buf, count: n)
        }
        return data
    }
}
```

Run tests — they fail (no `APIClient`, no `APIError` yet).

- [ ] **Step 5.3: Write `APIError.swift`**

Write `GameTracker/Networking/APIError.swift`:

```swift
import Foundation

/// Errors thrown by `APIClient`. Distinguishes transport failures, server
/// errors (decoded from the JSON envelope), and decoding failures.
enum APIError: Error, LocalizedError {
    /// URLSession transport-level failure (offline, TLS, etc.).
    case transport(URLError)
    /// Server returned a JSON error envelope.
    case server(code: String, message: String?, status: Int)
    /// Response body couldn't be decoded into the expected type.
    case decoding(String)
    /// Response had a status we didn't recognise (no JSON envelope).
    case unexpected(status: Int, bodyPrefix: String)

    var errorDescription: String? {
        switch self {
        case .transport(let e):                 return e.localizedDescription
        case .server(let code, let msg, _):     return msg ?? code
        case .decoding(let detail):             return "Decoding failed: \(detail)"
        case .unexpected(let status, let body): return "HTTP \(status): \(body)"
        }
    }

    /// Convenience for AuthManager: true if the user needs to log in again.
    var isAuthFailure: Bool {
        if case .server(let code, _, _) = self {
            return code == "invalid_token" || code == "missing_token"
        }
        return false
    }
}
```

- [ ] **Step 5.4: Write `APIClient.swift`**

Write `GameTracker/Networking/APIClient.swift`:

```swift
import Foundation

/// Thin URLSession wrapper that:
///  - prepends the configured base URL,
///  - attaches the current Bearer token (if any),
///  - decodes `{"data": ...}` envelopes into typed responses,
///  - throws typed errors for non-2xx responses with a JSON error body.
///
/// All methods are `async throws`. The client is value-type-ish (final class
/// because URLSession isn't a value type) and safe to share across actors.
final class APIClient: @unchecked Sendable {

    private let session: URLSession
    private let baseURL: URL
    private let tokenProvider: @Sendable () -> String?
    private let decoder: JSONDecoder

    init(session: URLSession = .shared,
         baseURL: URL,
         tokenProvider: @escaping @Sendable () -> String? = { nil }) {
        self.session = session
        self.baseURL = baseURL
        self.tokenProvider = tokenProvider
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601WithFractional
        self.decoder = d
    }

    // MARK: - Public API

    func get<T: Decodable>(_ path: String,
                           query: [String: String] = [:]) async throws -> T {
        let req = try buildRequest(method: "GET", path: path, query: query)
        return try await send(req)
    }

    func postForm<T: Decodable>(_ path: String,
                                fields: [String: String]) async throws -> T {
        var req = try buildRequest(method: "POST", path: path)
        let body = fields
            .map { "\(urlEncode($0.key))=\(urlEncode($0.value))" }
            .joined(separator: "&")
        req.httpBody = body.data(using: .utf8)
        req.setValue("application/x-www-form-urlencoded; charset=utf-8",
                     forHTTPHeaderField: "Content-Type")
        return try await send(req)
    }

    func postJSON<T: Decodable, B: Encodable>(_ path: String,
                                              body: B) async throws -> T {
        var req = try buildRequest(method: "POST", path: path)
        let encoder = JSONEncoder()
        req.httpBody = try encoder.encode(body)
        req.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")
        return try await send(req)
    }

    /// Raw download (e.g., image bytes). Bypasses JSON decoding.
    func downloadData(_ path: String, query: [String: String] = [:]) async throws -> Data {
        let req = try buildRequest(method: "GET", path: path, query: query)
        let (data, response) = try await session.data(for: req)
        try Self.validateStatus(data: data, response: response)
        return data
    }

    /// Multipart upload (for cover upload). Single file field named "image".
    func uploadImage<T: Decodable>(_ path: String,
                                   query: [String: String] = [:],
                                   imageData: Data,
                                   filename: String,
                                   mimeType: String) async throws -> T {
        let boundary = "Boundary-\(UUID().uuidString)"
        var req = try buildRequest(method: "POST", path: path, query: query)
        req.setValue("multipart/form-data; boundary=\(boundary)",
                     forHTTPHeaderField: "Content-Type")
        var body = Data()
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"image\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: \(mimeType)\r\n\r\n".data(using: .utf8)!)
        body.append(imageData)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)
        req.httpBody = body
        return try await send(req)
    }

    // MARK: - Internals

    private func buildRequest(method: String,
                              path: String,
                              query: [String: String] = [:]) throws -> URLRequest {
        var comps = URLComponents(url: baseURL.appendingPathComponent(path), resolvingAgainstBaseURL: false)!
        if !query.isEmpty {
            comps.queryItems = query.map { URLQueryItem(name: $0.key, value: $0.value) }
        }
        guard let url = comps.url else {
            throw APIError.decoding("Could not build URL from \(path)")
        }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = tokenProvider() {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        return req
    }

    private func send<T: Decodable>(_ req: URLRequest) async throws -> T {
        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await session.data(for: req)
        } catch let urlError as URLError {
            throw APIError.transport(urlError)
        }
        try Self.validateStatus(data: data, response: response)
        do {
            let envelope = try decoder.decode(APIEnvelope<T>.self, from: data)
            return envelope.data
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }

    private static func validateStatus(data: Data, response: URLResponse) throws {
        guard let http = response as? HTTPURLResponse else {
            throw APIError.unexpected(status: 0, bodyPrefix: "")
        }
        guard (200...299).contains(http.statusCode) else {
            // Try to decode the v2 error envelope.
            if let dto = try? JSONDecoder().decode(APIErrorDTO.self, from: data) {
                throw APIError.server(code: dto.error, message: dto.message, status: http.statusCode)
            }
            let prefix = String(data: data.prefix(256), encoding: .utf8) ?? ""
            throw APIError.unexpected(status: http.statusCode, bodyPrefix: prefix)
        }
    }

    private func urlEncode(_ s: String) -> String {
        var allowed = CharacterSet.urlQueryAllowed
        allowed.remove(charactersIn: "+&=")
        return s.addingPercentEncoding(withAllowedCharacters: allowed) ?? s
    }
}
```

- [ ] **Step 5.5: Run tests and verify they pass**

Drag the new files into Xcode (target memberships as before). Run:
```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' test 2>&1 | tail -20
```

Expected: all 5 APIClient tests pass.

- [ ] **Step 5.6: Commit**

```bash
git add GameTracker/Networking GameTrackerTests
git commit -m "Add APIClient with Bearer auth, JSON envelope decoding, and typed errors"
```

---

## Task 6: AuthAPI (login + revoke)

**Files:**
- Create: `GameTracker/Networking/AuthAPI.swift`
- Create: `GameTrackerTests/AuthAPITests.swift`

- [ ] **Step 6.1: Write the failing tests**

Write `GameTrackerTests/AuthAPITests.swift`:

```swift
import XCTest
@testable import GameTracker

final class AuthAPITests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_login_posts_credentials_and_returns_token() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"token":"abc","user_id":7,"username":"cam"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/auth/token.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!)
        let auth = AuthAPI(client: client)

        let response = try await auth.login(username: "cam", password: "secret", deviceName: "iPhone 15")
        XCTAssertEqual(response.token, "abc")
        XCTAssertEqual(response.userId, 7)
    }

    func test_login_wrong_password_throws_server_error() async {
        URLProtocolStub.register(.init(
            statusCode: 401,
            body: #"{"error":"invalid_credentials","message":"..."}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!)
        let auth = AuthAPI(client: client)
        do {
            _ = try await auth.login(username: "cam", password: "x", deviceName: nil)
            XCTFail("should have thrown")
        } catch let err as APIError {
            guard case .server(let code, _, _) = err else { XCTFail(); return }
            XCTAssertEqual(code, "invalid_credentials")
        } catch {
            XCTFail("expected APIError, got \(error)")
        }
    }

    func test_revoke_posts_with_bearer_header() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"revoked":true}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/auth/revoke.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "MY_TOKEN" })
        let auth = AuthAPI(client: client)
        let response = try await auth.revoke()

        XCTAssertTrue(response.revoked)
        XCTAssertEqual(URLProtocolStub.recordedRequests.first?.value(forHTTPHeaderField: "Authorization"),
                       "Bearer MY_TOKEN")
    }
}
```

- [ ] **Step 6.2: Run, verify failure**

Tests fail — `AuthAPI` doesn't exist.

- [ ] **Step 6.3: Write `AuthAPI.swift`**

Write `GameTracker/Networking/AuthAPI.swift`:

```swift
import Foundation

/// Auth endpoints: login (issue token) and revoke (logout).
struct AuthAPI {
    let client: APIClient

    /// POST /api/v2/auth/token.php
    /// Returns the freshly issued token + user info.
    func login(username: String, password: String, deviceName: String?) async throws -> TokenResponseDTO {
        var fields: [String: String] = ["username": username, "password": password]
        if let device = deviceName { fields["device_name"] = device }
        return try await client.postForm("/api/v2/auth/token.php", fields: fields)
    }

    /// POST /api/v2/auth/revoke.php — invalidates the *currently used* Bearer token.
    func revoke() async throws -> RevokeResponseDTO {
        return try await client.postForm("/api/v2/auth/revoke.php", fields: [:])
    }
}
```

- [ ] **Step 6.4: Run tests and verify they pass**

Drag `AuthAPI.swift` into Xcode (GameTracker target). Run tests; expect 3 new passes.

- [ ] **Step 6.5: Commit**

```bash
git add GameTracker/Networking/AuthAPI.swift GameTrackerTests/AuthAPITests.swift
git commit -m "Add AuthAPI for login and revoke"
```

---

## Task 7: KeychainTokenStore

**Files:**
- Create: `GameTracker/Auth/KeychainTokenStore.swift`
- Create: `GameTrackerTests/KeychainTokenStoreTests.swift`

The Bearer token is sensitive credential material — store it in the iOS Keychain, not UserDefaults.

- [ ] **Step 7.1: Write the failing tests**

Write `GameTrackerTests/KeychainTokenStoreTests.swift`:

```swift
import XCTest
@testable import GameTracker

final class KeychainTokenStoreTests: XCTestCase {

    /// We use a dedicated test service name so the production keychain
    /// entry is never touched. Each test cleans up after itself.
    private let service = "com.cameron.GameTracker.tests"

    override func setUp() {
        super.setUp()
        try? KeychainTokenStore(service: service).delete()
    }

    override func tearDown() {
        try? KeychainTokenStore(service: service).delete()
        super.tearDown()
    }

    func test_save_and_load_round_trip() throws {
        let store = KeychainTokenStore(service: service)
        try store.save(token: "abc-123")
        XCTAssertEqual(try store.load(), "abc-123")
    }

    func test_load_returns_nil_when_unset() throws {
        let store = KeychainTokenStore(service: service)
        XCTAssertNil(try store.load())
    }

    func test_overwrite_existing_value() throws {
        let store = KeychainTokenStore(service: service)
        try store.save(token: "first")
        try store.save(token: "second")
        XCTAssertEqual(try store.load(), "second")
    }

    func test_delete_removes_value() throws {
        let store = KeychainTokenStore(service: service)
        try store.save(token: "to-be-deleted")
        try store.delete()
        XCTAssertNil(try store.load())
    }
}
```

- [ ] **Step 7.2: Run, verify failure**

Tests fail — `KeychainTokenStore` doesn't exist.

- [ ] **Step 7.3: Write `KeychainTokenStore.swift`**

Write `GameTracker/Auth/KeychainTokenStore.swift`:

```swift
import Foundation
import Security

/// Bearer-token persistence in the iOS Keychain. Single-account; the
/// account name is fixed because the app is single-user.
struct KeychainTokenStore {

    enum Failure: Error {
        case status(OSStatus)
        case unexpectedFormat
    }

    private let service: String
    private let account = "bearer-token"

    init(service: String = "com.cameron.GameTracker") {
        self.service = service
    }

    func save(token: String) throws {
        let data = Data(token.utf8)
        // First try to update; if not found, insert.
        let queryBase: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        let updateAttrs: [String: Any] = [kSecValueData as String: data]
        let updateStatus = SecItemUpdate(queryBase as CFDictionary, updateAttrs as CFDictionary)
        if updateStatus == errSecSuccess { return }
        if updateStatus != errSecItemNotFound {
            throw Failure.status(updateStatus)
        }
        var addQuery = queryBase
        addQuery[kSecValueData as String] = data
        addQuery[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        let addStatus = SecItemAdd(addQuery as CFDictionary, nil)
        guard addStatus == errSecSuccess else { throw Failure.status(addStatus) }
    }

    func load() throws -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess else { throw Failure.status(status) }
        guard let data = item as? Data, let token = String(data: data, encoding: .utf8) else {
            throw Failure.unexpectedFormat
        }
        return token
    }

    func delete() throws {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        let status = SecItemDelete(query as CFDictionary)
        if status != errSecSuccess && status != errSecItemNotFound {
            throw Failure.status(status)
        }
    }
}
```

- [ ] **Step 7.4: Run tests and verify they pass**

Drag `Auth/` group into Xcode. Run tests.

Expected: all 4 Keychain tests pass.

Note: Keychain access requires running on Simulator or device — these tests won't pass with `xcodebuild test -destination 'platform=macOS'`. The Simulator destination already used is correct.

- [ ] **Step 7.5: Commit**

```bash
git add GameTracker/Auth GameTrackerTests/KeychainTokenStoreTests.swift
git commit -m "Add KeychainTokenStore for Bearer-token persistence"
```

---

## Task 8: AuthManager + LoginView + auth-gated root

**Files:**
- Create: `GameTracker/Auth/AuthManager.swift`
- Create: `GameTracker/Views/LoginView.swift`
- Create: `GameTracker/RootView.swift`
- Create: `GameTracker/Views/DebugHomeView.swift` (stub for this task; fleshed out in Task 15)
- Modify: `GameTracker/GameTrackerApp.swift`

- [ ] **Step 8.1: Write `AuthManager.swift`**

Write `GameTracker/Auth/AuthManager.swift`:

```swift
import Foundation
import SwiftUI
import Observation

/// Source of truth for "is the user logged in?" — backed by the Keychain.
/// Observable so SwiftUI views auto-refresh when login state changes.
@Observable
final class AuthManager {

    enum State {
        case loggedOut
        case loggedIn(userId: Int, username: String)
    }

    private(set) var state: State = .loggedOut

    private let store: KeychainTokenStore
    /// Cached in-memory token so we don't hit the Keychain on every request.
    private var cachedToken: String?

    init(store: KeychainTokenStore = KeychainTokenStore()) {
        self.store = store
        loadFromKeychain()
    }

    /// Synchronous accessor used by APIClient's `tokenProvider`. Reads the
    /// in-memory cache to avoid sync-on-every-request keychain hits.
    var currentToken: String? { cachedToken }

    /// Load the saved token + user info at app launch.
    private func loadFromKeychain() {
        guard let token = (try? store.load()) ?? nil else { return }
        cachedToken = token
        // user_id and username are stored separately in UserDefaults (non-sensitive)
        let ud = UserDefaults.standard
        let uid = ud.integer(forKey: "gt.userId")
        let uname = ud.string(forKey: "gt.username") ?? ""
        if uid > 0 {
            state = .loggedIn(userId: uid, username: uname)
        }
    }

    /// Persist a fresh login result.
    func setLoggedIn(token: String, userId: Int, username: String) {
        try? store.save(token: token)
        cachedToken = token
        UserDefaults.standard.set(userId, forKey: "gt.userId")
        UserDefaults.standard.set(username, forKey: "gt.username")
        state = .loggedIn(userId: userId, username: username)
    }

    /// Clear local credentials. Caller is responsible for separately invoking
    /// AuthAPI.revoke() and wiping the local SwiftData store.
    func clearLocalSession() {
        try? store.delete()
        cachedToken = nil
        UserDefaults.standard.removeObject(forKey: "gt.userId")
        UserDefaults.standard.removeObject(forKey: "gt.username")
        state = .loggedOut
    }
}
```

- [ ] **Step 8.2: Write `LoginView.swift`**

Write `GameTracker/Views/LoginView.swift`:

```swift
import SwiftUI

struct LoginView: View {
    @Environment(AuthManager.self) private var authManager
    @State private var username = ""
    @State private var password = ""
    @State private var isLoading = false
    @State private var errorMessage: String?

    /// Provided by parent so this view doesn't depend on knowing where
    /// APIClient is constructed.
    let authAPI: AuthAPI

    var body: some View {
        VStack(spacing: 20) {
            Text("gameTracker")
                .font(.largeTitle.weight(.bold))
                .padding(.top, 40)

            VStack(spacing: 12) {
                TextField("Username", text: $username)
                    .textContentType(.username)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .textFieldStyle(.roundedBorder)

                SecureField("Password", text: $password)
                    .textContentType(.password)
                    .textFieldStyle(.roundedBorder)

                if let msg = errorMessage {
                    Text(msg)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .multilineTextAlignment(.center)
                }

                Button(action: signIn) {
                    if isLoading {
                        ProgressView().tint(.white).frame(maxWidth: .infinity)
                    } else {
                        Text("Sign in").frame(maxWidth: .infinity)
                    }
                }
                .buttonStyle(.borderedProminent)
                .disabled(username.isEmpty || password.isEmpty || isLoading)
            }
            .padding(.horizontal)

            Spacer()
        }
        .padding()
    }

    private func signIn() {
        errorMessage = nil
        isLoading = true
        Task {
            do {
                let resp = try await authAPI.login(
                    username: username,
                    password: password,
                    deviceName: UIDevice.current.name
                )
                authManager.setLoggedIn(token: resp.token, userId: resp.userId, username: resp.username)
            } catch let err as APIError {
                errorMessage = err.errorDescription ?? "Sign-in failed."
            } catch {
                errorMessage = error.localizedDescription
            }
            isLoading = false
        }
    }
}
```

- [ ] **Step 8.3: Write the placeholder `DebugHomeView.swift`**

This stub gets replaced in Task 15. For now it just confirms login worked.

Write `GameTracker/Views/DebugHomeView.swift`:

```swift
import SwiftUI

struct DebugHomeView: View {
    @Environment(AuthManager.self) private var authManager

    var body: some View {
        VStack(spacing: 16) {
            Text("Logged in").font(.title2.bold())
            if case .loggedIn(let uid, let username) = authManager.state {
                Text("\(username) (user \(uid))").foregroundStyle(.secondary)
            }
            Button("Sign out") {
                authManager.clearLocalSession()
            }
            .buttonStyle(.bordered)
        }
        .padding()
    }
}
```

- [ ] **Step 8.4: Write `RootView.swift`**

Write `GameTracker/RootView.swift`:

```swift
import SwiftUI

/// Top-level switcher: shows LoginView when logged-out, DebugHomeView
/// (a placeholder for the real tabs) when logged-in.
struct RootView: View {
    @Environment(AuthManager.self) private var authManager
    let authAPI: AuthAPI

    var body: some View {
        switch authManager.state {
        case .loggedOut:
            LoginView(authAPI: authAPI)
        case .loggedIn:
            DebugHomeView()
        }
    }
}
```

- [ ] **Step 8.5: Wire it together in the app entry point**

Replace `GameTracker/GameTrackerApp.swift` with:

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

    /// Built lazily so it always reads the current token from `authManager`.
    private var apiClient: APIClient {
        APIClient(baseURL: Config.serverBaseURL,
                  tokenProvider: { [authManager] in authManager.currentToken })
    }

    private var authAPI: AuthAPI { AuthAPI(client: apiClient) }

    var body: some Scene {
        WindowGroup {
            RootView(authAPI: authAPI)
                .environment(authManager)
        }
        .modelContainer(container)
    }
}
```

- [ ] **Step 8.6: Manual verification — log in to the real server**

Build + run in Simulator. Enter the owner's real credentials.

Expected:
- Login button briefly shows a spinner.
- On success, the "Logged in" debug screen appears with the username.
- Tap "Sign out" → returns to LoginView.

If login fails: check `Config.swift`'s base URL (Step 2.3); confirm server is reachable; check Xcode console for `APIError` description.

This is the first end-to-end touchpoint against the live server. Don't proceed if it doesn't work.

- [ ] **Step 8.7: Commit**

```bash
git add GameTracker/Auth/AuthManager.swift GameTracker/Views GameTracker/RootView.swift GameTracker/GameTrackerApp.swift
git commit -m "Add AuthManager, LoginView, RootView with auth gating"
```

---

## Task 9: SyncAPI — fetch changes and push

**Files:**
- Create: `GameTracker/Networking/SyncAPI.swift`
- Create: `GameTrackerTests/SyncAPITests.swift`

- [ ] **Step 9.1: Write the failing tests**

Write `GameTrackerTests/SyncAPITests.swift`:

```swift
import XCTest
@testable import GameTracker

final class SyncAPITests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_fetchChanges_sends_since_query_parameter() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T00:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = SyncAPI(client: client)

        let since = Date(timeIntervalSince1970: 1747800000)
        _ = try await api.fetchChanges(since: since)

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let sinceParam = comps.queryItems?.first(where: { $0.name == "since" })?.value
        XCTAssertNotNil(sinceParam)
        XCTAssertTrue(sinceParam!.hasSuffix("Z"), "ISO 8601 UTC, got \(sinceParam!)")
    }

    func test_fetchChanges_returns_parsed_dto() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[{"id":1,"title":"G","platform":"P","updated_at":"2026-05-21T00:00:00Z"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[],
              "deletions":[{"table_name":"games","server_id":99,"deleted_at":"2026-05-21T00:00:00Z"}],
              "server_now":"2026-05-21T00:00:00Z"
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = SyncAPI(client: client)
        let result = try await api.fetchChanges(since: nil)

        XCTAssertEqual(result.games.count, 1)
        XCTAssertEqual(result.games[0].title, "G")
        XCTAssertEqual(result.deletions.count, 1)
        XCTAssertEqual(result.deletions[0].serverId, 99)
    }

    func test_push_sends_json_body_with_table_buckets() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[]}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = SyncAPI(client: client)

        let payload = PushPayload(
            games: PushBucket(new: [["client_id": .string("uuid-1"), "title": .string("X"), "platform": .string("Y")]],
                              modified: [], deleted: []),
            items: PushBucket.empty,
            gameCompletions: PushBucket.empty,
            gameImages: PushBucket.empty,
            itemImages: PushBucket.empty
        )
        _ = try await api.push(payload)

        let req = URLProtocolStub.recordedRequests.first!
        XCTAssertEqual(req.httpMethod, "POST")
        let body = req.httpBody ?? Data()
        let str = String(data: body, encoding: .utf8) ?? ""
        XCTAssertTrue(str.contains(#""games""#))
        XCTAssertTrue(str.contains(#""new""#))
        XCTAssertTrue(str.contains(#""title":"X""#))
    }
}
```

- [ ] **Step 9.2: Run, verify failure**

`SyncAPI` and `PushPayload` don't exist yet.

- [ ] **Step 9.3: Write `SyncAPI.swift`**

Write `GameTracker/Networking/SyncAPI.swift`:

```swift
import Foundation

/// Models the push request body. Each table has a bucket with three lists.
/// Cells are `JSONValue` so the same struct can carry every table's columns.
struct PushPayload: Encodable {
    let games: PushBucket
    let items: PushBucket
    let gameCompletions: PushBucket
    let gameImages: PushBucket
    let itemImages: PushBucket

    enum CodingKeys: String, CodingKey {
        case games, items
        case gameCompletions = "game_completions"
        case gameImages = "game_images"
        case itemImages = "item_images"
    }
}

struct PushBucket: Encodable {
    let new: [[String: JSONValue]]
    let modified: [[String: JSONValue]]
    let deleted: [[String: JSONValue]]

    static let empty = PushBucket(new: [], modified: [], deleted: [])
}

/// Server sync endpoints.
struct SyncAPI {
    let client: APIClient

    /// GET /api/v2/sync/changes.php?since=<ISO8601 UTC>.
    /// `since == nil` means full pull (server treats missing param as epoch).
    func fetchChanges(since: Date?) async throws -> ChangesResponseDTO {
        var query: [String: String] = [:]
        if let since {
            query["since"] = Self.iso8601UTC(since)
        }
        return try await client.get("/api/v2/sync/changes.php", query: query)
    }

    /// POST /api/v2/sync/push.php with JSON body.
    func push(_ payload: PushPayload) async throws -> PushResponseDTO {
        return try await client.postJSON("/api/v2/sync/push.php", body: payload)
    }

    /// Format: 2026-05-21T10:30:00Z (no fractional seconds — server accepts both).
    private static func iso8601UTC(_ date: Date) -> String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = TimeZone(identifier: "UTC")
        return f.string(from: date)
    }
}
```

- [ ] **Step 9.4: Run tests and verify they pass**

Drag `SyncAPI.swift` into Xcode. Run tests.

Expected: all 3 SyncAPI tests pass.

- [ ] **Step 9.5: Commit**

```bash
git add GameTracker/Networking/SyncAPI.swift GameTrackerTests/SyncAPITests.swift
git commit -m "Add SyncAPI for /sync/changes and /sync/push"
```

---

## Task 10: ImagesAPI with on-disk cache

**Files:**
- Create: `GameTracker/Networking/ImagesAPI.swift`
- Create: `GameTrackerTests/ImagesAPITests.swift`

- [ ] **Step 10.1: Write the failing tests**

Write `GameTrackerTests/ImagesAPITests.swift`:

```swift
import XCTest
@testable import GameTracker

final class ImagesAPITests: XCTestCase {

    private var tempCacheDir: URL!

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
        tempCacheDir = FileManager.default.temporaryDirectory
            .appendingPathComponent("imagecache-\(UUID().uuidString)")
        try? FileManager.default.createDirectory(at: tempCacheDir, withIntermediateDirectories: true)
    }

    override func tearDown() {
        try? FileManager.default.removeItem(at: tempCacheDir)
        super.tearDown()
    }

    func test_downloadCover_writes_bytes_to_cache_dir() async throws {
        let payload = Data([0xFF, 0xD8, 0xFF, 0xE0])  // JPEG header bytes
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: payload,
            headers: ["Content-Type": "image/jpeg"],
            predicate: { $0.url?.path.contains("/images/cover.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ImagesAPI(client: client, cacheRoot: tempCacheDir)

        let cached = try await api.downloadCover(gameServerId: 42, face: .front, size: .thumb)
        XCTAssertTrue(FileManager.default.fileExists(atPath: cached.path))
        let bytes = try Data(contentsOf: cached)
        XCTAssertEqual(bytes, payload)
    }

    func test_downloadCover_second_call_returns_cached_file_without_network() async throws {
        let payload = Data([0xFF, 0xD8, 0xFF, 0xE0])
        var requestCount = 0
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: payload,
            headers: ["Content-Type": "image/jpeg"],
            predicate: { _ in requestCount += 1; return true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ImagesAPI(client: client, cacheRoot: tempCacheDir)

        _ = try await api.downloadCover(gameServerId: 42, face: .front, size: .thumb)
        _ = try await api.downloadCover(gameServerId: 42, face: .front, size: .thumb)

        XCTAssertEqual(URLProtocolStub.recordedRequests.count, 1,
                       "second call should hit cache, not network")
    }

    func test_downloadCover_builds_correct_query_string() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: Data([0xFF]),
            headers: [:],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ImagesAPI(client: client, cacheRoot: tempCacheDir)
        _ = try await api.downloadCover(gameServerId: 7, face: .back, size: .full)

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["id"], "7")
        XCTAssertEqual(qs["face"], "back")
        XCTAssertEqual(qs["size"], "full")
    }
}
```

- [ ] **Step 10.2: Run, verify failure**

`ImagesAPI` doesn't exist.

- [ ] **Step 10.3: Write `ImagesAPI.swift`**

Write `GameTracker/Networking/ImagesAPI.swift`:

```swift
import Foundation

/// Downloads cover and extra-photo images from the server, caching them
/// on disk so subsequent requests are instant.
///
/// Cache strategy (per the spec):
///   - Cover thumbnails → Documents/covers/  (backed up to iCloud)
///   - Full-res covers → Caches/covers-full/ (evictable by iOS)
///   - Extra thumbs    → Documents/extras/
///   - Extra full-res  → Caches/extras-full/
///
/// `cacheRoot` is the directory under which we maintain the four
/// subdirectories. In production we pass the app's Documents dir;
/// tests inject a temporary directory.
struct ImagesAPI {

    enum Face: String { case front, back }
    enum Size: String { case thumb, full }
    enum ExtraType: String { case game, item }

    let client: APIClient
    let cacheRoot: URL

    init(client: APIClient, cacheRoot: URL) {
        self.client = client
        self.cacheRoot = cacheRoot
        try? FileManager.default.createDirectory(at: cacheRoot, withIntermediateDirectories: true)
    }

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

    /// Same pattern for extra photos. `type` selects game_images vs item_images.
    func downloadExtra(imageServerId: Int, type: ExtraType, size: Size) async throws -> URL {
        let filename = "extra_\(type.rawValue)_\(imageServerId)_\(size.rawValue).jpg"
        let dest = cacheRoot.appendingPathComponent(filename)
        if FileManager.default.fileExists(atPath: dest.path) { return dest }

        let data = try await client.downloadData(
            "/api/v2/images/extra.php",
            query: ["id": String(imageServerId), "type": type.rawValue, "size": size.rawValue]
        )
        try data.write(to: dest, options: .atomic)
        return dest
    }

    /// Manual purge — used by the eventual "Clear cache" Settings button.
    func clearCache() throws {
        let contents = try FileManager.default.contentsOfDirectory(at: cacheRoot, includingPropertiesForKeys: nil)
        for url in contents { try FileManager.default.removeItem(at: url) }
    }
}

/// Locations on disk corresponding to the spec's four caches.
enum ImageCachePaths {
    static var coversThumbs: URL { docs("covers") }
    static var coversFull: URL { caches("covers-full") }
    static var extrasThumbs: URL { docs("extras") }
    static var extrasFull: URL { caches("extras-full") }

    private static func docs(_ name: String) -> URL {
        let base = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
        return base.appendingPathComponent(name)
    }
    private static func caches(_ name: String) -> URL {
        let base = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
        return base.appendingPathComponent(name)
    }
}
```

- [ ] **Step 10.4: Run tests and verify they pass**

Expected: 3 ImagesAPI tests pass.

- [ ] **Step 10.5: Commit**

```bash
git add GameTracker/Networking/ImagesAPI.swift GameTrackerTests/ImagesAPITests.swift
git commit -m "Add ImagesAPI with on-disk cache"
```

---

## Task 11: ChangeApplier — apply server changes to local DB

**Files:**
- Create: `GameTracker/Sync/ChangeApplier.swift`
- Create: `GameTrackerTests/ChangeApplierTests.swift`

`ChangeApplier` takes a `ChangesResponseDTO` and upserts/deletes the corresponding local SwiftData rows.

- [ ] **Step 11.1: Write the failing tests**

Write `GameTrackerTests/ChangeApplierTests.swift`:

```swift
import XCTest
import SwiftData
@testable import GameTracker

final class ChangeApplierTests: XCTestCase {

    /// Minimal DTO factory — only fills required fields.
    private func gameDTO(id: Int, title: String, updatedAt: String = "2026-05-21T10:00:00Z") -> GameDTO {
        return try! JSONDecoder().decode(GameDTO.self, from: """
        {"id":\(id),"title":"\(title)","platform":"P","updated_at":"\(updatedAt)"}
        """.data(using: .utf8)!)
    }

    private func changesResponse(games: [GameDTO] = [],
                                 deletions: [DeletionDTO] = [],
                                 serverNow: String = "2026-05-21T10:00:00Z") -> ChangesResponseDTO {
        let body: [String: Any] = [
            "games": games.map { try! JSONSerialization.jsonObject(with: try! JSONEncoder().encode($0)) },
            "items": [], "game_completions": [], "game_images": [], "item_images": [],
            "deletions": deletions.map { ["table_name": $0.tableName, "server_id": $0.serverId, "deleted_at": $0.deletedAt] },
            "server_now": serverNow,
        ]
        let envelope: [String: Any] = ["data": body]
        let data = try! JSONSerialization.data(withJSONObject: envelope)
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601WithFractional
        return try! decoder.decode(APIEnvelope<ChangesResponseDTO>.self, from: data).data
    }

    func test_new_server_row_creates_local_game() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let applier = ChangeApplier(context: ctx)

        applier.apply(changesResponse(games: [gameDTO(id: 1, title: "Halo")]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 1)
        XCTAssertEqual(games.first?.title, "Halo")
        XCTAssertEqual(games.first?.serverId, 1)
        XCTAssertEqual(games.first?.syncState, .synced)
    }

    func test_existing_server_row_updates_local_game() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let existing = Game(title: "Old Title", platform: "P", syncState: .synced)
        existing.serverId = 5
        ctx.insert(existing)
        try ctx.save()

        let applier = ChangeApplier(context: ctx)
        applier.apply(changesResponse(games: [gameDTO(id: 5, title: "New Title")]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 1, "should update, not duplicate")
        XCTAssertEqual(games.first?.title, "New Title")
    }

    func test_locally_modified_row_is_not_clobbered_unless_local_synced() throws {
        // When a row is `localModified`, applying a server change should
        // NOT overwrite local edits — that's a conflict, handled by push.
        let (_, ctx) = try InMemoryContainer.make()
        let existing = Game(title: "Local Edit", platform: "P", syncState: .localModified)
        existing.serverId = 5
        ctx.insert(existing)
        try ctx.save()

        let applier = ChangeApplier(context: ctx)
        applier.apply(changesResponse(games: [gameDTO(id: 5, title: "Server Edit")]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.first?.title, "Local Edit",
                       "applier must not overwrite local pending edits")
    }

    func test_deletion_removes_local_synced_row() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let existing = Game(title: "Doomed", platform: "P", syncState: .synced)
        existing.serverId = 99
        ctx.insert(existing)
        try ctx.save()

        let applier = ChangeApplier(context: ctx)
        applier.apply(changesResponse(deletions: [
            DeletionDTO(tableName: "games", serverId: 99, deletedAt: "2026-05-21T10:00:00Z")
        ]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 0)
    }
}
```

- [ ] **Step 11.2: Run, verify failure**

`ChangeApplier` doesn't exist.

- [ ] **Step 11.3: Write `ChangeApplier.swift`**

Write `GameTracker/Sync/ChangeApplier.swift`:

```swift
import Foundation
import SwiftData

/// Applies a `ChangesResponseDTO` to the local SwiftData store. The
/// rules are intentionally conservative: never overwrite a local pending
/// edit (those are reconciled via the push step's conflict detection).
struct ChangeApplier {
    let context: ModelContext

    func apply(_ response: ChangesResponseDTO) {
        for dto in response.games        { applyGame(dto) }
        for dto in response.items        { applyItem(dto) }
        for dto in response.gameCompletions { applyCompletion(dto) }
        for dto in response.gameImages   { applyGameImage(dto) }
        for dto in response.itemImages   { applyItemImage(dto) }
        for d in response.deletions      { applyDeletion(d) }
    }

    // MARK: - Per-table appliers

    private func applyGame(_ dto: GameDTO) {
        let existing = fetchGame(serverId: dto.id)
        if let g = existing {
            // Don't clobber local edits.
            guard g.syncState == .synced else { return }
            copy(dto, into: g)
            g.syncState = .synced
            g.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let g = Game(title: dto.title, platform: dto.platform, syncState: .synced)
            g.serverId = dto.id
            copy(dto, into: g)
            g.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(g)
        }
    }

    private func applyItem(_ dto: ItemDTO) {
        let existing = fetchItem(serverId: dto.id)
        if let i = existing {
            guard i.syncState == .synced else { return }
            copy(dto, into: i)
            i.syncState = .synced
            i.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let i = Item(title: dto.title, category: dto.category, syncState: .synced)
            i.serverId = dto.id
            copy(dto, into: i)
            i.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(i)
        }
    }

    private func applyCompletion(_ dto: GameCompletionDTO) {
        let existing = fetchCompletion(serverId: dto.id)
        if let c = existing {
            guard c.syncState == .synced else { return }
            copy(dto, into: c)
            c.syncState = .synced
            c.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let c = GameCompletion(title: dto.title, syncState: .synced)
            c.serverId = dto.id
            copy(dto, into: c)
            c.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(c)
        }
    }

    private func applyGameImage(_ dto: GameImageDTO) {
        let existing = fetchGameImage(serverId: dto.id)
        if let g = existing {
            guard g.syncState == .synced else { return }
            g.imagePath = dto.imagePath
            g.gameServerId = dto.gameId
            g.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let g = GameImage(imagePath: dto.imagePath, gameServerId: dto.gameId)
            g.serverId = dto.id
            g.syncState = .synced
            g.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(g)
        }
    }

    private func applyItemImage(_ dto: ItemImageDTO) {
        let existing = fetchItemImage(serverId: dto.id)
        if let i = existing {
            guard i.syncState == .synced else { return }
            i.imagePath = dto.imagePath
            i.itemServerId = dto.itemId
            i.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let i = ItemImage(imagePath: dto.imagePath, itemServerId: dto.itemId)
            i.serverId = dto.id
            i.syncState = .synced
            i.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(i)
        }
    }

    private func applyDeletion(_ d: DeletionDTO) {
        switch d.tableName {
        case "games":            if let g = fetchGame(serverId: d.serverId),       g.syncState == .synced { context.delete(g) }
        case "items":            if let i = fetchItem(serverId: d.serverId),       i.syncState == .synced { context.delete(i) }
        case "game_completions": if let c = fetchCompletion(serverId: d.serverId), c.syncState == .synced { context.delete(c) }
        case "game_images":      if let g = fetchGameImage(serverId: d.serverId),  g.syncState == .synced { context.delete(g) }
        case "item_images":      if let i = fetchItemImage(serverId: d.serverId),  i.syncState == .synced { context.delete(i) }
        default: break
        }
    }

    // MARK: - Field copiers

    private func copy(_ dto: GameDTO, into g: Game) {
        g.title = dto.title
        g.platform = dto.platform
        g.genre = dto.genre
        g.gameDescription = dto.description
        g.series = dto.series
        g.specialEdition = dto.specialEdition
        g.conditionValue = dto.condition
        g.review = dto.review
        g.starRating = dto.starRating
        g.metacriticRating = dto.metacriticRating
        g.played = dto.played ?? 0
        g.pricePaid = dto.pricePaid
        g.pricechartingPrice = dto.pricechartingPrice
        g.isPhysical = dto.isPhysical ?? 1
        g.digitalStore = dto.digitalStore
        g.frontCoverImage = dto.frontCoverImage
        g.backCoverImage = dto.backCoverImage
        g.releaseDate = dto.releaseDate.flatMap(Self.parseYMD)
    }

    private func copy(_ dto: ItemDTO, into i: Item) {
        i.title = dto.title
        i.platform = dto.platform
        i.category = dto.category
        i.itemDescription = dto.description
        i.conditionValue = dto.condition
        i.pricePaid = dto.pricePaid
        i.pricechartingPrice = dto.pricechartingPrice
        i.frontImage = dto.frontImage
        i.backImage = dto.backImage
        i.notes = dto.notes
        i.quantity = dto.quantity ?? 1
    }

    private func copy(_ dto: GameCompletionDTO, into c: GameCompletion) {
        c.gameServerId = dto.gameId
        c.title = dto.title
        c.platform = dto.platform
        c.timeTaken = dto.timeTaken
        c.dateStarted = dto.dateStarted.flatMap(Self.parseYMD)
        c.dateCompleted = dto.dateCompleted.flatMap(Self.parseYMD)
        c.completionYear = dto.completionYear
        c.notes = dto.notes
    }

    // MARK: - Lookup helpers

    private func fetchGame(serverId: Int) -> Game? {
        let p = #Predicate<Game> { $0.serverId == serverId }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchItem(serverId: Int) -> Item? {
        let p = #Predicate<Item> { $0.serverId == serverId }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchCompletion(serverId: Int) -> GameCompletion? {
        let p = #Predicate<GameCompletion> { $0.serverId == serverId }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchGameImage(serverId: Int) -> GameImage? {
        let p = #Predicate<GameImage> { $0.serverId == serverId }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchItemImage(serverId: Int) -> ItemImage? {
        let p = #Predicate<ItemImage> { $0.serverId == serverId }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }

    // MARK: - Date parsing

    private func parseDate(_ s: String) -> Date? {
        let fmts = [
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
            "yyyy-MM-dd'T'HH:mm:ssXXXXX",
            "yyyy-MM-dd HH:mm:ss",
        ]
        for fmt in fmts {
            let f = DateFormatter()
            f.locale = Locale(identifier: "en_US_POSIX")
            f.timeZone = TimeZone(identifier: "UTC")
            f.dateFormat = fmt
            if let d = f.date(from: s) { return d }
        }
        return nil
    }

    private static func parseYMD(_ s: String) -> Date? {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd"
        return f.date(from: s)
    }
}
```

- [ ] **Step 11.4: Run tests and verify they pass**

Drag `Sync/` group into Xcode. Run tests.

Expected: all 4 ChangeApplier tests pass.

- [ ] **Step 11.5: Commit**

```bash
git add GameTracker/Sync/ChangeApplier.swift GameTrackerTests/ChangeApplierTests.swift
git commit -m "Add ChangeApplier to upsert server changes into SwiftData"
```

---

## Task 12: PushBuilder — collect dirty rows into payload

**Files:**
- Create: `GameTracker/Sync/PushBuilder.swift`
- Create: `GameTrackerTests/PushBuilderTests.swift`

- [ ] **Step 12.1: Write the failing tests**

Write `GameTrackerTests/PushBuilderTests.swift`:

```swift
import XCTest
import SwiftData
@testable import GameTracker

final class PushBuilderTests: XCTestCase {

    func test_localNew_game_appears_in_new_bucket() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "New Game", platform: "PC", syncState: .localNew)
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.new.count, 1)
        XCTAssertEqual(payload.games.modified.count, 0)
        XCTAssertEqual(payload.games.deleted.count, 0)
        XCTAssertEqual(payload.games.new[0]["title"]?.stringValue, "New Game")
        XCTAssertNotNil(payload.games.new[0]["client_id"]?.stringValue)
    }

    func test_localModified_game_with_serverId_appears_in_modified_bucket() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Edit", platform: "PC", syncState: .localModified)
        g.serverId = 42
        g.lastSyncedAt = Date(timeIntervalSince1970: 1_700_000_000)
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.modified.count, 1)
        XCTAssertEqual(payload.games.modified[0]["server_id"]?.intValue, 42)
        XCTAssertEqual(payload.games.modified[0]["last_synced_at"]?.stringValue,
                       "2023-11-14T22:13:20Z")
    }

    func test_localDeleted_game_with_serverId_appears_in_deleted_bucket() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Gone", platform: "PC", syncState: .localDeleted)
        g.serverId = 7
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.deleted.count, 1)
        XCTAssertEqual(payload.games.deleted[0]["server_id"]?.intValue, 7)
    }

    func test_synced_row_is_not_included() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Already synced", platform: "PC", syncState: .synced)
        g.serverId = 1
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.new.count, 0)
        XCTAssertEqual(payload.games.modified.count, 0)
        XCTAssertEqual(payload.games.deleted.count, 0)
    }

    func test_conflict_row_is_not_pushed() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Conflicted", platform: "PC", syncState: .conflict)
        g.serverId = 9
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.modified.count, 0,
                       "conflicts are not auto-pushed; user must resolve first")
    }
}
```

- [ ] **Step 12.2: Run, verify failure**

`PushBuilder` doesn't exist.

- [ ] **Step 12.3: Write `PushBuilder.swift`**

Write `GameTracker/Sync/PushBuilder.swift`:

```swift
import Foundation
import SwiftData

/// Walks the SwiftData store and assembles a `PushPayload` of every row
/// whose `syncState` is `localNew`, `localModified`, or `localDeleted`.
/// `synced` and `conflict` rows are skipped.
struct PushBuilder {
    let context: ModelContext

    func build() throws -> PushPayload {
        return PushPayload(
            games: try bucketGames(),
            items: try bucketItems(),
            gameCompletions: try bucketCompletions(),
            gameImages: try bucketGameImages(),
            itemImages: try bucketItemImages()
        )
    }

    // MARK: - Per-table buckets

    private func bucketGames() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<Game>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for g in all {
            switch g.syncState {
            case .localNew:
                new.append(gameToNewRow(g))
            case .localModified where g.serverId != nil:
                modified.append(gameToModifiedRow(g))
            case .localDeleted where g.serverId != nil:
                deleted.append(["server_id": .int(g.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    private func bucketItems() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<Item>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for i in all {
            switch i.syncState {
            case .localNew:
                new.append(itemToNewRow(i))
            case .localModified where i.serverId != nil:
                modified.append(itemToModifiedRow(i))
            case .localDeleted where i.serverId != nil:
                deleted.append(["server_id": .int(i.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    private func bucketCompletions() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<GameCompletion>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for c in all {
            switch c.syncState {
            case .localNew:
                new.append(completionToNewRow(c))
            case .localModified where c.serverId != nil:
                modified.append(completionToModifiedRow(c))
            case .localDeleted where c.serverId != nil:
                deleted.append(["server_id": .int(c.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    private func bucketGameImages() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<GameImage>())
        var new: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for g in all {
            switch g.syncState {
            case .localNew:
                guard let gid = g.gameServerId else { continue } // need parent's server id first
                new.append([
                    "client_id": .string(g.clientId.uuidString),
                    "game_id":   .int(gid),
                    "image_path": .string(g.imagePath),
                ])
            case .localDeleted where g.serverId != nil:
                deleted.append(["server_id": .int(g.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: [], deleted: deleted)
    }

    private func bucketItemImages() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<ItemImage>())
        var new: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for i in all {
            switch i.syncState {
            case .localNew:
                guard let iid = i.itemServerId else { continue }
                new.append([
                    "client_id": .string(i.clientId.uuidString),
                    "item_id":   .int(iid),
                    "image_path": .string(i.imagePath),
                ])
            case .localDeleted where i.serverId != nil:
                deleted.append(["server_id": .int(i.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: [], deleted: deleted)
    }

    // MARK: - Row builders

    private func gameToNewRow(_ g: Game) -> [String: JSONValue] {
        var d: [String: JSONValue] = [
            "client_id": .string(g.clientId.uuidString),
            "title":    .string(g.title),
            "platform": .string(g.platform),
            "played":   .int(g.played),
            "is_physical": .int(g.isPhysical),
        ]
        addOptional(&d, "genre", g.genre)
        addOptional(&d, "description", g.gameDescription)
        addOptional(&d, "series", g.series)
        addOptional(&d, "special_edition", g.specialEdition)
        addOptional(&d, "condition", g.conditionValue)
        addOptional(&d, "review", g.review)
        addOptionalInt(&d, "star_rating", g.starRating)
        addOptionalInt(&d, "metacritic_rating", g.metacriticRating)
        addOptionalDouble(&d, "price_paid", g.pricePaid)
        addOptionalDouble(&d, "pricecharting_price", g.pricechartingPrice)
        addOptional(&d, "digital_store", g.digitalStore)
        addOptional(&d, "front_cover_image", g.frontCoverImage)
        addOptional(&d, "back_cover_image", g.backCoverImage)
        addOptional(&d, "release_date", g.releaseDate.flatMap(Self.formatYMD))
        return d
    }

    private func gameToModifiedRow(_ g: Game) -> [String: JSONValue] {
        var d = gameToNewRow(g)
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(g.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(g.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    private func itemToNewRow(_ i: Item) -> [String: JSONValue] {
        var d: [String: JSONValue] = [
            "client_id": .string(i.clientId.uuidString),
            "title":    .string(i.title),
            "category": .string(i.category),
            "quantity": .int(i.quantity),
        ]
        addOptional(&d, "platform", i.platform)
        addOptional(&d, "description", i.itemDescription)
        addOptional(&d, "condition", i.conditionValue)
        addOptional(&d, "front_image", i.frontImage)
        addOptional(&d, "back_image", i.backImage)
        addOptional(&d, "notes", i.notes)
        addOptionalDouble(&d, "price_paid", i.pricePaid)
        addOptionalDouble(&d, "pricecharting_price", i.pricechartingPrice)
        return d
    }

    private func itemToModifiedRow(_ i: Item) -> [String: JSONValue] {
        var d = itemToNewRow(i)
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(i.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(i.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    private func completionToNewRow(_ c: GameCompletion) -> [String: JSONValue] {
        var d: [String: JSONValue] = [
            "client_id": .string(c.clientId.uuidString),
            "title": .string(c.title),
        ]
        addOptionalInt(&d, "game_id", c.gameServerId)
        addOptional(&d, "platform", c.platform)
        addOptional(&d, "time_taken", c.timeTaken)
        addOptional(&d, "date_started", c.dateStarted.flatMap(Self.formatYMD))
        addOptional(&d, "date_completed", c.dateCompleted.flatMap(Self.formatYMD))
        addOptionalInt(&d, "completion_year", c.completionYear)
        addOptional(&d, "notes", c.notes)
        return d
    }

    private func completionToModifiedRow(_ c: GameCompletion) -> [String: JSONValue] {
        var d = completionToNewRow(c)
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(c.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(c.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    // MARK: - Encoding helpers

    private func addOptional(_ d: inout [String: JSONValue], _ key: String, _ v: String?) {
        if let v { d[key] = .string(v) }
    }
    private func addOptionalInt(_ d: inout [String: JSONValue], _ key: String, _ v: Int?) {
        if let v { d[key] = .int(v) }
    }
    private func addOptionalDouble(_ d: inout [String: JSONValue], _ key: String, _ v: Double?) {
        if let v { d[key] = .double(v) }
    }

    private static func iso8601UTC(_ date: Date) -> String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = TimeZone(identifier: "UTC")
        return f.string(from: date)
    }

    private static func formatYMD(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: d)
    }
}
```

- [ ] **Step 12.4: Run tests and verify they pass**

Expected: all 5 PushBuilder tests pass.

- [ ] **Step 12.5: Commit**

```bash
git add GameTracker/Sync/PushBuilder.swift GameTrackerTests/PushBuilderTests.swift
git commit -m "Add PushBuilder to assemble sync push payload"
```

---

## Task 13: SyncEngine — orchestrator

**Files:**
- Create: `GameTracker/Sync/SyncStatus.swift`
- Create: `GameTracker/Sync/SyncEngine.swift`
- Create: `GameTrackerTests/SyncEngineTests.swift`

The engine glues `SyncAPI`, `ChangeApplier`, `PushBuilder`, and the push-response handler. It also owns the on-disk `SyncMetadata` singleton (last_synced_at).

- [ ] **Step 13.1: Write `SyncStatus.swift`**

Write `GameTracker/Sync/SyncStatus.swift`:

```swift
import Foundation
import Observation

/// Observable status banner the UI binds to. Updated on the MainActor.
@Observable
@MainActor
final class SyncStatus {
    enum Phase: Equatable {
        case idle
        case syncing
        case error(String)
    }
    var phase: Phase = .idle
    var lastSyncedAt: Date?
    var pendingPushCount: Int = 0
    var conflictCount: Int = 0
}
```

- [ ] **Step 13.2: Write the failing tests**

Write `GameTrackerTests/SyncEngineTests.swift`:

```swift
import XCTest
import SwiftData
@testable import GameTracker

final class SyncEngineTests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    /// Helper to assemble a SyncEngine wired to in-memory SwiftData + a stubbed APIClient.
    private func makeEngine() throws -> (SyncEngine, ModelContext) {
        let (_, ctx) = try InMemoryContainer.make()
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let engine = SyncEngine(
            context: ctx,
            syncAPI: SyncAPI(client: client),
            status: SyncStatus()
        )
        return (engine, ctx)
    }

    func test_runOnce_applies_server_changes_then_pushes_locals() async throws {
        // Step 1: server returns one new game (id=10)
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[{"id":10,"title":"FromServer","platform":"PC","updated_at":"2026-05-21T10:00:00Z"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[],
              "deletions":[], "server_now":"2026-05-21T10:00:00Z"
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        // Step 2: server accepts the locally-new game with server_id=99
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[{"client_id":"placeholder","server_id":99,"updated_at":"2026-05-21T10:00:01Z","result":"accepted"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[]
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))

        let (engine, ctx) = try makeEngine()

        // Pre-existing local-only game
        let localGame = Game(title: "FromPhone", platform: "iOS", syncState: .localNew)
        ctx.insert(localGame)
        try ctx.save()

        try await engine.runOnce()

        // Server's game pulled down
        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 2)
        let serverGame = games.first(where: { $0.title == "FromServer" })
        XCTAssertEqual(serverGame?.serverId, 10)
        XCTAssertEqual(serverGame?.syncState, .synced)

        // Local game now has a server_id and is marked synced
        let pushedGame = games.first(where: { $0.title == "FromPhone" })
        XCTAssertEqual(pushedGame?.serverId, 99)
        XCTAssertEqual(pushedGame?.syncState, .synced)
    }

    func test_conflict_response_marks_local_row_as_conflict() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T10:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[
                { "server_id": 5, "server_version":{"id":5,"title":"ServerWins","platform":"PC","updated_at":"2026-05-21T11:00:00Z"}, "result":"conflict" }
              ],
              "items":[],"game_completions":[],"game_images":[],"item_images":[]
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))

        let (engine, ctx) = try makeEngine()
        let local = Game(title: "PhoneEdit", platform: "PC", syncState: .localModified)
        local.serverId = 5
        local.lastSyncedAt = Date(timeIntervalSince1970: 1700000000)
        ctx.insert(local)
        try ctx.save()

        try await engine.runOnce()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        let g = games.first!
        XCTAssertEqual(g.syncState, .conflict)
        XCTAssertEqual(g.title, "PhoneEdit", "phone version must NOT be auto-overwritten — user resolves")
    }

    func test_runOnce_updates_status_to_idle_on_success() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T10:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[]}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))
        let (engine, _) = try makeEngine()
        try await engine.runOnce()
        let phase = await engine.status.phase
        XCTAssertEqual(phase, .idle)
    }
}
```

- [ ] **Step 13.3: Run, verify failure**

`SyncEngine` doesn't exist.

- [ ] **Step 13.4: Write `SyncEngine.swift`**

Write `GameTracker/Sync/SyncEngine.swift`:

```swift
import Foundation
import SwiftData

/// Orchestrates a single sync cycle:
///   1. GET /sync/changes?since=<lastSyncedAt>
///   2. ChangeApplier applies them
///   3. PushBuilder gathers pending local rows
///   4. POST /sync/push
///   5. Apply push results (accepted → mark synced + stamp server_id; conflict → mark conflict)
///   6. Save context, persist new lastSyncedAt
///
/// All state-mutating work happens on the SwiftData context; SyncEngine
/// itself is `MainActor` because it touches the @Observable SyncStatus.
@MainActor
final class SyncEngine {

    let context: ModelContext
    let syncAPI: SyncAPI
    let status: SyncStatus

    init(context: ModelContext, syncAPI: SyncAPI, status: SyncStatus) {
        self.context = context
        self.syncAPI = syncAPI
        self.status = status
    }

    /// Run a complete sync cycle. Thread-safe to call multiple times;
    /// callers should debounce or skip if already in `.syncing`.
    func runOnce() async throws {
        status.phase = .syncing
        do {
            // 1+2: pull
            let meta = fetchOrCreateMeta()
            let changes = try await syncAPI.fetchChanges(since: meta.lastSyncedAt)
            ChangeApplier(context: context).apply(changes)
            meta.lastSyncedAt = changes.serverNow

            // 3+4: push
            let payload = try PushBuilder(context: context).build()
            let response = try await syncAPI.push(payload)

            // 5: reconcile push results
            try applyPushResults(response)

            try context.save()

            status.lastSyncedAt = changes.serverNow
            status.pendingPushCount = try countPending()
            status.conflictCount = try countConflicts()
            status.phase = .idle
        } catch {
            status.phase = .error(error.localizedDescription)
            throw error
        }
    }

    // MARK: - Response application

    private func applyPushResults(_ resp: PushResponseDTO) throws {
        for r in resp.games           { applyGameResult(r) }
        for r in resp.items           { applyItemResult(r) }
        for r in resp.gameCompletions { applyCompletionResult(r) }
        for r in resp.gameImages      { applyGameImageResult(r) }
        for r in resp.itemImages      { applyItemImageResult(r) }
    }

    // Per-table appliers. Verbose, but `#Predicate` doesn't reliably specialize
    // over a generic `T: PersistentModel`, so we spell out each type explicitly.

    private func applyGameResult(_ r: PushRowResultDTO) {
        let local: Game? = {
            if let cid = r.clientId, let uuid = UUID(uuidString: cid) {
                let p = #Predicate<Game> { $0.clientId == uuid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            } else if let sid = r.serverId {
                let p = #Predicate<Game> { $0.serverId == sid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            }
            return nil
        }()
        guard let row = local else { return }
        applyOutcome(r, to: row)
    }

    private func applyItemResult(_ r: PushRowResultDTO) {
        let local: Item? = {
            if let cid = r.clientId, let uuid = UUID(uuidString: cid) {
                let p = #Predicate<Item> { $0.clientId == uuid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            } else if let sid = r.serverId {
                let p = #Predicate<Item> { $0.serverId == sid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            }
            return nil
        }()
        guard let row = local else { return }
        applyOutcome(r, to: row)
    }

    private func applyCompletionResult(_ r: PushRowResultDTO) {
        let local: GameCompletion? = {
            if let cid = r.clientId, let uuid = UUID(uuidString: cid) {
                let p = #Predicate<GameCompletion> { $0.clientId == uuid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            } else if let sid = r.serverId {
                let p = #Predicate<GameCompletion> { $0.serverId == sid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            }
            return nil
        }()
        guard let row = local else { return }
        applyOutcome(r, to: row)
    }

    private func applyGameImageResult(_ r: PushRowResultDTO) {
        let local: GameImage? = {
            if let cid = r.clientId, let uuid = UUID(uuidString: cid) {
                let p = #Predicate<GameImage> { $0.clientId == uuid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            } else if let sid = r.serverId {
                let p = #Predicate<GameImage> { $0.serverId == sid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            }
            return nil
        }()
        guard let row = local else { return }
        applyOutcome(r, to: row)
    }

    private func applyItemImageResult(_ r: PushRowResultDTO) {
        let local: ItemImage? = {
            if let cid = r.clientId, let uuid = UUID(uuidString: cid) {
                let p = #Predicate<ItemImage> { $0.clientId == uuid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            } else if let sid = r.serverId {
                let p = #Predicate<ItemImage> { $0.serverId == sid }
                return try? context.fetch(FetchDescriptor(predicate: p)).first
            }
            return nil
        }()
        guard let row = local else { return }
        applyOutcome(r, to: row)
    }

    /// Shared outcome handling. Works on the SyncableModel protocol since
    /// it doesn't need #Predicate — only direct property assignments and
    /// `context.delete`, both of which are non-generic-sensitive.
    private func applyOutcome<T: SyncableModel>(_ r: PushRowResultDTO, to row: T) {
        switch r.result {
        case "accepted":
            if let sid = r.serverId { row.serverId = sid }
            row.lastSyncedAt = r.updatedAt.flatMap(Self.parseISO) ?? Date()
            row.syncStateRaw = SyncState.synced.rawValue
        case "conflict":
            row.syncStateRaw = SyncState.conflict.rawValue
        case "not_found":
            // Server already deleted it — drop ours too.
            context.delete(row)
        case "rejected":
            // Leave the row in its current state — retry on next sync.
            break
        default:
            break
        }
    }

    // MARK: - Metadata + counts

    private func fetchOrCreateMeta() -> SyncMetadata {
        if let existing = try? context.fetch(FetchDescriptor<SyncMetadata>()).first {
            return existing
        }
        let m = SyncMetadata()
        context.insert(m)
        return m
    }

    private func countPending() throws -> Int {
        // Approximate — sums across all five tables.
        let gms = try context.fetch(FetchDescriptor<Game>())
            .filter { $0.syncState == .localNew || $0.syncState == .localModified || $0.syncState == .localDeleted }
        let itms = try context.fetch(FetchDescriptor<Item>())
            .filter { $0.syncState == .localNew || $0.syncState == .localModified || $0.syncState == .localDeleted }
        let cmps = try context.fetch(FetchDescriptor<GameCompletion>())
            .filter { $0.syncState == .localNew || $0.syncState == .localModified || $0.syncState == .localDeleted }
        return gms.count + itms.count + cmps.count
    }

    private func countConflicts() throws -> Int {
        let gms = try context.fetch(FetchDescriptor<Game>()).filter { $0.syncState == .conflict }
        let itms = try context.fetch(FetchDescriptor<Item>()).filter { $0.syncState == .conflict }
        return gms.count + itms.count
    }

    private static func parseISO(_ s: String) -> Date? {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f.date(from: s)
    }
}

/// Tiny protocol implemented by every `@Model` in the app that carries
/// sync metadata. Lets SyncEngine's response applier be generic over
/// the model class.
protocol SyncableModel: PersistentModel {
    var clientId: UUID { get set }
    var serverId: Int? { get set }
    var lastSyncedAt: Date? { get set }
    var syncStateRaw: String { get set }
}

extension Game: SyncableModel {}
extension Item: SyncableModel {}
extension GameCompletion: SyncableModel {}
extension GameImage: SyncableModel {}
extension ItemImage: SyncableModel {}
```

- [ ] **Step 13.5: Run tests and verify they pass**

Drag the new files into Xcode. Run tests.

Expected: 3 new SyncEngine tests pass; existing tests still pass.

- [ ] **Step 13.6: Commit**

```bash
git add GameTracker/Sync/SyncStatus.swift GameTracker/Sync/SyncEngine.swift GameTrackerTests/SyncEngineTests.swift
git commit -m "Add SyncEngine orchestrator with status observability"
```

---

## Task 14: Conflict resolution UI

**Files:**
- Create: `GameTracker/Views/ConflictBannerView.swift`
- Create: `GameTracker/Views/ConflictListView.swift`
- Create: `GameTracker/Views/ConflictDetailView.swift`

Per the spec's open questions, v1 ships with keep-phone / keep-server only. Field-level merge is deferred.

- [ ] **Step 14.1: Write `ConflictBannerView.swift`**

Write `GameTracker/Views/ConflictBannerView.swift`:

```swift
import SwiftUI

/// Red banner shown at the top of relevant screens whenever the
/// SyncStatus reports `conflictCount > 0`. Tap to open the resolution list.
struct ConflictBannerView: View {
    @Bindable var status: SyncStatus
    let onTap: () -> Void

    var body: some View {
        if status.conflictCount > 0 {
            Button(action: onTap) {
                HStack {
                    Image(systemName: "exclamationmark.triangle.fill")
                    Text("\(status.conflictCount) sync conflict\(status.conflictCount == 1 ? "" : "s") — tap to resolve")
                    Spacer()
                    Image(systemName: "chevron.right").font(.caption)
                }
                .foregroundStyle(.white)
                .padding(.horizontal)
                .padding(.vertical, 10)
                .background(Color.red)
            }
            .buttonStyle(.plain)
        }
    }
}
```

- [ ] **Step 14.2: Write `ConflictListView.swift`**

Write `GameTracker/Views/ConflictListView.swift`:

```swift
import SwiftUI
import SwiftData

/// Lists every row currently in `syncState == .conflict`. Tapping opens
/// the per-row picker. v1 only handles `Game` and `Item` conflicts —
/// other tables fall through to "keep server" automatically.
struct ConflictListView: View {
    @Query(filter: #Predicate<Game> { $0.syncStateRaw == "conflict" })
    private var conflictGames: [Game]

    @Query(filter: #Predicate<Item> { $0.syncStateRaw == "conflict" })
    private var conflictItems: [Item]

    var body: some View {
        NavigationStack {
            List {
                if !conflictGames.isEmpty {
                    Section("Games") {
                        ForEach(conflictGames) { g in
                            NavigationLink(value: ConflictRoute.game(g.persistentModelID)) {
                                VStack(alignment: .leading) {
                                    Text(g.title).bold()
                                    Text(g.platform).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
                if !conflictItems.isEmpty {
                    Section("Items") {
                        ForEach(conflictItems) { i in
                            NavigationLink(value: ConflictRoute.item(i.persistentModelID)) {
                                VStack(alignment: .leading) {
                                    Text(i.title).bold()
                                    Text(i.category).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
                if conflictGames.isEmpty && conflictItems.isEmpty {
                    ContentUnavailableView("No conflicts", systemImage: "checkmark.seal")
                }
            }
            .navigationTitle("Resolve conflicts")
            .navigationDestination(for: ConflictRoute.self) { route in
                ConflictDetailView(route: route)
            }
        }
    }
}

enum ConflictRoute: Hashable {
    case game(PersistentIdentifier)
    case item(PersistentIdentifier)
}
```

- [ ] **Step 14.3: Write `ConflictDetailView.swift`**

Write `GameTracker/Views/ConflictDetailView.swift`:

```swift
import SwiftUI
import SwiftData

/// Per-row conflict picker. Shows the local fields side-by-side with what
/// the user can compare against (in v1 we don't download a fresh server
/// version — we already received it on the conflict push response and
/// could carry it forward, but for simplicity v1 just offers:
///   "Keep phone"   → mark localModified, push will retry
///   "Keep server"  → discard local edit, mark synced, server's row stays
///
/// A future plan can add the field-level merge picker from the spec.
struct ConflictDetailView: View {
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    let route: ConflictRoute

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("This row was edited both on the phone and on the server.")
                    .foregroundStyle(.secondary)

                switch route {
                case .game(let id):
                    if let g: Game = self[id] { gameContent(g) }
                case .item(let id):
                    if let i: Item = self[id] { itemContent(i) }
                }
            }
            .padding()
        }
        .navigationTitle("Conflict")
    }

    // MARK: - Content

    @ViewBuilder
    private func gameContent(_ g: Game) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Phone version", systemImage: "iphone").font(.headline)
            Text("Title: \(g.title)")
            Text("Platform: \(g.platform)")
            if let r = g.starRating { Text("Stars: \(r)") }
        }
        .padding()
        .background(Color.blue.opacity(0.1))
        .cornerRadius(8)

        Button("Keep phone version (retry push)") {
            g.syncState = .localModified
            try? context.save()
            dismiss()
        }
        .buttonStyle(.borderedProminent)
        .frame(maxWidth: .infinity)

        Button("Keep server version (discard phone edits)") {
            g.syncState = .synced
            // Server's row hasn't been re-fetched into local DB yet — next /sync/changes
            // will pull it (since lastSyncedAt < server's updated_at).
            // Reset lastSyncedAt to force re-fetch.
            g.lastSyncedAt = nil
            try? context.save()
            dismiss()
        }
        .buttonStyle(.bordered)
        .frame(maxWidth: .infinity)
    }

    @ViewBuilder
    private func itemContent(_ i: Item) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Phone version", systemImage: "iphone").font(.headline)
            Text("Title: \(i.title)")
            Text("Category: \(i.category)")
        }
        .padding()
        .background(Color.blue.opacity(0.1))
        .cornerRadius(8)

        Button("Keep phone version (retry push)") {
            i.syncState = .localModified
            try? context.save()
            dismiss()
        }
        .buttonStyle(.borderedProminent)
        .frame(maxWidth: .infinity)

        Button("Keep server version (discard phone edits)") {
            i.syncState = .synced
            i.lastSyncedAt = nil
            try? context.save()
            dismiss()
        }
        .buttonStyle(.bordered)
        .frame(maxWidth: .infinity)
    }

    // MARK: - Lookup

    /// Subscript helper to resolve a PersistentIdentifier into its model.
    private subscript<T: PersistentModel>(_ id: PersistentIdentifier) -> T? {
        return context.model(for: id) as? T
    }
}
```

- [ ] **Step 14.4: Build (smoke check — UI doesn't have automated tests yet)**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' build 2>&1 | tail -5
```

Expected: `** BUILD SUCCEEDED **`. (Functional testing happens in Task 16.)

- [ ] **Step 14.5: Commit**

```bash
git add GameTracker/Views/ConflictBannerView.swift GameTracker/Views/ConflictListView.swift GameTracker/Views/ConflictDetailView.swift
git commit -m "Add conflict banner, list, and per-row keep-phone/keep-server picker"
```

---

## Task 15: Debug home view — list games + manual sync + sign out

**Files:**
- Modify: `GameTracker/Views/DebugHomeView.swift` (replace the stub from Task 8)
- Modify: `GameTracker/RootView.swift` (inject the sync engine)
- Modify: `GameTracker/GameTrackerApp.swift` (build + share the sync engine)

This view is a temporary landing screen that exercises every sync path end-to-end. Plan 3 replaces it with the real Library tab.

- [ ] **Step 15.1: Replace `DebugHomeView.swift`**

Overwrite `GameTracker/Views/DebugHomeView.swift`:

```swift
import SwiftUI
import SwiftData

/// Temporary post-login landing screen. Lists all games (title only),
/// shows sync state, and exposes manual controls. Plan 3 replaces this
/// with the real five-tab interface.
struct DebugHomeView: View {
    @Environment(AuthManager.self) private var authManager
    @Environment(\.modelContext) private var context

    @Query(sort: \Game.createdAt, order: .reverse) private var games: [Game]

    let syncEngine: SyncEngine
    @Bindable var status: SyncStatus

    @State private var showConflicts = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }

                List {
                    Section("Sync") {
                        HStack {
                            Text("Status")
                            Spacer()
                            statusLabel
                        }
                        if let last = status.lastSyncedAt {
                            HStack {
                                Text("Last synced")
                                Spacer()
                                Text(last.formatted(date: .abbreviated, time: .standard))
                                    .foregroundStyle(.secondary)
                            }
                        }
                        Button("Sync now") {
                            Task { try? await syncEngine.runOnce() }
                        }
                        .disabled(status.phase == .syncing)
                    }

                    Section("Games (\(games.count))") {
                        if games.isEmpty {
                            Text("No games yet — sync to pull them down")
                                .foregroundStyle(.secondary)
                        }
                        ForEach(games) { g in
                            HStack {
                                Text(g.title)
                                Spacer()
                                syncStateBadge(g.syncState)
                            }
                        }
                    }

                    Section("Account") {
                        if case .loggedIn(let uid, let username) = authManager.state {
                            HStack {
                                Text("User")
                                Spacer()
                                Text("\(username) (#\(uid))").foregroundStyle(.secondary)
                            }
                        }
                        Button("Sign out", role: .destructive) {
                            Task { await signOut() }
                        }
                    }
                }
            }
            .navigationTitle("gameTracker")
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task {
                // Initial sync on view appear.
                try? await syncEngine.runOnce()
            }
            .refreshable {
                try? await syncEngine.runOnce()
            }
        }
    }

    private var statusLabel: some View {
        Group {
            switch status.phase {
            case .idle:               Text("Idle").foregroundStyle(.secondary)
            case .syncing:            HStack { ProgressView(); Text("Syncing…") }
            case .error(let message): Text(message).foregroundStyle(.red).lineLimit(2)
            }
        }
    }

    @ViewBuilder
    private func syncStateBadge(_ state: SyncState) -> some View {
        switch state {
        case .synced:        Image(systemName: "checkmark.circle").foregroundStyle(.secondary)
        case .localNew:      Text("new").font(.caption).foregroundStyle(.blue)
        case .localModified: Text("edit").font(.caption).foregroundStyle(.orange)
        case .localDeleted:  Text("del").font(.caption).foregroundStyle(.red)
        case .conflict:      Text("⚠ conflict").font(.caption).foregroundStyle(.red)
        }
    }

    private func signOut() async {
        // Best-effort token revoke; never blocks logout.
        // (AuthAPI not in this view's scope — caller wires it via the engine's APIClient.)
        authManager.clearLocalSession()
        // Wipe local DB so the next user starts clean.
        do {
            try context.delete(model: Game.self)
            try context.delete(model: Item.self)
            try context.delete(model: GameCompletion.self)
            try context.delete(model: GameImage.self)
            try context.delete(model: ItemImage.self)
            try context.delete(model: SyncMetadata.self)
            try context.save()
        } catch {
            // Non-fatal; state will be inconsistent until next login wipe.
        }
    }
}
```

- [ ] **Step 15.2: Update `RootView.swift` to pass the sync engine + status**

Replace `GameTracker/RootView.swift` with:

```swift
import SwiftUI

struct RootView: View {
    @Environment(AuthManager.self) private var authManager
    let authAPI: AuthAPI
    let syncEngine: SyncEngine
    @Bindable var status: SyncStatus

    var body: some View {
        switch authManager.state {
        case .loggedOut:
            LoginView(authAPI: authAPI)
        case .loggedIn:
            DebugHomeView(syncEngine: syncEngine, status: status)
        }
    }
}
```

- [ ] **Step 15.3: Update `GameTrackerApp.swift` to build the sync engine**

Replace `GameTracker/GameTrackerApp.swift` with:

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

    var body: some Scene {
        WindowGroup {
            RootViewContainer(authAPI: authAPI, syncAPI: syncAPI, status: status)
                .environment(authManager)
        }
        .modelContainer(container)
    }
}

/// Wraps RootView so we can grab the `modelContext` from the environment
/// (which is only injected *after* `.modelContainer(...)`) and build the
/// SyncEngine with it.
private struct RootViewContainer: View {
    @Environment(\.modelContext) private var context
    let authAPI: AuthAPI
    let syncAPI: SyncAPI
    @Bindable var status: SyncStatus

    var body: some View {
        RootView(authAPI: authAPI,
                 syncEngine: SyncEngine(context: context, syncAPI: syncAPI, status: status),
                 status: status)
    }
}
```

- [ ] **Step 15.4: Build and run on Simulator**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 15' build 2>&1 | tail -5
```

Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 15.5: Manual verification — pull-to-refresh sync against live server**

Run in Simulator. Log in with real credentials. After login:
1. The "Syncing…" indicator should appear briefly.
2. The "Games" section should populate with the user's actual games from the server.
3. Each game shows a green checkmark (synced).
4. Pulling down on the list triggers another sync.

If any of these fail, debug before continuing — the rest of the plan depends on a working sync round-trip.

- [ ] **Step 15.6: Commit**

```bash
git add GameTracker/Views/DebugHomeView.swift GameTracker/RootView.swift GameTracker/GameTrackerApp.swift
git commit -m "Add DebugHomeView with list, manual sync, and sign-out"
```

---

## Task 16: End-to-end live integration test on Simulator

**Files:**
- Create: `GameTrackerTests/LiveIntegrationTests.swift`

This task verifies the entire stack against a real local PHP server (the same `tests/v2/run-all.sh` harness from Plan 1). It exists so future regressions in the sync engine are caught automatically, not just manually.

Because it requires the PHP server to be running, the test is **disabled by default** and is opt-in via the environment variable `GT_LIVE_TEST=1`.

- [ ] **Step 16.1: Write the live integration test**

Write `GameTrackerTests/LiveIntegrationTests.swift`:

```swift
import XCTest
import SwiftData
@testable import GameTracker

/// Hits a real PHP dev server + test database. To run:
///
/// In the web-app repo:
///   bash tests/v2/setup-test-db.sh
///   php -S localhost:8000 router.php
///
/// In Xcode: edit the GameTracker scheme → Test → Environment Variables:
///   GT_LIVE_TEST       = 1
///   GT_SERVER_BASE_URL = http://localhost:8000
///   GT_TEST_USERNAME   = testuser
///   GT_TEST_PASSWORD   = test_password
///
/// Then ⌘U. Without those env vars the test is skipped.
final class LiveIntegrationTests: XCTestCase {

    private var baseURL: URL!
    private var username: String!
    private var password: String!

    override func setUpWithError() throws {
        let env = ProcessInfo.processInfo.environment
        guard env["GT_LIVE_TEST"] == "1" else {
            throw XCTSkip("Set GT_LIVE_TEST=1 to run live integration tests")
        }
        guard let urlStr = env["GT_SERVER_BASE_URL"],
              let url = URL(string: urlStr),
              let u = env["GT_TEST_USERNAME"],
              let p = env["GT_TEST_PASSWORD"]
        else {
            throw XCTSkip("Set GT_SERVER_BASE_URL, GT_TEST_USERNAME, GT_TEST_PASSWORD")
        }
        baseURL = url
        username = u
        password = p
    }

    func test_full_sync_round_trip() async throws {
        let (_, ctx) = try InMemoryContainer.make()

        // Login
        var token: String? = nil
        let client = APIClient(baseURL: baseURL, tokenProvider: { token })
        let auth = AuthAPI(client: client)
        let login = try await auth.login(username: username, password: password, deviceName: "xctest")
        token = login.token

        // Sync
        let status = await SyncStatus()
        let engine = await SyncEngine(context: ctx, syncAPI: SyncAPI(client: client), status: status)
        try await engine.runOnce()

        // Create a new local game and push it
        let g = Game(title: "Sync Test \(UUID().uuidString.prefix(8))", platform: "TestPlatform", syncState: .localNew)
        ctx.insert(g)
        try ctx.save()

        try await engine.runOnce()
        XCTAssertNotNil(g.serverId, "newly-created game should have a server ID after push")
        XCTAssertEqual(g.syncState, .synced)

        // Edit the game and push again
        g.title = g.title + " (edited)"
        g.syncState = .localModified
        try ctx.save()

        try await engine.runOnce()
        XCTAssertEqual(g.syncState, .synced)

        // Clean up: delete the test row
        g.syncState = .localDeleted
        try ctx.save()
        try await engine.runOnce()
        // After deletion, the row should still exist locally with `localDeleted` cleared OR be removed.
        // The push response is `accepted`; we currently still mark it synced but server-deleted.
        // (A future cleanup pass can prune `localDeleted`+`synced` rows.)

        // Revoke
        _ = try await auth.revoke()
    }
}
```

- [ ] **Step 16.2: Run with the live env vars set**

In a separate terminal in the web-app repo:
```bash
cd ~/Library/Mobile\ Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker
bash tests/v2/setup-test-db.sh
php -S localhost:8000 router.php &
```

Then in Xcode, edit the `GameTracker` scheme → Test action → Environment Variables, add the four `GT_*` vars from the test's docstring.

Run ⌘U.

Expected: `LiveIntegrationTests.test_full_sync_round_trip` passes. All previous tests still pass (they don't require the live server because `GT_LIVE_TEST` is unset in regular runs).

- [ ] **Step 16.3: Document the test in the README**

(Skip the README update — the root README in single-repo mode covers iOS at a high level; a per-test docstring is sufficient documentation.)

- [ ] **Step 16.4: Commit**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTrackerTests/LiveIntegrationTests.swift
git commit -m "Add live-server integration test (opt-in via GT_LIVE_TEST=1)"
```

---

## Task 17: Push and wrap up

**Files:** none

- [ ] **Step 17.1: Verify clean working tree**

Run:
```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status
```
Expected: `nothing to commit, working tree clean`.

- [ ] **Step 17.2: Push to the existing GitHub remote**

Direct push:
```bash
git push
```

Or, if you prefer a PR for review:
```bash
git checkout -b plan-2-sync-engine
git push -u origin plan-2-sync-engine
gh pr create --fill
```

- [ ] **Step 17.3: Mark the plan complete**

Update this plan's checkboxes from `- [ ]` to `- [x]` for every completed step, then commit:

```bash
git add docs/superpowers/plans/2026-05-21-ios-skeleton-and-sync.md
git commit -m "Mark Plan 2 (iOS skeleton + sync engine) complete"
```

---

## What this plan does NOT build (Plan 3+)

- The five real tabs (Library, Items, Spin, Stats, Settings)
- Game Detail / Item Detail screens
- Add-game / Edit-game flows
- **Debounced sync trigger after local edits** (spec §3): no add/edit UI exists in Plan 2, so there are no local edits to debounce. The trigger infrastructure ships in Plan 3 next to the edit screens it serves.
- **`image_cache` SwiftData table** (spec §2): Plan 2 caches images on disk using deterministic filenames (`cover_<gameServerId>_<face>_<size>.jpg`), so the local file path is computed from the row instead of stored. A future plan can add the table if LRU eviction or last-fetched timestamps become useful.
- PriceCharting + Metacritic UI (`ProxiesAPI` not built — those endpoints exist on the server but the iOS-side client + screens land in Plan 3 where they're used)
- Cover-upload UI (the `ImagesAPI` covers download; upload UI ships in Plan 3)
- Cover-flow view, search, filters, view-mode toggle
- Currently Playing widget (separate target — Plan 4)
- Field-level conflict-merge UI (deferred per spec open question)
- Sideloading workflow / Sideloadly `.ipa` build automation (Plan 4)
- DuckDNS configuration / TLS certificate setup (server-side, addressed at deploy time)

When all 17 tasks are checked off, the iOS app:
- Logs in against the live `/api/v2/` server
- Holds a full offline copy of the user's collection in SwiftData
- Syncs bidirectionally with the server (changes + push)
- Detects and surfaces conflicts (keep-phone / keep-server)
- Caches cover images on disk
- Has a debug home screen confirming everything works end-to-end

Plan 3 (the five tabs and their detail screens) can then build on top of this foundation without re-touching networking, storage, or sync code.
