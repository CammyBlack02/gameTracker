# iOS Items Tab — Design (Plan 3c)

**Date:** 2026-05-22
**Status:** Design approved, awaiting implementation plan
**Author:** Cameron (with Claude)
**Predecessors:** [2026-05-15-ios-app-design.md](2026-05-15-ios-app-design.md), Plan 3a (Library tab + game flows), Plan 3b (Completions tab)

## Overview

Replace the **Items** placeholder tab in `RootTabView` with a working Items surface — a single searchable list of every console and accessory the user owns, with category filtering, full CRUD via add/edit sheets, and a per-item detail view. Item images are surfaced from the server using the same three-format dispatch (data URI / HTTPS / bare filename) the games cover endpoint already handles, with a small server-side parity addition.

Tab bar after Plan 3c: **Library / Items / Completions / Stats / Settings**. Stats remains a placeholder; that's Plan 3d's job.

## Goals

- Browse, search, and filter the user's full console + accessory collection on the phone.
- Add, edit, and soft-delete items locally; round-trip via the existing v2 sync layer.
- Display real item cover images, matching how the web app surfaces them.
- Reuse Plan 3a's pattern (LibraryView + GameDetailView) as the template — no new architectural surface.

## Non-goals (out of scope for Plan 3c)

- ~~**Image upload from iOS**~~ — **front-cover upload was folded in during checkpoint**; see Section 9 below. Back-cover upload stays deferred.
- **The `item_images` extras gallery** on the detail view. Single front/back cover only in v1.
- **Stats tab.** Separate plan (3d candidate).
- **Per-game "add completion" button** on `GameDetailView`. Still deferred.
- **Per-item "linked games"** UI (e.g., "games for this console"). Out of scope.
- **Image upload for games** (only items for now). Games already have a working web upload UX; iOS image upload for games can be added later.
- **Cache invalidation when an item is edited on the web app** between iOS syncs. Single-user app for now; future plan can handle this if needed.

## Key Decisions (from brainstorming Q&A)

| Decision | Choice |
|---|---|
| Category UX | **Single list with a category filter chip** (All / Consoles / Accessories) |
| Image strategy | **Real images via server parity** — extend `cover.php` to take `?type=game\|item` |
| Detail page | **Yes — `ItemDetailView`** pushed onto the nav stack on row tap |
| Image-upload from iOS | **Front cover, in scope** (folded in during checkpoint). Data-URI base64 written to `item.frontImage`, sync push delivers it; `cover.php`'s existing data-URI dispatch serves it back. Zero new server work. |
| Image capture sources | **Camera** (UIImagePickerController via Representable) **+ Photo Library** (SwiftUI PhotosPicker), surfaced via action sheet |
| Compression | **1024px max longer edge, JPEG quality 0.7** — ~150–400KB per upload |
| Sort default | **Title A→Z**, hardcoded (matches LibraryView's default; no sort menu in v1) |
| Grid vs list default | **List** (matches LibraryView's default) |
| Grid/list persistence | **Per-session `@State`** (matches LibraryView) |
| Category filter placement | **Inline segmented `Picker` above the search field**; 3 options fits a chip, not worth a sheet |
| Pricing on the list row | **Detail-only**; keeps the row scannable |
| View-mode toggle placement | **Toolbar `ellipsis.circle` Menu** (matches LibraryView's pattern) |
| Quantity widget | **`Stepper` with minimum 1**, plus `×N` badge on the row when N > 1 |

---

## Section 1: High-level shape

```
RootTabView
└── (tab #2) ItemsView          ← replaces PlaceholderTabView("Items")
    ├── filter chip: All / Consoles / Accessories
    ├── search (title or platform)
    ├── grid / list toggle (toolbar)
    ├── ConflictBanner / SyncStatusBanner   (reused from 3a/3b)
    ├── + button → AddItemView (sheet)
    └── tap row → ItemDetailView (push)
        ├── front/back cover swap
        ├── full metadata
        └── Edit button → EditItemView (sheet)
```

All sync, error, and conflict UX is reused from Plan 3a — no new networking surfaces beyond the image endpoint extension below.

## Section 2: File structure

### New files

All under `ios/GameTracker/GameTracker/Views/Items/`:

```
Items/
├── ItemsView.swift             — main tab: @Query list, search, +, filter, sync, swipe-delete
├── ItemsListRow.swift          — single row (list mode): thumb + title + meta + quantity badge
├── ItemsGridCell.swift         — single cell (grid mode): square thumb + title overlay
├── ItemDetailView.swift        — detail page: cover, metadata, Edit button
├── AddItemView.swift           — form sheet for a new item
├── EditItemView.swift          — form sheet for an existing item
└── ItemFormBody.swift          — shared Form body used by Add + Edit
```

### Modified iOS files

- `Views/Tabs/RootTabView.swift` — replace the `Items` `PlaceholderTabView` with `ItemsView`.
- `Networking/ImagesAPI.swift` — generalize `downloadCover` to take a kind (game / item); add a cache-filename branch for items.
- `Views/Common/CoverImage.swift` — accept either a game or item server ID via a small discriminator; existing call sites (LibraryView, GameDetailView, CompletionsView, CompletionFormBody, GamePickerSheet) get a one-line update.

### Modified server files

- `api/v2/images/cover.php` — accept `?type=game|item` (default `game` for back-compat). When `type=item`, look up `items.front_image` / `items.back_image` by item ID, then run the same data-URI / HTTPS / bare-filename dispatch the games path already has. Zero new format handling.

### Untouched

- `RootView.swift`, `GameTrackerApp.swift` — `ItemsView` only needs `imagesAPI` + `syncEngine` + `syncTrigger` + `status`, all of which `RootTabView` already holds.
- Sync layer (`SyncEngine`, `PushBuilder`, `ChangeApplier`) — `Item` and `ItemImage` already round-trip; nothing to do.
- `Item` model — already has every field this UI needs.

---

## Section 3: View specifications

### 3.1 `ItemsView`

Reactive `@Query` of every non-deleted `Item`. Two display modes (list / grid), toggled from the toolbar `Menu`. Inline category filter and `.searchable` match title or platform substring.

**Sort:** hardcoded title A→Z. (Matches `LibraryView`'s default sort. No user-facing sort menu in v1.)

**View mode default:** `.list` (matches `LibraryView`).

**Filter logic:** category filter narrows `allItems` by `category == "console"` or `category == "accessory"`. "All" shows everything. UI: a segmented `Picker` directly above the `.searchable` field, hosted in the content `VStack` (not the toolbar) so it stays visible without expanding a menu.

**Toolbar (trailing):**
- `+` button → presents `AddItemView` as a sheet.
- `ellipsis.circle` `Menu` containing the view-mode `Picker` (List / Grid). No sort menu in v1.

**Row tap:** pushes `ItemDetailView(itemID: persistentModelID, …)` onto the NavigationStack via `.navigationDestination(for: PersistentIdentifier.self)` (same pattern as `LibraryView`).

**Swipe-to-delete:** soft-delete (`syncState = .localDeleted`) when `serverId` is set; hard-delete (`context.delete(item)`) otherwise. Pings `syncTrigger.pingAfterMutation()`.

**Pull-to-refresh:** `try? await syncEngine.runOnce()`. ConflictBanner + SyncStatusBanner stacked above the content area (same as Library/Completions).

### 3.2 `ItemsListRow` (list mode)

```
┌──┐  Console / Accessory  · Platform · Condition           ×3
│IMG│  Item Title (1–2 lines, weight: medium)               [sync badge]
└──┘
```

Cover thumb 40×60, rounded 4. Title + caption row. Category icon (`gamecontroller.fill` for console, `cable.connector` for accessory) inline before the platform. Quantity badge appears only when `quantity > 1`. `SyncStateBadge` (reused) on the right.

### 3.3 `ItemsGridCell` (grid mode)

Square front cover with title overlay at the bottom (`.bottom` gradient + Text). Quantity badge top-right if `> 1`. Sync badge top-left. Mirrors `GameGridCell`'s shape so the grid feels uniform across tabs.

### 3.4 `ItemDetailView`

Layout (top → bottom):

- Cover image: front by default. Tap toggles to back if `backImage` is non-nil.
- Title (large) + platform + category line.
- Section: **Pricing** — `Price paid: $X.XX` and `Pricecharting value: $Y.YY` (each shown only when non-nil).
- Section: **Condition & quantity** — condition string, `Quantity: N`.
- Section: **Description** — multi-line, only when non-empty.
- Section: **Notes** — multi-line, only when non-empty.
- Toolbar: trailing `Edit` button → presents `EditItemView` sheet.
- Identity: looked up by `PersistentIdentifier` (matches `GameDetailView`).

No "delete" button here — deletion stays the swipe-on-list gesture, like LibraryView.

### 3.5 `AddItemView` / `EditItemView` / `ItemFormBody`

Shared Form body, owned by the parent sheet (sheet anchor lessons from Plan 3b: no nested sheets, parents own state and presentation).

Form sections:

1. **Title & category** — `TextField` for title; segmented `Picker` for category (Console / Accessory).
2. **Platform & condition** — two `TextField`s. (Both optional; no preset menus in v1.)
3. **Price** — two `TextField`s (`pricePaid`, `pricechartingPrice`), `.decimalPad` keyboard, parsed to `Double?`.
4. **Quantity** — `Stepper(value: $quantity, in: 1...99)`.
5. **Description** — `TextField(axis: .vertical)`, 3–10 lines.
6. **Notes** — `TextField(axis: .vertical)`, 3–10 lines.

**Save:** Add → insert with `syncState = .localNew`. Edit → mutate fields, transition `.synced` → `.localModified` only. Both `try? context.save()` and `syncTrigger.pingAfterMutation()`.

**Cancel:** dismiss without saving.

**Category on the form:** the form binds to a local `enum ItemCategory: String { case console, accessory }` for UI ergonomics; on save, its `rawValue` is written into `Item.category` (which is `String` in the model). Default selection: `.console`.

**`canSave` rule:** title is non-empty. Category always has a selection (defaulted), so title is the only real gate.

---

## Section 4: Server endpoint extension

### `api/v2/images/cover.php`

Extend the existing endpoint to accept an optional `?type=game|item` query param. Default: `game` (so existing iOS clients continue to work unchanged).

```php
// after the existing $face/$size validation:
$type = $_GET['type'] ?? 'game';
if (!in_array($type, ['game', 'item'], true)) {
    v2_error('bad_request', 'type must be game or item', 400);
}

if ($type === 'item') {
    $col = $face === 'back' ? 'back_image' : 'front_image';
    $stmt = $pdo->prepare("SELECT $col AS path FROM items WHERE id = ? AND user_id = ?");
} else {
    $col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
    $stmt = $pdo->prepare("SELECT $col AS path FROM games WHERE id = ? AND user_id = ?");
}
// existing path-handling (data: / https:// / bare filename) is unchanged
```

The downstream format dispatch (data URI → decode-and-stream, https → curl-and-stream, bare filename → file-on-disk) is reused verbatim. ~10 lines added; no existing logic touched.

### iOS `ImagesAPI`

Add a `Kind` enum (or reuse the existing `ExtraType`, which is already `game | item`). Generalize the cover-download path:

```swift
enum CoverKind: String { case game, item }

func downloadCover(serverId: Int, kind: CoverKind, face: Face, size: Size) async throws -> URL {
    let filename = "\(kind.rawValue)_\(serverId)_\(face.rawValue)_\(size.rawValue).jpg"
    // existing dest-file caching logic
    return try await apiClient.download(
        path: "/api/v2/images/cover.php",
        query: ["id": String(serverId),
                "type": kind.rawValue,
                "face": face.rawValue,
                "size": size.rawValue]
    )
}
```

Existing `downloadCover(gameServerId:face:size:)` becomes a thin wrapper that calls `downloadCover(serverId: gameServerId, kind: .game, …)` to preserve every existing call site without a rename ripple.

### iOS `CoverImage`

Generalize to accept either kind. Cleanest shape: introduce a new init that takes `(serverId: Int?, kind: CoverKind, face:, size:, api:)`. The existing `init(gameServerId:…)` becomes a convenience wrapper that forwards with `kind: .game`. New `init(itemServerId:…)` does the same with `kind: .item`. Call sites in LibraryView / GameDetailView / CompletionsView / CompletionFormBody / GamePickerSheet keep working with no changes. New Items views call the item variant.

---

## Section 5: Sync behaviour (no new code)

`Item` is already in `SyncEngine`, `PushBuilder`, and `ChangeApplier` from Plan 2. Add/Edit/Delete in this plan exercises the existing path:

- **Add:** insert as `.localNew` → next sync pushes it → server assigns `serverId` → response promotes to `.synced`.
- **Edit:** `.synced → .localModified` → next sync pushes the diff → server confirms → back to `.synced`.
- **Delete (server-known):** `.synced → .localDeleted` → next sync pushes a tombstone → server soft-deletes → row removed locally.
- **Delete (local-only):** `context.delete(item)` → never reaches the server.
- **Pull:** `runOnce()` calls `SyncAPI.fetchChanges(since:)` → `ChangeApplier.applyItem(_:)` already handles incoming `ItemDTO`.

The `frontImage` / `backImage` strings round-trip as opaque payloads. The iOS app never parses them; it only passes the item's server ID to the cover endpoint.

## Section 6: Testing

**Unit tests:** none net-new required. The model layer and sync layer are already covered by Plan 2's test suite. View-layer code is exercised manually via the checkpoint.

**Manual checkpoint (per the established per-feature rhythm):** one big checkpoint after the full tab is wired, mirroring Plan 3b's structure. Owner ⌘Rs the sim and walks:

1. Items tab shows server-side items, sorted newest first.
2. Category chip narrows to All / Consoles / Accessories correctly.
3. Search filters live by title or platform.
4. Grid / list toggle works.
5. `+` opens Add sheet; fill in title + category, save → row appears, badge clears after sync.
6. Tap row → detail view pushes; metadata renders correctly.
7. Edit button → sheet pre-filled; save → row updates; badge clears.
8. Front/back cover swap on the detail view if both images are present.
9. Swipe-to-delete; pull-to-refresh leaves it gone.
10. **Image upload — Add path:** `+` → Add sheet → tap "Add a photo" → action sheet shows Take Photo / Choose from Library. Pick library, choose an image, preview shows in the form. Save. New row appears with the uploaded image as its thumbnail; ~6s later the `new` badge clears (synced).
11. **Image upload — Edit replace path:** Open an existing item with a broken / dangling image (placeholder shown). Edit → "Change". Library or camera. Save. List thumbnail + detail full-size update to the new image. Web app shows the same image after refresh.
12. **Image upload — Camera path:** `+` → "Take Photo" → camera opens (sim falls back to a "Cancel" if no camera; on a real device this captures). Snap → preview → Save with item. Image appears.
13. **Image upload — Remove path:** Open item with image. Edit → "Remove". Save. Preview reverts to placeholder; list thumbnail does same after re-render.
14. Web app shows same changes round-tripping in both directions.
15. Library / Completions tabs still behave; Stats placeholder still says "Coming soon"; Settings still has working sign-out.

If the checkpoint surfaces missing scope (Plan 3b precedent: dateStarted), it gets folded in via additional commits on the same branch and noted in the PR body.

## Section 7: Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| Server endpoint change breaks existing game cover requests | High | `type` param defaults to `game`; iOS keeps sending today's URL shape; only the item-specific path is new. |
| `CoverImage` generalization introduces a one-line bug at a call site | Medium | Keep existing inits intact as convenience wrappers; no rename. All current call sites unchanged. |
| Image cache filename collision between games and items with the same server ID | Medium | New filename format namespaced by kind: `item_<id>_<face>_<size>.jpg` vs `cover_<id>_<face>_<size>.jpg` (preserve the existing game format for back-compat). |
| Items with no images render an ugly empty box | Low | `CoverImage` already shows a `photo` placeholder when `localURL` is nil — same behaviour Items inherits. |
| Items tab grows the sheet-presentation surface | Low | No nested sheets in this plan (Plan 3b learning). Add/Edit sheets stand alone; no game-picker analog needed. |
| Missing privacy strings → crash on first camera/library access | High | Add `NSCameraUsageDescription` + `NSPhotoLibraryUsageDescription` to the GameTracker target's Info.plist (or build settings) as an explicit early task. |
| Data URI payload too large → sync push timeout / 413 | Medium | Compress to 1024px max longer edge + JPEG quality 0.7 = ~150–400KB. Worst-case 50-item batch ~10MB; default PHP `post_max_size` is 8MB so document the per-batch limit and rely on `pingAfterMutation` (small batches). |
| Cached thumb shows old image after local upload | Medium | After save, `imagesAPI.invalidateItemCover(itemServerId:)` deletes the cache files at `item_<id>_<face>_<size>.jpg` for both thumb and full sizes. Next render re-fetches. |
| Web-side edit during iOS session shows stale cached image | Low | Documented out of scope. Single-user app; user can delete + reinstall app if they care. |

## Section 8: Plan-3c-prep work (none expected)

The merged Plan 3b leaves main in a state where this plan can branch off cleanly:

- Sync layer covers `Item` and `ItemImage` already.
- `SyncTrigger`, `ConflictBannerView`, `SyncStatusBannerView`, `SyncStateBadge` all reusable as-is.
- `Game` and `GameCompletion` flows continue to work; their UI is untouched.

The only "non-trivial" work outside iOS is the ~10-line `cover.php` extension, included in this plan's scope as a single early task.

---

## Section 9: Front-cover image upload (folded in during checkpoint)

### Why this is in scope now

The original plan deferred image upload to Plan 3d. During the user checkpoint it became clear that:

- Some `front_image` rows on the live database point at filenames that are missing from disk (dangling references). Re-photographing from iOS is the cleanest way to overwrite them.
- Capturing the collection from phone is the user's stated workflow.
- The upload path is **simpler than expected** because `cover.php` already handles inline data URIs server-side — no new endpoint, no schema change, no multipart upload helper, no thumbnail generation. iOS just writes a data-URI string into `item.frontImage` and the existing sync layer carries it.

### Storage approach

A captured photo becomes a single `String` of the form `data:image/jpeg;base64,...` written to `item.frontImage`. The sync `PushBuilder.itemToModifiedRow` already sends this column opaquely; the server's `items.front_image` column (MEDIUMTEXT) accepts the full string; `cover.php`'s data-URI dispatch (line 49-66) decodes and streams it back as image bytes. Round-trip works with zero new server code.

### Capture pipeline (background queue)

1. User taps the image section in the form → action sheet with two choices.
2. **"Take Photo"** → `UIImagePickerController` (camera source) presented via a SwiftUI `UIViewControllerRepresentable` wrapper. Returns a `UIImage`.
3. **"Choose from Library"** → SwiftUI `PhotosPicker` (iOS 16+). Returns `PhotosPickerItem` → `Data` → `UIImage`.
4. Downscale to **max 1024px on the longer edge** via `UIGraphicsImageRenderer`.
5. JPEG-encode at **quality 0.7** → `Data`.
6. Base64-encode and prepend `data:image/jpeg;base64,` → `String`.
7. Assign to a `@State pendingNewImage: UIImage?` in the parent (Add/Edit) view and a parallel `@State pendingNewImageDataURI: String?`. The UIImage is for in-form preview; the data URI is what saves into the model.

### Form integration

`ItemFormBody` gains a new `Section("Image")` above `Section("Title & category")`:

```
┌────────────────────────────────┐
│ ┌────┐                         │
│ │IMG │ [Change]  [Remove]      │   ← when an image is present
│ └────┘                         │
└────────────────────────────────┘

         OR

┌────────────────────────────────┐
│  [+ Add a photo]               │   ← when no image
└────────────────────────────────┘
```

Preview source:
- If `pendingNewImage != nil` → render the freshly-picked `UIImage`.
- Else if `existingFrontImage` (the original `item.frontImage` string) is non-nil → render via `CoverImage(itemServerId: item.serverId, …)` (works for data URIs, HTTPS URLs, and bare filenames; for `AddItemView` the item has no `serverId` yet so this branch is skipped).
- Else → placeholder tap-zone.

### Save semantics

- **AddItemView:** if `pendingNewImageDataURI` is set, assign it to `newItem.frontImage` before `context.insert`. Sync push will deliver the data URI on the next round.
- **EditItemView:**
  - If `pendingNewImageDataURI` is set → overwrite `item.frontImage` with it; transition `.synced → .localModified`.
  - If the user tapped "Remove" → set `item.frontImage = nil`; transition `.synced → .localModified`.
  - Otherwise → leave `frontImage` untouched (preserves legacy bare-filename / HTTPS values).
  - After save, call `imagesAPI.invalidateItemCover(itemServerId: item.serverId)` if `serverId` is non-nil. Purges the cache files at `item_<id>_front_thumb.jpg` and `item_<id>_front_full.jpg` so the next render re-fetches.

### Info.plist additions (mandatory)

Without these, the app crashes the first time the camera or photo library is requested:

| Key | Value |
|---|---|
| `NSCameraUsageDescription` | "GameTracker uses the camera to photograph your consoles and accessories." |
| `NSPhotoLibraryUsageDescription` | "GameTracker uses your photo library to pick existing photos of your collection." |

Plan-writing decision: Info.plist edits land as one of the first tasks of the upload sub-plan so the runtime crash risk surfaces immediately, not after all the UI work.

### Files this section touches

New:
- `Views/Items/ItemImagePicker.swift` — single file containing the `UIImagePickerController` Representable wrapper, the SwiftUI action-sheet + preview view, and the static image-processing helper (`downscale` + `dataURI(from:)`). Roughly ~150 lines.

Modified:
- `Views/Items/ItemFormBody.swift` — add the new "Image" section + bindings for `pendingNewImage`, `pendingNewImageDataURI`, `existingFrontImage`, `removeRequested`.
- `Views/Items/AddItemView.swift` — add the @State, pass the bindings, persist data URI on save.
- `Views/Items/EditItemView.swift` — add the @State, pass the bindings, load `existingFrontImage` from item, persist + invalidate cache on save.
- `Networking/ImagesAPI.swift` — add `invalidateItemCover(itemServerId:)` helper.
- `GameTracker.xcodeproj` build settings — add the two Info.plist privacy strings via the target's "Info" tab (these are stored in the pbxproj as `INFOPLIST_KEY_NSCameraUsageDescription` and `INFOPLIST_KEY_NSPhotoLibraryUsageDescription` for synthesized-Info.plist projects).

### Explicit out of scope

- Back-cover upload from iOS.
- Photo upload for games (covers + extras).
- Multi-image gallery (`item_images` extras table).
- In-app image editing (crop, rotate, brightness).
- Quick-replace from `ItemDetailView` (replacement happens via Edit only).

---

## Open questions resolved during plan writing

1. **Category filter placement** → inline segmented `Picker` above the search field. 3 options is small enough that a sheet (LibraryView's choice for 30+ platforms) is overkill.
2. **Price on the list row** → detail-only. Row stays scannable.
3. **Grid/list persistence** → per-session `@State`. Matches LibraryView exactly.
4. **Sort menu** → none in v1. Hardcode title A→Z (matches LibraryView's default). Sort menu can be added in a follow-up plan if needed.
5. **View-mode toggle** → toolbar `ellipsis.circle` `Menu`, matching LibraryView's existing pattern.
