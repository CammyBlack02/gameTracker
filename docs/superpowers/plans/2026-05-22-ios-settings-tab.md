# iOS Settings Tab Implementation Plan (Plan 4a)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace today's minimal Settings tab (Account + Sign out only) with the full five-section layout from the master spec: **Sync** (last synced, Sync now, Conflicts), **Account** (unchanged), **Appearance** (System / Light / Dark), **Storage** (cache size, Clear), **About** (version, Web app link).

**Architecture:** One new SwiftUI enum (`AppearanceMode`) + one new helper (`ImageCacheSizeCalculator`) + a full rewrite of `SettingsView.swift`. `.preferredColorScheme(...)` is applied at the `WindowGroup` root in `GameTrackerApp.swift`, driven by `@AppStorage("appearanceMode")`. Sync Now calls existing `SyncEngine.runOnce()` directly (no debounce). Conflict count comes from `@Query` predicates matching `ConflictListView`. Cache size sums the four `ImageCachePaths` URLs.

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData, `@AppStorage`, `XCTest` (unit tests). No new server endpoints, no new packages, no new models.

**Predecessors:** Plans 3a–3e complete. Branch `plan-4a-settings-tab` already created with the design spec committed (`48c6f91`). Spec: [`docs/superpowers/specs/2026-05-22-ios-settings-tab-design.md`](../specs/2026-05-22-ios-settings-tab-design.md).

**Execution rhythm:** Per memory `feedback_per_feature_checkpoints` — Plan 4a's five sections all become visible on the same screen at once, so bundling into a single commit (with one end-of-plan checkpoint covering all five sections) is the right granularity, matching Plan 3e's pattern.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-4a-settings-tab`, branched off `main` (Plan 3e merged at `253a341`).
- **Pre-existing changes to leave alone in every commit:**
  - `js/completions.js` — old uncommitted whitespace edit.
  - `scripts/generate-thumbnails 2.php` + `tests/v2/*2.sh` — iCloud Drive conflict copies.
  - `ios/GameTracker/GameTrackerTests/Helpers 2/` — iCloud Drive folder duplicate; do not touch.
- **iCloud Drive Swift conflict files:** clear before each test pass:

  ```bash
  find ios/GameTracker -name "* [0-9].swift" -print -delete
  ```

---

## What this plan does NOT build (Plan 4b/4c+ territory)

- Rich themes (Matrix, retro Mac, etc.) — Plan 4b.
- A `Theme` / `ThemeManager` abstraction — Plan 4b.
- CoverFlow on the Library — Plan 4c.
- Conflict-resolution UI changes — `ConflictListView` is reused as-is.
- Sync engine changes — `SyncEngine.runOnce()` is called as-is.
- SwiftData store size measurement — only the image cache is surfaced.
- Per-image cache breakdown — single aggregate byte count.
- Custom credits / changelog / licensing — About section is two rows only.
- Server changes — none.

---

## File structure

### New iOS files

```
ios/GameTracker/GameTracker/Settings/AppearanceMode.swift
ios/GameTracker/GameTracker/Settings/ImageCacheSizeCalculator.swift
ios/GameTracker/GameTrackerTests/AppearanceModeTests.swift
ios/GameTracker/GameTrackerTests/ImageCacheSizeCalculatorTests.swift
```

### Modified iOS files

| File | Change |
|---|---|
| `GameTrackerApp.swift` | Add `@AppStorage` for appearance + `.preferredColorScheme(...)` on the WindowGroup root. |
| `Views/Tabs/RootTabView.swift` | Pass `syncEngine` to `SettingsView(...)`. |
| `Views/Settings/SettingsView.swift` | Full rewrite — five sections instead of two. |

### Untouched

- All other tabs (Library, Items, Completions, Stats).
- `ConflictListView`, `ConflictDetailView`, `ConflictBannerView`, `SyncStatusBannerView`.
- Sync engine, API client, models.
- `Config.swift`, `ImagesAPI.swift`, `ImageCachePaths` enum.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-22-ios-settings-tab.md` (this file)

- [x] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current        # → plan-4a-settings-tab
git log --oneline -3              # spec on top of 3e merge
git status --short                # only pre-existing junk
```

Expected: branch is `plan-4a-settings-tab`; spec commit (`48c6f91`) sits on top of the 3e merge (`253a341`).

- [x] **Step 0.2: Clear iCloud Swift conflict files**

```bash
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

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
git add docs/superpowers/plans/2026-05-22-ios-settings-tab.md
git commit -m "Add Plan 4a (iOS Settings tab) implementation plan"
```

---

## Task 1: `AppearanceMode` enum + unit tests (TDD)

**Files:**
- Create: `ios/GameTracker/GameTracker/Settings/AppearanceMode.swift`
- Create: `ios/GameTracker/GameTrackerTests/AppearanceModeTests.swift`

The enum is the seam between Plan 4a's three Apple color schemes and Plan 4b's richer themes. Raw values must be stable: Plan 4b extends the case list but does not rename `system | light | dark`.

- [x] **Step 1.1: Write the failing tests**

Write `ios/GameTracker/GameTrackerTests/AppearanceModeTests.swift`:

```swift
import XCTest
import SwiftUI
@testable import GameTracker

final class AppearanceModeTests: XCTestCase {

    func test_system_maps_to_nil_color_scheme() {
        XCTAssertNil(AppearanceMode.system.colorScheme)
    }

    func test_light_maps_to_light_color_scheme() {
        XCTAssertEqual(AppearanceMode.light.colorScheme, .light)
    }

    func test_dark_maps_to_dark_color_scheme() {
        XCTAssertEqual(AppearanceMode.dark.colorScheme, .dark)
    }

    func test_raw_values_are_stable() {
        XCTAssertEqual(AppearanceMode.system.rawValue, "system")
        XCTAssertEqual(AppearanceMode.light.rawValue, "light")
        XCTAssertEqual(AppearanceMode.dark.rawValue, "dark")
    }

    func test_all_cases_have_distinct_display_names() {
        let names = AppearanceMode.allCases.map(\.displayName)
        XCTAssertEqual(Set(names).count, AppearanceMode.allCases.count)
    }
}
```

- [x] **Step 1.2: Run tests — expect compile failure**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "BUILD FAILED|error:" | head -5
```

Expected: BUILD FAILED — `AppearanceMode` not found. That's correct; the next step creates it.

- [x] **Step 1.3: Implement `AppearanceMode.swift`**

Write `ios/GameTracker/GameTracker/Settings/AppearanceMode.swift`:

```swift
import SwiftUI

/// Persisted user preference for the app's color scheme. Stored in
/// UserDefaults under `"appearanceMode"` via `@AppStorage`.
///
/// Plan 4b will extend the case list with rich themes (Matrix, retro
/// Mac, etc.). The raw values `"system" | "light" | "dark"` are stable
/// across plans — adding new cases never invalidates an existing
/// preference.
enum AppearanceMode: String, CaseIterable, Identifiable {
    case system
    case light
    case dark

    var id: Self { self }

    /// Display label shown in the Settings picker.
    var displayName: String {
        switch self {
        case .system: return "System"
        case .light:  return "Light"
        case .dark:   return "Dark"
        }
    }

    /// Passed to a root-level `.preferredColorScheme(...)` modifier.
    /// `nil` means "no override — follow OS appearance."
    var colorScheme: ColorScheme? {
        switch self {
        case .system: return nil
        case .light:  return .light
        case .dark:   return .dark
        }
    }
}
```

- [x] **Step 1.4: Run tests — expect pass**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -3
```

Expected: `** TEST SUCCEEDED **`. (No commit yet — Tasks 1–5 ship as one bundle at Task 6.)

---

## Task 2: Apply `.preferredColorScheme` at the app root

**Files:**
- Modify: `ios/GameTracker/GameTracker/GameTrackerApp.swift`

- [x] **Step 2.1: Add `@AppStorage` + `.preferredColorScheme(...)`**

Find the `GameTrackerApp` struct's `@State` declarations:

```swift
    @State private var authManager = AuthManager()
    @State private var status = SyncStatus()
```

Insert a new `@AppStorage` property right after them:

```swift
    @AppStorage("appearanceMode") private var appearanceMode: AppearanceMode = .system
```

Then find the existing `body`:

```swift
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
```

Replace with:

```swift
    var body: some Scene {
        WindowGroup {
            RootViewContainer(authAPI: authAPI,
                              syncAPI: syncAPI,
                              proxiesAPI: proxiesAPI,
                              imagesAPI: imagesAPI,
                              status: status)
                .environment(authManager)
                .preferredColorScheme(appearanceMode.colorScheme)
        }
        .modelContainer(container)
    }
```

- [x] **Step 2.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**. (Appearance picker UI lands in Task 5; for now the app reads the default `.system` and behaves identically.)

---

## Task 3: `ImageCacheSizeCalculator` helper + unit tests (TDD)

**Files:**
- Create: `ios/GameTracker/GameTracker/Settings/ImageCacheSizeCalculator.swift`
- Create: `ios/GameTracker/GameTrackerTests/ImageCacheSizeCalculatorTests.swift`

Lives outside `SettingsView` so it's testable with a temp directory.

- [x] **Step 3.1: Write the failing tests**

Write `ios/GameTracker/GameTrackerTests/ImageCacheSizeCalculatorTests.swift`:

```swift
import XCTest
@testable import GameTracker

final class ImageCacheSizeCalculatorTests: XCTestCase {

    private var tempDir: URL!

    override func setUpWithError() throws {
        tempDir = FileManager.default.temporaryDirectory
            .appendingPathComponent("ImageCacheSizeCalculatorTests-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tempDir, withIntermediateDirectories: true)
    }

    override func tearDownWithError() throws {
        try? FileManager.default.removeItem(at: tempDir)
    }

    func test_empty_directory_returns_zero() {
        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir]), 0)
    }

    func test_missing_directory_returns_zero() {
        let missing = tempDir.appendingPathComponent("does-not-exist")
        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [missing]), 0)
    }

    func test_sums_file_sizes_in_single_root() throws {
        let a = tempDir.appendingPathComponent("a.bin")
        let b = tempDir.appendingPathComponent("b.bin")
        try Data(repeating: 0xAB, count: 1024).write(to: a)
        try Data(repeating: 0xCD, count: 2048).write(to: b)

        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir]), 1024 + 2048)
    }

    func test_walks_subdirectories() throws {
        let sub = tempDir.appendingPathComponent("sub/deep")
        try FileManager.default.createDirectory(at: sub, withIntermediateDirectories: true)
        let f = sub.appendingPathComponent("file.bin")
        try Data(repeating: 0xEF, count: 512).write(to: f)

        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir]), 512)
    }

    func test_sums_across_multiple_roots() throws {
        let other = FileManager.default.temporaryDirectory
            .appendingPathComponent("ImageCacheSizeCalculatorTests-other-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: other, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: other) }

        let a = tempDir.appendingPathComponent("a.bin")
        let b = other.appendingPathComponent("b.bin")
        try Data(repeating: 0xAB, count: 1024).write(to: a)
        try Data(repeating: 0xCD, count: 2048).write(to: b)

        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir, other]), 1024 + 2048)
    }

    func test_formatted_returns_non_empty_string() {
        XCTAssertFalse(ImageCacheSizeCalculator.formatted(1_500_000).isEmpty)
        XCTAssertFalse(ImageCacheSizeCalculator.formatted(0).isEmpty)
    }
}
```

- [x] **Step 3.2: Run tests — expect compile failure**

Same `xcodebuild test` command as Step 1.2. Expected: BUILD FAILED — `ImageCacheSizeCalculator` not found.

- [x] **Step 3.3: Implement `ImageCacheSizeCalculator.swift`**

Write `ios/GameTracker/GameTracker/Settings/ImageCacheSizeCalculator.swift`:

```swift
import Foundation

/// Computes total disk usage across one or more directories. Used by
/// the Settings tab's Storage row.
///
/// Pure helper — no SwiftUI dependency — so it unit-tests against a
/// temp directory without mounting a view.
enum ImageCacheSizeCalculator {

    /// Sum of file sizes of every regular file under each of `roots`,
    /// recursively. Missing directories contribute 0. Symbolic links
    /// are not followed. Enumeration errors are silently treated as 0
    /// — this is for display only.
    static func totalBytes(under roots: [URL]) -> Int64 {
        var total: Int64 = 0
        let keys: [URLResourceKey] = [.isRegularFileKey, .totalFileAllocatedSizeKey, .fileSizeKey]
        let keySet = Set(keys)
        for root in roots {
            guard FileManager.default.fileExists(atPath: root.path) else { continue }
            guard let enumerator = FileManager.default.enumerator(
                at: root,
                includingPropertiesForKeys: keys,
                options: [.skipsHiddenFiles]
            ) else { continue }
            for case let url as URL in enumerator {
                guard let values = try? url.resourceValues(forKeys: keySet) else { continue }
                guard values.isRegularFile == true else { continue }
                if let size = values.totalFileAllocatedSize ?? values.fileSize {
                    total += Int64(size)
                }
            }
        }
        return total
    }

    /// Human-readable, e.g. `"12.4 MB"`, `"0 bytes"`. Locale-dependent.
    static func formatted(_ bytes: Int64) -> String {
        let fmt = ByteCountFormatter()
        fmt.allowedUnits = [.useAll]
        fmt.countStyle = .file
        return fmt.string(fromByteCount: bytes)
    }
}
```

- [x] **Step 3.4: Run tests — expect pass**

Same command as Step 1.4. Expected: `** TEST SUCCEEDED **`.

---

## Task 4: Pass `syncEngine` through `RootTabView` to `SettingsView`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift`

`RootTabView` already receives `syncEngine` from `RootView`. We just need to forward it to `SettingsView` (which gains the parameter in Task 5).

- [x] **Step 4.1: Update the `SettingsView(...)` call site**

Find:

```swift
            SettingsView(authAPI: authAPI)
                .tabItem { Label("Settings", systemImage: "gear") }
```

Replace with:

```swift
            SettingsView(authAPI: authAPI, syncEngine: syncEngine)
                .tabItem { Label("Settings", systemImage: "gear") }
```

- [x] **Step 4.2: Build check — expected to FAIL until Task 5 lands**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD FAILED** — `SettingsView` doesn't accept `syncEngine` yet. Fixed by Task 5.

---

## Task 5: Rewrite `SettingsView.swift` — five sections

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Settings/SettingsView.swift`

Replace the entire file's contents.

- [x] **Step 5.1: Write the new file**

Overwrite `ios/GameTracker/GameTracker/Views/Settings/SettingsView.swift`:

```swift
import SwiftUI
import SwiftData

/// Settings tab — Plan 4a build-out.
///
/// Sections top-to-bottom: Sync, Account, Appearance, Storage, About.
struct SettingsView: View {

    // MARK: - Inputs

    let authAPI: AuthAPI
    let syncEngine: SyncEngine

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
            List {
                syncSection
                accountSection
                appearanceSection
                storageSection
                aboutSection
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
```

- [x] **Step 5.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

If the build fails with "cannot find type 'AppearanceMode' in scope" or similar, the Xcode file-system-synchronized group might need a beat — `find ios/GameTracker -name "* [0-9].swift" -print -delete` and rebuild.

---

## Task 6: Full test pass + bundle commit

**Files:** none modified in this task.

- [x] **Step 6.1: Clear iCloud conflict files**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

- [x] **Step 6.2: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: `** TEST SUCCEEDED **`. Allow up to 8 minutes.

If the first invocation reports `** TEST FAILED **` with no accompanying `error:` lines, it's likely simulator hiccup — try once more.

- [x] **Step 6.3: Pre-commit sanity check**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected listing:

- Untracked: `ios/GameTracker/GameTracker/Settings/AppearanceMode.swift`, `ios/GameTracker/GameTracker/Settings/ImageCacheSizeCalculator.swift`, `ios/GameTracker/GameTrackerTests/AppearanceModeTests.swift`, `ios/GameTracker/GameTrackerTests/ImageCacheSizeCalculatorTests.swift`
- Modified: `GameTrackerApp.swift`, `Views/Tabs/RootTabView.swift`, `Views/Settings/SettingsView.swift`
- Pre-existing junk to NOT commit: `js/completions.js`, `scripts/generate-thumbnails 2.php`, `tests/v2/* 2.sh`, `ios/GameTracker/GameTrackerTests/Helpers 2/`

- [x] **Step 6.4: Bundle commit Tasks 1–5**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Settings/AppearanceMode.swift \
        ios/GameTracker/GameTracker/Settings/ImageCacheSizeCalculator.swift \
        ios/GameTracker/GameTrackerTests/AppearanceModeTests.swift \
        ios/GameTracker/GameTrackerTests/ImageCacheSizeCalculatorTests.swift \
        ios/GameTracker/GameTracker/GameTrackerApp.swift \
        ios/GameTracker/GameTracker/Views/Tabs/RootTabView.swift \
        ios/GameTracker/GameTracker/Views/Settings/SettingsView.swift
git commit -m "Build out Settings tab: Sync, Appearance, Storage, About"
```

### 🛑 User checkpoint — Settings tab

Stop here. The owner ⌘R in Xcode (iPhone 17 sim) and verifies each section.

1. **Sync — Last synced row:** Open Settings. Row shows a relative time (or "—" if `SyncMetadata.lastSyncedAt` is nil — typically right after first install).
2. **Sync — Sync now button:** Tap. Spinner appears inline; resolves; "Last synced" row updates to "just now" / "a few seconds ago".
3. **Sync — Conflicts row visibility:** With no conflicts, the row is **absent**. (You'll only see this row when a real conflict exists; treat absence as correct.)
4. **Account — unchanged:** Username row + Sign out flow behaves exactly like before (do not actually sign out unless you want to).
5. **Appearance — System:** Picker defaults to "System". Toggle iOS Settings → Display → Appearance between Light/Dark with the app open; the app follows.
6. **Appearance — Light:** Pick Light. Whole app flips to light mode regardless of OS setting.
7. **Appearance — Dark:** Pick Dark. Whole app flips to dark mode regardless of OS setting.
8. **Appearance persists across app restart:** Force-quit + relaunch — picker still on Dark (or whichever you chose), and the app is in that mode.
9. **Storage — Cached images row:** Shows a non-zero size after browsing a couple of game covers. The row updates after Clear (next step).
10. **Storage — Clear button:** Confirm dialog appears with the byte count in it. Confirm. Row should drop to "Zero KB" / "0 bytes". Open Library — covers re-download.
11. **Storage — Clear button disabled when zero:** Immediately after a clear, the button is disabled until you browse and accumulate cache again.
12. **About — Version row:** Shows "X.Y (Z)" format (short version + build).
13. **About — Web app link:** Tap. Safari opens to `https://cammysgametracker.duckdns.org`.
14. **No regression on Library / Items / Completions / Stats.**

Resume only after owner confirms or reports a specific failure.

---

## Task 7: Push + open PR + wrap up

**Files:** none.

- [x] **Step 7.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk.

- [x] **Step 7.2: Push**

```bash
git push -u origin plan-4a-settings-tab
```

- [x] **Step 7.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-22-ios-settings-tab.md
git add docs/superpowers/plans/2026-05-22-ios-settings-tab.md
git commit -m "Mark Plan 4a (iOS Settings tab) complete"
git push
```

- [x] **Step 7.4: Open PR**

```bash
gh pr create --base main --head plan-4a-settings-tab \
  --title "Plan 4a: iOS Settings tab" \
  --body "$(cat <<'EOF'
## Summary

Replaces the minimal Settings tab (Account + Sign out only) with the full five-section layout from the master spec.

- **Sync:** Last-synced relative time, "Sync now" button (calls \`SyncEngine.runOnce()\`), Conflicts (N) link (only when N > 0) presenting the existing \`ConflictListView\`.
- **Account:** Unchanged from prior behaviour.
- **Appearance:** Picker — System / Light / Dark — backed by \`@AppStorage(\"appearanceMode\")\` and applied via \`.preferredColorScheme(...)\` at the WindowGroup root.
- **Storage:** Cache size across all four \`ImageCachePaths\` (\`coversThumbs\`, \`coversFull\`, \`extrasThumbs\`, \`extrasFull\`); Clear button with confirmation.
- **About:** App version (short + build) + tappable link to the web app.

### New supporting types

- \`AppearanceMode\` enum — single seam Plan 4b will extend with rich themes (Matrix, retro Mac, etc.) without breaking the AppStorage key.
- \`ImageCacheSizeCalculator\` — pure helper, unit-tested against a temp directory.

## Test Plan

- [x] \`xcodebuild test\` — full suite passes including new \`AppearanceModeTests\` and \`ImageCacheSizeCalculatorTests\`
- [x] Manual checkpoint: all five sections behave as specified; appearance persists across restart; cache clear empties Documents/covers and Caches/covers-full
- [x] No regression on Library / Items / Completions / Stats

## Not in scope (Plan 4b/4c+ territory)

Rich themes (Matrix, retro Mac), CoverFlow on Library, sync engine changes, conflict-UI changes, SwiftData store size measurement, per-image cache breakdown.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [x] Every referenced symbol exists: `AppearanceMode`, `ImageCacheSizeCalculator`, `SyncEngine.runOnce()`, `SyncMetadata.lastSyncedAt`, `Game.syncStateRaw`, `Item.syncStateRaw`, `ConflictListView`, `Config.serverBaseURL`, `ImageCachePaths.coversThumbs/.coversFull/.extrasThumbs/.extrasFull`, `AuthManager.state`, `AuthAPI.revoke()`. (All landed via prior plans + Tasks 1 + 3 of this plan.)
- [x] `@AppStorage("appearanceMode")` uses the same key string in both `GameTrackerApp.swift` and `SettingsView.swift`. (Key is the persistence boundary; mismatched keys would silently store separate values.)
- [x] `SettingsView`'s parameter list `(authAPI:, syncEngine:)` is identical between Task 4's call site (`RootTabView`) and Task 5's struct definition.
- [x] `cachePaths` in `SettingsView` covers all four `ImageCachePaths` (coversThumbs, coversFull, extrasThumbs, extrasFull) — not just the one ImagesAPI currently uses.
- [x] All commit messages cover visible behaviour and bundle interdependent files (Plan 3e precedent).
- [x] No "TBD" or "implement later" anywhere except the meta-line in this self-review checklist.
