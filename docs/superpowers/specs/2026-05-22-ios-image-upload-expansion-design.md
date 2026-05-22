# iOS Image Upload Expansion — Design (Plan 3e)

**Date:** 2026-05-22
**Status:** Design approved, awaiting implementation plan
**Author:** Cameron (with Claude)
**Predecessors:** [2026-05-15-ios-app-design.md](2026-05-15-ios-app-design.md), Plans 3a/3b/3c/3d. Plan 3c shipped the first cover-upload flow (front cover for items via camera + photo library + data URI sync push). This plan extends that pattern to games and to back covers.

## Overview

Three image-upload gaps remain after Plan 3c:

1. **Games can't be photographed from iOS.** `AddGameView` has a "Paste image URL" text field that has been intentionally inert since Plan 3a; `EditGameView` has no image UI at all.
2. **Back covers can't be uploaded from iOS.** Plan 3c only wired front covers for items.
3. **URL paste during Add doesn't work** because the existing server flow (`proxiesAPI.externalImage`) requires the game's `serverId`, which doesn't exist before the first sync.

Plan 3e closes all three gaps in one pass by generalizing Plan 3c's `ItemImagePicker` into a `CoverImagePicker` that handles either games or items, either face, and adds a third capture source — **URL paste with client-side fetch** — so URL input works on the Add screens too. Items pick up URL paste as a side benefit (the picker is generic).

## Goals

- Photograph or paste-URL front + back covers for every game in the library, from iOS.
- Add back-cover support to items (front is already done in Plan 3c).
- Make URL paste work during Add as well as during Edit, for both games and items, both faces.
- Keep the storage path symmetric across games and items: data URI in the model's image column, no new server endpoints.
- Use the time on this plan to delete one piece of dead UI (Plan 3a's inert `coverURL` field on `AddGameView`).

## Non-goals (out of scope for Plan 3e)

- **Multi-image extras gallery** (`game_images` / `item_images` tables) — separate feature.
- **In-app image editing** (crop, rotate, brightness).
- **Image upload for completions** — `GameCompletion` has no image column.
- **Cache invalidation when the web app edits an image** between iOS syncs (single-user app for now).
- **Unifying the existing `GameDetailView` URL-paste sheet** with the new picker. It uses the server-side `proxiesAPI.externalImage` flow; the new picker does its own client-side fetch. Both produce valid `front_cover_image` strings; keeping both is simpler than risking a regression on the working sheet.
- **Replacing the existing `cover-upload.php` multipart endpoint** for games. iOS deliberately doesn't use it (data URI is symmetric with items and avoids the dangling-reference risk); web flows continue to use it.

## Key Decisions (from brainstorming Q&A)

| Decision | Choice |
|---|---|
| Storage approach for game uploads | **Data URI**, same as items (Plan 3c) — symmetric, no new server endpoints |
| Generalization | **Refactor `ItemImagePicker` → `CoverImagePicker`** in `Views/Common/` so both games and items share the implementation |
| Picker action sheet | **Take Photo / Choose from Library / Paste URL / Cancel** |
| URL paste mechanism | **Client-side `URLSession` fetch**, then through the same downscale + JPEG + base64 pipeline as camera/library — works even before the game has a `serverId` |
| URL fetch limits | 30s timeout, 10MB max download, validate result decodes to a non-nil `UIImage` |
| Existing `GameDetailView` URL-paste sheet | **Untouched** — leave the working flow alone |
| `AddGameView` inert URL field | **Removed** — replaced by the picker's URL-paste option |
| Back-cover support locations | Items: AddItemView, EditItemView. Games: AddGameView, EditGameView. (`ItemDetailView` already supports tap-to-flip; `GameDetailView` gains the same gesture.) |
| Cache invalidation | Reuse Plan 3c's `invalidateItemCover(itemServerId:)` pattern; add a parallel `invalidateGameCover(gameServerId:)` |

---

## Section 1: High-level shape

```
CoverImagePicker.swift  (in Views/Common/)
├── enum CoverKind { game, item }
├── struct CoverImageProcessor (static helpers — was ItemImageProcessor)
│   ├── dataURI(from:maxDim:quality:)
│   └── (private) downscale + JPEG encode
├── struct CameraPickerView (UIViewControllerRepresentable — unchanged from Plan 3c)
└── struct CoverImagePickerSection
    ├── action sheet: Take Photo / Choose from Library / Paste URL
    ├── camera flow (CameraPickerView)
    ├── library flow (PhotosPicker)
    ├── URL-paste flow (URL input + URLSession fetch + UIImage decode)
    └── preview (pendingNewImage ?? CoverImage(kind+serverId+face) ?? placeholder)
```

The picker is parametric over `kind: CoverKind` and `face: ImagesAPI.Face`. Each form embeds **two** instances per item/game — one for front, one for back. The two instances are independent (separate `pendingNewImage` and `existingImage` bindings) so the user can update either cover without touching the other.

## Section 2: File structure

### New iOS files

```
Views/Common/CoverImagePicker.swift     — generalized from Views/Items/ItemImagePicker.swift
```

### Deleted iOS files

```
Views/Items/ItemImagePicker.swift       — replaced by Views/Common/CoverImagePicker.swift
```

### Modified iOS files

| File | Change |
|---|---|
| `Views/Items/ItemFormBody.swift` | Add back-cover bindings + a second `CoverImagePickerSection` for `.back`. Update front section to use the new generic init. |
| `Views/Items/AddItemView.swift` | Add `@State pendingNewBackImage`, `@State existingBackImage`. Pass to FormBody. Save writes both faces. |
| `Views/Items/EditItemView.swift` | Same as Add + load `existingBackImage = i.backImage` in `loadOnce`. Save writes both faces. |
| `Views/Detail/AddGameView.swift` | **Remove** the inert `coverURL` text field + its Section. Add two `CoverImagePickerSection`s (front + back) with bindings for pending images. Save encodes data URIs and writes to `game.frontCoverImage` / `game.backCoverImage`. |
| `Views/Detail/EditGameView.swift` | Add two `CoverImagePickerSection`s at the top. Bindings for pending + existing images. Save writes both. |
| `Views/Detail/GameDetailView.swift` | Add `tap-to-flip` on the cover (mirror `ItemDetailView.cover`). Existing URL-paste sheet untouched. |
| `Networking/ImagesAPI.swift` | Add `invalidateGameCover(gameServerId:)` — parallel to existing `invalidateItemCover`. |

### Untouched

- Server (`api/`) — every endpoint stays as-is.
- Sync layer.
- Models — `Game.frontCoverImage`, `Game.backCoverImage`, `Item.frontImage`, `Item.backImage` already exist as `String?` columns.
- `LibraryView`, `ItemsView`, `CompletionsView`, `StatsView`, `SettingsView`.
- `Money.swift`.

---

## Section 3: `CoverImagePicker` component spec

### 3.1 `CoverKind`

```swift
enum CoverKind {
    case game
    case item
}
```

Used by `CoverImagePickerSection` to dispatch to the right `CoverImage` init (which in turn picks the right `ImagesAPI.downloadCover` overload).

### 3.2 `CoverImageProcessor` (renamed from `ItemImageProcessor`)

API unchanged from Plan 3c. Static methods:

```swift
enum CoverImageProcessor {
    static func dataURI(from image: UIImage,
                        maxDimension: CGFloat = 1024,
                        jpegQuality: CGFloat = 0.7) -> String?

    // private downscale helper stays as-is
}
```

### 3.3 `CameraPickerView`

Unchanged from Plan 3c. `UIViewControllerRepresentable` wrapping `UIImagePickerController` with `.camera` source type. No generic parameters — produces a `UIImage`.

### 3.4 `CoverImagePickerSection`

New shape:

```swift
struct CoverImagePickerSection: View {
    @Binding var pendingNewImage: UIImage?
    @Binding var existingImageString: String?
    let kind: CoverKind
    let serverId: Int?
    let face: ImagesAPI.Face
    let imagesAPI: ImagesAPI
    let sectionTitle: String      // e.g. "Front cover" or "Back cover"

    // local state
    @State private var showSourceSheet
    @State private var showCamera
    @State private var showLibrary
    @State private var libraryPickerItem: PhotosPickerItem?
    @State private var showURLDialog
    @State private var urlInput: String
    @State private var urlFetchInFlight: Bool
    @State private var urlFetchError: String?
}
```

**Section header:** uses the `sectionTitle` parameter so the form can label each face clearly.

**Action sheet items (confirmationDialog):**
- "Take Photo" → toggles `showCamera`
- "Choose from Library" → toggles `showLibrary` (PhotosPicker presents itself via `.photosPicker(isPresented:)`)
- "Paste URL" → toggles `showURLDialog`
- "Cancel" → role: cancel

**Preview rules (unchanged structure from Plan 3c):**
- If `pendingNewImage != nil`, render `Image(uiImage:)`.
- Else if `serverId != nil && existingImageString != nil`, render `CoverImage(...)`. Pass `kind`-appropriate init: `CoverImage(gameServerId: serverId, face: face, ...)` or `CoverImage(itemServerId: serverId, face: face, ...)`.
- Else, "Add a photo" placeholder button (taps to open the action sheet).

**Change / Remove buttons** (when an image is shown): same as Plan 3c.

### 3.5 URL-paste flow

The "Paste URL" path opens an inline `.sheet` (or `.alert` with text field — `.sheet` is more flexible and matches Plan 3c's pattern for new modal flows):

```
┌──────────────────────────────────────┐
│ Add image from URL            Cancel │
├──────────────────────────────────────┤
│ [https://...                       ] │
│                                      │
│ [  Fetch  ]                          │
│                                      │
│ (Error message renders here)         │
└──────────────────────────────────────┘
```

On Fetch:
1. Validate the URL parses as `https://...` (reject `http://`).
2. Fire `URLSession.shared.data(from: url)` with a 30s timeout.
3. Validate response: HTTP 200, content-type starts with `image/`, body ≤ 10MB.
4. Decode bytes to `UIImage`. If nil, show "Image couldn't be decoded".
5. On success: assign to `pendingNewImage`, dismiss the URL dialog. From there, the parent save path handles encoding to data URI exactly the same as camera/library.

Errors surface as a `Text(.red)` inside the dialog. The user can correct the URL and retry without dismissing.

---

## Section 4: Form integration

### 4.1 Items — `ItemFormBody.swift`

Replace the single `ItemImagePickerSection` (Plan 3c) with two `CoverImagePickerSection`s:

```swift
CoverImagePickerSection(pendingNewImage: $pendingNewFrontImage,
                        existingImageString: $existingFrontImage,
                        kind: .item,
                        serverId: itemServerId,
                        face: .front,
                        imagesAPI: imagesAPI,
                        sectionTitle: "Front cover")

CoverImagePickerSection(pendingNewImage: $pendingNewBackImage,
                        existingImageString: $existingBackImage,
                        kind: .item,
                        serverId: itemServerId,
                        face: .back,
                        imagesAPI: imagesAPI,
                        sectionTitle: "Back cover")
```

`ItemFormBody`'s parameter list grows by 4 bindings (2 per face).

### 4.2 Items — `AddItemView.swift`

```swift
@State private var pendingNewFrontImage: UIImage? = nil
@State private var pendingNewBackImage: UIImage? = nil
@State private var existingFrontImage: String? = nil   // always nil on Add
@State private var existingBackImage: String? = nil    // always nil on Add
```

`save()` adds:

```swift
if let img = pendingNewFrontImage,
   let uri = CoverImageProcessor.dataURI(from: img) {
    item.frontImage = uri
}
if let img = pendingNewBackImage,
   let uri = CoverImageProcessor.dataURI(from: img) {
    item.backImage = uri
}
```

### 4.3 Items — `EditItemView.swift`

Mirrors AddItemView. `loadOnce` adds:

```swift
existingFrontImage = i.frontImage
existingBackImage  = i.backImage
```

`save()` writes both faces (encoded from pending, or leaves as-is, or nils if removed — same logic as Plan 3c's front-only).

Cache invalidation runs for both faces; existing `invalidateItemCover(itemServerId:)` already handles both.

### 4.4 Games — `AddGameView.swift`

**Removes** the existing Cover-image section that contains the inert `coverURL` text field:

```swift
Section("Cover image") {
    TextField("Paste image URL (https://…)", text: $coverURL)
    ...
    Text("Server downloads + saves it after the game is created.")
}
```

**Adds** two `CoverImagePickerSection`s in its place:

```swift
CoverImagePickerSection(pendingNewImage: $pendingNewFrontImage,
                        existingImageString: $existingFrontImage,
                        kind: .game,
                        serverId: nil,  // Add: no serverId yet
                        face: .front,
                        imagesAPI: imagesAPI,
                        sectionTitle: "Front cover")

CoverImagePickerSection(...face: .back, sectionTitle: "Back cover", ...)
```

Removes the `@State coverURL` property (and the comment block referencing the "intentionally inert" placeholder). Adds:

```swift
@State private var pendingNewFrontImage: UIImage? = nil
@State private var pendingNewBackImage: UIImage? = nil
@State private var existingFrontImage: String? = nil
@State private var existingBackImage: String? = nil
```

`save()` adds (mirroring AddItemView):

```swift
if let img = pendingNewFrontImage,
   let uri = CoverImageProcessor.dataURI(from: img) {
    game.frontCoverImage = uri
}
if let img = pendingNewBackImage,
   let uri = CoverImageProcessor.dataURI(from: img) {
    game.backCoverImage = uri
}
```

### 4.5 Games — `EditGameView.swift`

Adds two `CoverImagePickerSection`s at the top of the Form (currently has no image UI). Bindings mirror AddGameView. `loadOnce` populates `existingFrontImage = game.frontCoverImage`, `existingBackImage = game.backCoverImage`. Save writes both. Cache invalidation calls the new `invalidateGameCover(gameServerId: game.serverId)` for synced rows.

### 4.6 Games — `GameDetailView.swift`

Add `tap-to-flip` on the cover image, mirroring `ItemDetailView.cover`:

```swift
@State private var showingBack = false

// Inside the cover view:
.onTapGesture { showingBack.toggle() }

// face: showingBack ? .back : .front
```

The existing "Set cover from URL" sheet stays exactly as-is — it's a separate workflow that posts to `proxiesAPI.externalImage`.

### 4.7 `ImagesAPI.swift` — add `invalidateGameCover`

Parallel to the existing `invalidateItemCover`:

```swift
func invalidateGameCover(gameServerId: Int) {
    for face in [Face.front, Face.back] {
        for size in [Size.thumb, Size.full] {
            let filename = "cover_\(gameServerId)_\(face.rawValue)_\(size.rawValue).jpg"
            let dest = cacheRoot.appendingPathComponent(filename)
            try? FileManager.default.removeItem(at: dest)
        }
    }
}
```

Cache filename uses the existing `cover_<id>_…` prefix that game covers already use, matching `downloadCover(gameServerId:…)`'s filename format.

---

## Section 5: Save semantics (per form)

For each face, on save:

| Form state | Action on the model column |
|---|---|
| `pendingNewImage != nil` | Encode + write data URI |
| `pendingNewImage == nil` AND `existingImageString == nil` AND the model has a prior value | User tapped Remove — write `nil` |
| `pendingNewImage == nil` AND `existingImageString != nil` | Leave the existing string in place (data URI / HTTPS URL / bare filename — all preserved) |

If the row is `.synced` and any change was made, transition to `.localModified`. Call the appropriate `invalidate*Cover` after `context.save()` for synced rows.

---

## Section 6: Testing

**Unit tests** — none net-new required.

**Manual checkpoint** — one walkthrough covering both items-back and games-everything. Each step listed independently so failures can be reported precisely:

1. **Items front (regression):** Add → tap front cover's "Add a photo" → library → pick image → preview shows → Save. New row appears with the image. (Unchanged from Plan 3c; ensures the refactor didn't break.)
2. **Items back (new):** Edit an item → tap back cover's "Add a photo" → library → pick → Save. Item's row thumbnail unchanged; open detail → tap the front cover — flips to the new back cover.
3. **Items URL paste:** Edit an item → tap "Add a photo" on front cover → Paste URL → input a known-good image URL (e.g. wikimedia) → Fetch → preview shows → Save. Item displays the fetched image.
4. **Games front + back from picker:** Add a new game → front cover → Take Photo (or library) → preview → back cover → Take Photo → preview → Save. Game appears in Library with the front cover; open detail → tap to flip → back cover shown.
5. **Games URL paste during Add:** Add a new game → front cover → Paste URL → fetch → preview → Save. Game appears with the URL-sourced cover.
6. **Games URL paste during Edit:** Same flow on Edit. Works identically.
7. **Games tap-to-flip on detail:** Open any game with both covers set → tap → back. Tap again → front.
8. **Existing GameDetailView URL sheet (regression):** Open a game with no cover → tap the cover → existing URL-paste sheet opens → paste URL → save. Game's front_cover_image is set server-side; iOS picks it up on next sync.
9. **Removed inert AddGameView URL field:** No "Paste image URL" text field appears on the Add game sheet. The Cover image section shows the two new pickers.
10. **No regression on Library / Items / Completions / Stats / Settings.**

---

## Section 7: Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| `CoverImagePicker` rename breaks Plan 3c call sites | Medium | Refactor in one pass; every `ItemImagePickerSection(...)` call in `ItemFormBody.swift` becomes `CoverImagePickerSection(...)`. Compile-time errors flag any missed call site. |
| URL fetch returns non-image bytes (HTML error page, redirect chain) | Medium | Validate content-type prefix `image/` and successfully-decoded `UIImage(data:)`. Display a clear error and let the user retry. |
| Huge URL download stalls the UI | Low | 30s timeout + 10MB size cap; fetch runs on a background queue via `URLSession.data(from:)` which is awaitable. |
| Cache invalidation forgets a face | Low | `invalidateGameCover` iterates both faces × both sizes. Mirror `invalidateItemCover`. |
| User adds an https URL that the server can't later download (CORS / auth) | Low | Doesn't matter — iOS does the fetch client-side, encodes the result as a data URI, and stores the inline bytes. The server never re-fetches the URL. |
| Removing the inert `coverURL` field from AddGameView confuses a user who relied on it | Negligible | The field never worked (Plan 3a marked it `intentionally inert`). Removing dead UI is an improvement. |
| Tap-to-flip on GameDetailView conflicts with the existing "tap cover → URL paste sheet" gesture | Medium | Need to read GameDetailView's existing tap handler in the plan-writing phase and resolve the conflict (e.g., long-press for URL paste, short-tap for flip; or move URL-paste to a button). Plan-time decision, not spec-time. |

---

## Open questions resolved during plan writing

1. **Naming of the URL fetch helper** — inline private function in `CoverImagePickerSection` is fine for v1; if it ever needs reuse, extract.
2. **Tap-to-flip vs existing URL-paste gesture on `GameDetailView`** — needs investigation during plan writing. The existing detail view's cover tap opens the URL sheet; we'll move that gesture to a button or long-press and use short-tap for flip. Documented as a plan-writing-time decision in Section 7.
3. **Pillbox toggles for face selection (single picker that swaps face) vs two side-by-side picker sections** — went with two sections because forms read naturally top-to-bottom and back-cover is a distinct concept worth its own header.
