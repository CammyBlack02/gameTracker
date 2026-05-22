# iOS Settings Tab Design (Plan 4a)

**Status:** Approved 2026-05-22.

## Overview

Replace today's minimal Settings tab — which contains only Account + Sign out — with a full-featured Settings screen matching the master spec's Tab 5 layout. Adds four new sections: **Sync**, **Appearance**, **Storage**, **About**. The existing Account section stays as-is.

The Appearance picker in this plan covers only the three Apple color schemes — System, Light, Dark — using `@AppStorage` + `.preferredColorScheme(_:)`. A richer theming layer (Matrix, retro Mac, etc.) is deferred to Plan 4b, which will *extend* the same AppStorage key rather than replace this plumbing.

## Goals

- Provide an obvious "Sync now" button for users who don't want to wait on the next auto-trigger.
- Surface how many unresolved conflicts exist, and link to the existing resolution UI.
- Let the user toggle dark mode immediately.
- Let the user see how much disk the cover cache is consuming and wipe it if desired.
- Let the user confirm app version and open the web app in Safari.

## Non-goals (out of scope for 4a)

- **Rich themes** — Matrix, retro Mac, etc. Plan 4b territory.
- **Theme abstraction layer** — no `Theme` protocol / `ThemeManager` / semantic color tokens. `@AppStorage` + `.preferredColorScheme` is the entire mechanism in 4a; 4b will replace the storage-key consumer without breaking the picker UI.
- **Sync engine changes** — no new sync APIs; we wire the existing `SyncTrigger` / `SyncMetadata` / `ConflictListView` together.
- **Server changes** — none. Appearance is per-device, not synced to the account.
- **Custom About content** — no credits, changelog, or licensing screens.
- **Storage management beyond image cache** — SwiftData store size is not surfaced; "Clear cache" only touches the image cache directory, not the SwiftData store (that's what Sign out does).

## Section 1: Sync section

### Layout

```
Section "Sync"
  Row 1:  "Last synced"        <relative time>
  Row 2:  "Sync now"            [button, full-width, with spinner during sync]
  Row 3:  "Conflicts (N)"        ▸          ← conditional: only when N > 0
```

### Data sources

- **Last synced** — most recent successful pull timestamp. The existing `SyncMetadata` model stores per-table `lastPullAt` values. The Settings row shows the *most recent across all tables*, formatted with `RelativeDateTimeFormatter` ("2 minutes ago", "yesterday", "3 days ago"). Re-computes on `.onReceive(timer)` once per minute while visible.
- **Conflict count** — query SwiftData for rows with `syncState == .conflicted` across `Game`, `Item`, `GameCompletion`. The exact field name matches the existing `ConflictListView`'s query — read that view's source as the reference.

### "Sync now" behavior

- Tap → set local `@State syncInFlight = true`, call the existing manual-sync entry point on `SyncTrigger` (or `SyncEngine` directly if SyncTrigger has no manual variant — implementation chooses based on what exists), await completion, then `syncInFlight = false`.
- During sync: button label swaps to a `ProgressView`. Button is `.disabled(syncInFlight)`.
- On success: "Last synced" row's relative time updates on the next render (the underlying `SyncMetadata` was just touched).
- On error: the existing `SyncStatusBannerView` already surfaces sync errors app-wide. Settings does not duplicate that — we don't show an inline error row.

### Conflicts row

- `NavigationLink` pushing `ConflictListView` onto the Settings nav stack.
- Hidden entirely when conflict count is zero (no row rendered, no chevron, no zero-count clutter).
- Count refreshes on `.onAppear` and on `.task(id:)` watching a publisher from `SyncTrigger` if one exists; otherwise re-fetch on appear is sufficient — stale-by-a-few-seconds is acceptable for a Settings row.

## Section 2: Appearance section

### Layout

```
Section "Appearance"
  Row 1:  "Theme"   [System ▾]  ← Menu picker
```

### Picker options

Three cases: **System** (default), **Light**, **Dark**. Single-select menu picker, inline value display.

### Persistence + application

- Storage: `@AppStorage("appearanceMode") private var appearanceMode: AppearanceMode = .system` with a `String`-raw enum at the app's root.
- App's root view (the `App` struct's `body`, before tabs) applies `.preferredColorScheme(appearanceMode.colorScheme)` where `colorScheme` returns:
  - `.system` → `nil` (no override; follows OS)
  - `.light` → `.light`
  - `.dark` → `.dark`
- Per-device, not per-account. Survives sign-out (AppStorage outlives the SwiftData wipe).

### Forward-compatibility with Plan 4b

- The `AppearanceMode` enum is the seam. Plan 4b adds more cases (e.g. `.matrix`, `.retroMac`) and replaces the root-level `.preferredColorScheme(...)` consumer with a `ThemeManager` that maps each case to a full color/font palette.
- The Settings picker UI structure stays — only the case list grows. The `@AppStorage` key (`"appearanceMode"`) stays — values previously stored as `"system" | "light" | "dark"` remain valid in 4b.
- This means **Plan 4b does not break existing user preferences**.

## Section 3: Storage section

### Layout

```
Section "Storage"
  Row 1:  "Cached images"  <size>
  Row 2:  "Clear image cache"  [button, full-width, destructive role]
```

### Data sources

- **Cached images size** — recursive `FileManager.default.attributesOfItem(atPath:)` walk of `ImagesAPI.cacheRoot`, including any subdirectories (`coversThumbs`, `coversFull` if they exist, etc.). Sum bytes, format with `ByteCountFormatter` (`.useMB` / `.useKB`, count style `.file`).
- Computed in a small helper struct `ImageCacheSizeCalculator` (new) so the logic is unit-testable without mounting the Settings view. Settings view calls it `async` on `.task` and stores the result in `@State`.
- Re-computed: on view appear, after Clear button completes.

### Clear button behavior

- Confirmation alert: "Clear cached images? This wipes <size> of downloaded covers. They re-download as you browse. Your library and items are not affected."
- On confirm: `FileManager.default.removeItem(at: cacheRoot)`, then `try? FileManager.default.createDirectory(at: cacheRoot, withIntermediateDirectories: true)` to restore the empty directory (matches `ImagesAPI.init`'s setup).
- Lazy re-download — `CoverImage` views naturally re-fetch when their on-disk file is missing. No app-wide reload needed.
- Recompute size; row should now read something like "0 bytes" or "—".

### What's NOT included

- SwiftData store size — Sign out handles wipe of the local DB; surfacing the store size is unnecessary for a personal app.
- Per-game / per-image breakdown — single aggregate is enough.

## Section 4: About section

### Layout

```
Section "About"
  Row 1:  "Version"  <short> (<build>)
  Row 2:  "Web app"  cammysgametracker.duckdns.org  ↗︎ ← Link, opens Safari
```

### Data sources

- **Version:** `Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String` + `CFBundleVersion` for the build number. Displayed as `"1.0 (42)"`.
- **Web app URL:** Use `Config.serverBaseURL` directly — same URL the app uses to talk to the API. No separate "web-app URL" constant; the API host *is* the web app.
- Implemented with SwiftUI's `Link("…", destination: …)` so it opens in Safari (in-app SFSafariViewController is not used here — owner can long-press if needed).

## Section 5: Final section ordering

Top-to-bottom:

1. **Sync** — most actionable, most frequently used.
2. **Account** — existing.
3. **Appearance** — quick toggle, deserves to be near the top after the actionable stuff.
4. **Storage** — destructive button, lower priority.
5. **About** — terminal section.

(Note: master spec listed Sync → Account → Storage → Appearance → About. Reordering Appearance above Storage because dark-mode toggling is a more common interaction than cache clearing.)

## File structure

### New iOS files

```
ios/GameTracker/GameTracker/
  Settings/
    AppearanceMode.swift            — enum + AppStorage helpers
    ImageCacheSizeCalculator.swift  — disk-usage helper (testable)
```

### Modified iOS files

```
ios/GameTracker/GameTracker/
  Views/Settings/SettingsView.swift  — rewrites the existing minimal screen
                                       into the full five-section layout
  GameTrackerApp.swift               — apply .preferredColorScheme at root
```

### Untouched

- All other tabs (Library, Items, Completions, Stats).
- Sync engine, API client, models, networking layer.
- Existing `ConflictListView` / `ConflictDetailView` / `SyncStatusBannerView` — just linked to.
- `Config.swift` — no new constants needed.

## Testing

- **Unit:** `ImageCacheSizeCalculator` gets a small test that creates a temp directory with known file sizes and verifies the byte sum.
- **Unit:** `AppearanceMode.colorScheme` maps each case correctly.
- **UI / manual checkpoint:** sync now / conflict count link / appearance switch / clear-cache flow / about link — single end-of-plan checkpoint per `feedback_per_feature_checkpoints`.

## Open questions to revisit during implementation

- Does `SyncTrigger` expose a manual-trigger method, or do we need to call `SyncEngine` directly? Implementation plan will determine — both are existing APIs; the picker view layer is identical either way.
- Does any cached image live outside `cacheRoot` (e.g. extras thumbs in a subdirectory at a different root)? The implementation plan's first step is to grep — if so, sum across all roots.
