# iOS Image Upload Expansion Implementation Plan (Plan 3e)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend Plan 3c's image-upload flow so the user can photograph, library-pick, or URL-paste **front + back covers** for both **games and items**, from both **Add and Edit screens**, with the new picker working even before the first sync.

**Architecture:** Refactor Plan 3c's `ItemImagePicker` into a generic `CoverImagePicker` (parameterised by kind + face) under `Views/Common/`. Embed two instances per form (front + back). Add a third capture source — URL paste with client-side `URLSession` fetch — so the same pipeline (downscale → JPEG → base64 data URI → model column → sync push) works during Add, before any `serverId` exists. `GameDetailView` gains tap-to-flip on the cover; its existing `PhotosPicker` (multipart to `cover-upload.php`) and "Set cover from URL…" sheet stay untouched.

**Tech Stack:** Swift 5.10+, SwiftUI, PhotosUI (`PhotosPicker`), `UIImagePickerController` via `UIViewControllerRepresentable`, `URLSession`, SwiftData. No new server endpoints; no server changes; data URI dispatch in `cover.php` (extended in Plan 3c) already handles the inline bytes.

**Predecessors:** Plans 3a (Library + game flows including the existing GameDetailView photo upload), 3b (Completions), 3c (Items front-cover upload — the picker generalised here), 3d (Stats + currency utility). Spec: [`docs/superpowers/specs/2026-05-22-ios-image-upload-expansion-design.md`](../specs/2026-05-22-ios-image-upload-expansion-design.md).

**Execution rhythm:** Per-feature checkpoint pattern (memory: `feedback_per_feature_checkpoints`). One visible commit at the end of Task 9 (full feature wired), then one user checkpoint covering games-front, games-back, items-back, and URL-paste across both. The existing GameDetailView upload + URL-paste flows get a regression check inside the same checkpoint.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-3e-image-upload-expansion`, branched off `main` (Plan 3d merged at `37987ff`).
- **Pre-existing changes to leave alone in every commit:**
  - `js/completions.js` — old uncommitted whitespace edit.
  - `scripts/generate-thumbnails 2.php` + `tests/v2/*2.sh` — iCloud Drive conflict copies.
- **iCloud Drive Swift conflict files:** clear before each test pass:

  ```bash
  find ios/GameTracker -name "* [0-9].swift" -print -delete
  ```

---

## What this plan does NOT build (Plan 3f+ territory)

- **Multi-image extras gallery** (`game_images` / `item_images` tables).
- **In-app image editing** (crop, rotate, brightness).
- **Image upload for completions.**
- **Cache invalidation when an item / game is edited on the web app between syncs.**
- **Unifying the existing GameDetailView upload paths with the new picker** — leave both as-is.
- **Replacing or removing the existing `proxiesAPI.uploadCover` multipart endpoint** — iOS still uses it via `GameDetailView`'s PhotosPicker; web uses it too.
- **Bumping `ItemImageProcessor` / new `CoverImageProcessor` from 1024px to 2048px** to match the existing GameDetailView helper. Two different downscale sizes coexist; consolidation can wait.

---

## File structure

### New iOS files

```
Views/Common/CoverImagePicker.swift     — generalized from Views/Items/ItemImagePicker.swift;
                                          parameterised by kind (game/item) + face (front/back);
                                          action sheet now includes Paste URL
```

### Deleted iOS files

```
Views/Items/ItemImagePicker.swift       — replaced by Views/Common/CoverImagePicker.swift
```

### Modified iOS files

| File | Change |
|---|---|
| `Views/Items/ItemFormBody.swift` | New bindings for back image; renders **two** `CoverImagePickerSection`s (front, back). |
| `Views/Items/AddItemView.swift` | Add `@State pendingNewBackImage` + `existingBackImage`. Pass to FormBody. Save encodes both faces. |
| `Views/Items/EditItemView.swift` | Same as Add, plus loads `existingBackImage = item.backImage` on `loadOnce`. Save writes both faces. |
| `Views/Detail/AddGameView.swift` | Remove the inert `coverURL` text field and its Section. Add `CoverImagePickerSection`s for front + back. Save writes data URIs to `game.frontCoverImage` / `game.backCoverImage`. |
| `Views/Detail/EditGameView.swift` | Add `CoverImagePickerSection`s for front + back at the top of the form. Bindings + loadOnce + save. |
| `Views/Detail/GameDetailView.swift` | Add `tap-to-flip` on the cover image. Existing PhotosPicker button + "Set cover from URL…" sheet untouched. |
| `Networking/ImagesAPI.swift` | Add `invalidateGameCover(gameServerId:)` parallel to existing `invalidateItemCover`. |

### Untouched

- Server (`api/`).
- Sync layer.
- Models — every column already exists.
- `LibraryView`, `ItemsView`, `CompletionsView`, `StatsView`, `SettingsView`.
- `ItemDetailView` — tap-to-flip already there; back-cover preview comes for free once `backImage` is set.
- `ProxiesAPI.uploadCover` — still used by `GameDetailView`'s existing PhotosPicker.
- `Money.swift`, `CoverImage.swift` (the view), `ImagesAPI.downloadCover` overloads.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-22-ios-image-upload-expansion.md` (this file)

- [x] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current        # → plan-3e-image-upload-expansion
git log --oneline -3              # spec on top of 3d merge
git status --short                # only pre-existing junk
```

Expected: branch is `plan-3e-image-upload-expansion`; spec commit (`cb3edda`) sits on top of the 3d merge (`37987ff`).

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
git add docs/superpowers/plans/2026-05-22-ios-image-upload-expansion.md
git commit -m "Add Plan 3e (iOS image upload expansion) implementation plan"
```

---

## Task 1: Refactor — `ItemImagePicker.swift` → `Views/Common/CoverImagePicker.swift`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Common/CoverImagePicker.swift`
- Delete: `ios/GameTracker/GameTracker/Views/Items/ItemImagePicker.swift`

The new file generalises the old one with three additions: a `CoverKind` enum (game/item), a `face` parameter, and a third action-sheet source (Paste URL) backed by a client-side `URLSession` fetch.

- [x] **Step 1.1: Write the new `CoverImagePicker.swift`**

Write the file at `ios/GameTracker/GameTracker/Views/Common/CoverImagePicker.swift`:

```swift
import SwiftUI
import PhotosUI
import UIKit

// MARK: - CoverKind

/// Discriminator passed to `CoverImagePickerSection` so the preview
/// renders via the right `CoverImage` init.
enum CoverKind {
    case game
    case item
}

// MARK: - UIImagePickerController wrapper (camera capture)

/// SwiftUI wrapper around `UIImagePickerController` for live camera
/// capture. PhotosPicker handles the library; this handles the camera.
struct CameraPickerView: UIViewControllerRepresentable {
    let onPick: (UIImage) -> Void

    @Environment(\.dismiss) private var dismiss

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let vc = UIImagePickerController()
        vc.sourceType = .camera
        vc.allowsEditing = false
        vc.delegate = context.coordinator
        return vc
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let parent: CameraPickerView
        init(_ parent: CameraPickerView) { self.parent = parent }

        func imagePickerController(_ picker: UIImagePickerController,
                                   didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if let img = info[.originalImage] as? UIImage {
                parent.onPick(img)
            }
            parent.dismiss()
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            parent.dismiss()
        }
    }
}

// MARK: - Image processing helpers

enum CoverImageProcessor {
    /// Returns a base64 `data:image/jpeg;base64,…` URI suitable for
    /// writing to the model's `frontImage` / `backImage` /
    /// `frontCoverImage` / `backCoverImage` column.
    static func dataURI(from image: UIImage,
                        maxDimension: CGFloat = 1024,
                        jpegQuality: CGFloat = 0.7) -> String? {
        let resized = downscale(image, maxDimension: maxDimension)
        guard let jpeg = resized.jpegData(compressionQuality: jpegQuality) else { return nil }
        let b64 = jpeg.base64EncodedString()
        return "data:image/jpeg;base64,\(b64)"
    }

    private static func downscale(_ image: UIImage, maxDimension: CGFloat) -> UIImage {
        let longest = max(image.size.width, image.size.height)
        guard longest > maxDimension else { return image }
        let scale = maxDimension / longest
        let newSize = CGSize(width: image.size.width * scale,
                             height: image.size.height * scale)
        let renderer = UIGraphicsImageRenderer(size: newSize)
        return renderer.image { _ in
            image.draw(in: CGRect(origin: .zero, size: newSize))
        }
    }
}

// MARK: - URL fetch helper

enum CoverImageURLFetcher {
    enum FetchError: LocalizedError {
        case badURL
        case nonHTTPS
        case badResponse(Int)
        case wrongType(String)
        case tooLarge(Int)
        case decodeFailed

        var errorDescription: String? {
            switch self {
            case .badURL:               return "That doesn't look like a valid URL."
            case .nonHTTPS:             return "URL must start with https://"
            case .badResponse(let c):   return "Server returned HTTP \(c)."
            case .wrongType(let t):     return "Expected an image, got \(t)."
            case .tooLarge(let bytes):  return "Image is too large (\(bytes / 1_000_000) MB). Max 10 MB."
            case .decodeFailed:         return "Couldn't decode the downloaded image."
            }
        }
    }

    /// Fetches `urlString` via URLSession and returns a `UIImage`.
    /// Validation: must be https://, must respond 200 with an
    /// `image/*` content-type, must be ≤ 10 MB, must decode.
    static func fetchImage(from urlString: String) async throws -> UIImage {
        let trimmed = urlString.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let url = URL(string: trimmed), url.scheme != nil else {
            throw FetchError.badURL
        }
        guard url.scheme?.lowercased() == "https" else {
            throw FetchError.nonHTTPS
        }

        var request = URLRequest(url: url)
        request.timeoutInterval = 30

        let (data, response) = try await URLSession.shared.data(for: request)

        if let http = response as? HTTPURLResponse, http.statusCode != 200 {
            throw FetchError.badResponse(http.statusCode)
        }
        let contentType = (response as? HTTPURLResponse)?.value(forHTTPHeaderField: "Content-Type") ?? ""
        if !contentType.lowercased().hasPrefix("image/") {
            throw FetchError.wrongType(contentType)
        }
        if data.count > 10 * 1_000_000 {
            throw FetchError.tooLarge(data.count)
        }
        guard let img = UIImage(data: data) else {
            throw FetchError.decodeFailed
        }
        return img
    }
}

// MARK: - Form section

/// The "Image" section for Item / Game Add and Edit forms. Holds
/// local picker presentation state but writes results back through
/// parent-owned bindings.
struct CoverImagePickerSection: View {
    @Binding var pendingNewImage: UIImage?
    @Binding var existingImageString: String?
    let kind: CoverKind
    let serverId: Int?
    let face: ImagesAPI.Face
    let imagesAPI: ImagesAPI
    let sectionTitle: String

    @State private var showSourceSheet = false
    @State private var showCamera = false
    @State private var showLibrary = false
    @State private var libraryPickerItem: PhotosPickerItem?
    @State private var showURLDialog = false
    @State private var urlInput: String = ""
    @State private var urlFetchInFlight: Bool = false
    @State private var urlFetchError: String?

    private var hasAnyImage: Bool {
        pendingNewImage != nil || (serverId != nil && existingImageString != nil)
    }

    var body: some View {
        Section(sectionTitle) {
            if hasAnyImage {
                preview
            } else {
                Button {
                    showSourceSheet = true
                } label: {
                    Label("Add a photo", systemImage: "camera")
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
        .confirmationDialog("Add an image", isPresented: $showSourceSheet, titleVisibility: .visible) {
            Button("Take Photo") { showCamera = true }
            Button("Choose from Library") { showLibrary = true }
            Button("Paste URL") {
                urlInput = ""
                urlFetchError = nil
                showURLDialog = true
            }
            Button("Cancel", role: .cancel) {}
        }
        .sheet(isPresented: $showCamera) {
            CameraPickerView { img in
                pendingNewImage = img
            }
            .ignoresSafeArea()
        }
        .photosPicker(isPresented: $showLibrary,
                      selection: $libraryPickerItem,
                      matching: .images)
        .onChange(of: libraryPickerItem) { _, newItem in
            guard let newItem else { return }
            Task {
                if let data = try? await newItem.loadTransferable(type: Data.self),
                   let img = UIImage(data: data) {
                    await MainActor.run { pendingNewImage = img }
                }
                await MainActor.run { libraryPickerItem = nil }
            }
        }
        .sheet(isPresented: $showURLDialog) {
            urlDialog
        }
    }

    @ViewBuilder
    private var preview: some View {
        VStack(alignment: .leading, spacing: 8) {
            Group {
                if let img = pendingNewImage {
                    Image(uiImage: img)
                        .resizable()
                        .aspectRatio(contentMode: .fit)
                } else if let id = serverId, existingImageString != nil {
                    switch kind {
                    case .game:
                        CoverImage(gameServerId: id, face: face, size: .thumb, api: imagesAPI)
                    case .item:
                        CoverImage(itemServerId: id, face: face, size: .thumb, api: imagesAPI)
                    }
                } else {
                    Color.gray.opacity(0.2)
                }
            }
            .frame(maxWidth: .infinity)
            .frame(height: 160)
            .clipShape(RoundedRectangle(cornerRadius: 8))

            HStack {
                Button {
                    showSourceSheet = true
                } label: {
                    Label("Change", systemImage: "arrow.triangle.2.circlepath")
                }
                Spacer()
                Button(role: .destructive) {
                    pendingNewImage = nil
                    existingImageString = nil
                } label: {
                    Label("Remove", systemImage: "trash")
                }
            }
            .buttonStyle(.bordered)
            .controlSize(.small)
        }
    }

    @ViewBuilder
    private var urlDialog: some View {
        NavigationStack {
            Form {
                Section("Image URL") {
                    TextField("https://…", text: $urlInput)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                }
                Section {
                    Button {
                        Task { await fetchURL() }
                    } label: {
                        if urlFetchInFlight {
                            HStack { ProgressView(); Text("Fetching…") }
                        } else {
                            Label("Fetch image", systemImage: "arrow.down.circle")
                        }
                    }
                    .disabled(urlInput.isEmpty || urlFetchInFlight)
                }
                if let err = urlFetchError {
                    Section { Text(err).font(.callout).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Add image from URL")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showURLDialog = false }
                }
            }
        }
    }

    private func fetchURL() async {
        urlFetchError = nil
        urlFetchInFlight = true
        defer { urlFetchInFlight = false }
        do {
            let img = try await CoverImageURLFetcher.fetchImage(from: urlInput)
            await MainActor.run {
                pendingNewImage = img
                showURLDialog = false
            }
        } catch {
            await MainActor.run {
                urlFetchError = (error as? CoverImageURLFetcher.FetchError)?.errorDescription
                    ?? error.localizedDescription
            }
        }
    }
}
```

- [x] **Step 1.2: Delete the old `ItemImagePicker.swift`**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
rm ios/GameTracker/GameTracker/Views/Items/ItemImagePicker.swift
```

- [x] **Step 1.3: Build check (expected: errors from existing call sites)**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: **BUILD FAILED** — `ItemImagePickerSection` is no longer found by `ItemFormBody`, and `ItemImageProcessor` is no longer found by `AddItemView` / `EditItemView`. That's fine; the next tasks fix the call sites. (No commit yet — Tasks 1–9 ship as one bundle at the end of Task 9.)

If you see errors *other* than those three lookups, stop and check the new file matches Step 1.1 exactly.

---

## Task 2: Update `ItemFormBody` — front + back sections

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Items/ItemFormBody.swift`

`ItemFormBody`'s parameter list gains 4 new bindings (2 per face). The single existing `ItemImagePickerSection` is replaced with two `CoverImagePickerSection`s.

- [x] **Step 2.1: Replace the file's contents**

```swift
import SwiftUI
import UIKit

/// Shared form fields for Add/Edit item. The owning view supplies
/// every binding; this struct contains no `@State` of its own (apart
/// from picker presentation state inside `CoverImagePickerSection`).
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
    @Binding var pendingNewFrontImage: UIImage?
    @Binding var existingFrontImage: String?
    @Binding var pendingNewBackImage: UIImage?
    @Binding var existingBackImage: String?
    let itemServerId: Int?
    let imagesAPI: ImagesAPI

    var body: some View {
        Group {
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

            Section("Title & category") {
                TextField("Title", text: $title)
                Picker("Category", selection: $category) {
                    ForEach(ItemCategory.allCases) { c in
                        Label(c.displayName, systemImage: c.systemImage).tag(c)
                    }
                }
                .pickerStyle(.menu)
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

- [x] **Step 2.2: Build check (still expected to fail at AddItemView / EditItemView call sites)**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: still **BUILD FAILED** — `AddItemView` and `EditItemView` haven't been updated yet to pass the new bindings. Fixed by Task 3 and Task 4.

---

## Task 3: Update `AddItemView` — back image bindings + save

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Items/AddItemView.swift`

- [x] **Step 3.1: Replace the property block and the `ItemFormBody(...)` call**

Find this property block:

```swift
    @State private var pendingNewImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil   // always nil for Add; passed for symmetry
```

Replace with:

```swift
    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil   // always nil for Add
    @State private var existingBackImage: String? = nil    // always nil for Add
```

Find the `ItemFormBody(...)` call:

```swift
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes,
                             pendingNewImage: $pendingNewImage,
                             existingFrontImage: $existingFrontImage,
                             itemServerId: nil,
                             imagesAPI: imagesAPI)
```

Replace with:

```swift
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes,
                             pendingNewFrontImage: $pendingNewFrontImage,
                             existingFrontImage: $existingFrontImage,
                             pendingNewBackImage: $pendingNewBackImage,
                             existingBackImage: $existingBackImage,
                             itemServerId: nil,
                             imagesAPI: imagesAPI)
```

- [x] **Step 3.2: Update `save()` to encode both faces**

Find the existing save logic that references `pendingNewImage`:

```swift
        if let img = pendingNewImage,
           let dataURI = ItemImageProcessor.dataURI(from: img) {
            item.frontImage = dataURI
        }
```

Replace with:

```swift
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            item.frontImage = dataURI
        }
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            item.backImage = dataURI
        }
```

- [x] **Step 3.3: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: still **BUILD FAILED** — only `EditItemView` left to migrate. Fixed by Task 4.

---

## Task 4: Update `EditItemView` — back image bindings + load + save

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Items/EditItemView.swift`

- [x] **Step 4.1: Replace the property block**

Find:

```swift
    @State private var pendingNewImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil
    @State private var loaded = false
```

Replace with:

```swift
    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil
    @State private var existingBackImage: String? = nil
    @State private var loaded = false
```

- [x] **Step 4.2: Update the `ItemFormBody(...)` call**

Find:

```swift
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes,
                             pendingNewImage: $pendingNewImage,
                             existingFrontImage: $existingFrontImage,
                             itemServerId: currentItemServerId,
                             imagesAPI: imagesAPI)
```

Replace with:

```swift
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes,
                             pendingNewFrontImage: $pendingNewFrontImage,
                             existingFrontImage: $existingFrontImage,
                             pendingNewBackImage: $pendingNewBackImage,
                             existingBackImage: $existingBackImage,
                             itemServerId: currentItemServerId,
                             imagesAPI: imagesAPI)
```

- [x] **Step 4.3: Update `loadOnce()` to load the back image**

Find:

```swift
        existingFrontImage = i.frontImage
        loaded = true
```

Replace with:

```swift
        existingFrontImage = i.frontImage
        existingBackImage  = i.backImage
        loaded = true
```

- [x] **Step 4.4: Update `save()` to encode both faces + handle removal of either**

Find the existing image-handling block in `save()`:

```swift
        // Image upload: if a new photo was picked this session, encode +
        // overwrite the model's frontImage. If the user removed (both
        // bindings nilled), clear it. Otherwise leave the original
        // string untouched (legacy bare-filename / HTTPS values).
        if let img = pendingNewImage,
           let dataURI = ItemImageProcessor.dataURI(from: img) {
            i.frontImage = dataURI
        } else if pendingNewImage == nil && existingFrontImage == nil && i.frontImage != nil {
            i.frontImage = nil
        }
```

Replace with:

```swift
        // Image upload (front): if a new photo was picked this session,
        // encode + overwrite. If both bindings nilled, user tapped
        // Remove → clear it. Otherwise leave the original untouched.
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            i.frontImage = dataURI
        } else if pendingNewFrontImage == nil && existingFrontImage == nil && i.frontImage != nil {
            i.frontImage = nil
        }

        // Image upload (back): same rules.
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            i.backImage = dataURI
        } else if pendingNewBackImage == nil && existingBackImage == nil && i.backImage != nil {
            i.backImage = nil
        }
```

(`invalidateItemCover` already covers both faces and both sizes — no change needed.)

- [x] **Step 4.5: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: **BUILD SUCCEEDED**. Items side is fully migrated; Games side hasn't been touched yet, but Games don't use `CoverImagePickerSection` yet so nothing is broken there.

---

## Task 5: Update `AddGameView` — remove inert URL field, add picker sections

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Detail/AddGameView.swift`

- [x] **Step 5.1: Remove the inert `coverURL` property and Section**

Find this property:

```swift
    @State private var coverURL = ""
```

Delete the line.

Find this Section in the body:

```swift
                Section("Cover image") {
                    TextField("Paste image URL (https://…)", text: $coverURL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                    Text("Server downloads + saves it after the game is created.")
                        .font(.caption).foregroundStyle(.secondary)
                }
```

Delete the entire Section block (including the closing `}`).

- [x] **Step 5.2: Add new image-state properties**

Find the remaining `@State` block (after the `@State private var saveInFlight = false` line). Add at the end of the block:

```swift
    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil   // always nil for Add
    @State private var existingBackImage: String? = nil    // always nil for Add
```

You'll also need to add `import UIKit` at the top of the file if it's not already there (it isn't in Plan 3a's version — add it next to the existing `import SwiftUI` / `import SwiftData`).

- [x] **Step 5.3: Insert two `CoverImagePickerSection`s where the old Section used to be**

Find the location where the old `Section("Cover image")` was — between `Section("Required")` and the metadata-fetch `Section`. Insert:

```swift
                CoverImagePickerSection(pendingNewImage: $pendingNewFrontImage,
                                        existingImageString: $existingFrontImage,
                                        kind: .game,
                                        serverId: nil,
                                        face: .front,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Front cover")

                CoverImagePickerSection(pendingNewImage: $pendingNewBackImage,
                                        existingImageString: $existingBackImage,
                                        kind: .game,
                                        serverId: nil,
                                        face: .back,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Back cover")
```

- [x] **Step 5.4: Update `save()` to encode both faces into the new game's columns**

Find this block in `save()` (after `context.insert(game)` and before `syncTrigger.pingAfterMutation()`):

```swift
        // 2. Trigger immediate-ish sync so we get the server_id back ASAP.
        // (Cover-URL flow on the detail screen handles the actual upload;
        // see Task 9. The URL field on this form is intentionally inert in 3a.)
        syncTrigger.pingAfterMutation()
```

Replace with:

```swift
        // 2. Encode any picked images as data URIs into the new game's
        // image columns. The sync push delivers them with the new row.
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            game.frontCoverImage = dataURI
        }
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            game.backCoverImage = dataURI
        }
        try? context.save()

        // 3. Trigger immediate-ish sync so we get the server_id back ASAP.
        syncTrigger.pingAfterMutation()
```

(The earlier `try context.save()` already inserted the row; this second save commits the data-URI fields onto the same row before sync picks it up.)

- [x] **Step 5.5: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: **BUILD SUCCEEDED**.

---

## Task 6: Update `EditGameView` — add picker sections, load + save

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Detail/EditGameView.swift`

`EditGameView` currently has no image UI. We add two `CoverImagePickerSection`s at the top of the form, plus loading + saving logic.

- [x] **Step 6.1: Add `import UIKit` + an `imagesAPI` parameter**

Find the top of the file:

```swift
import SwiftUI
import SwiftData

struct EditGameView: View {
```

Replace with:

```swift
import SwiftUI
import SwiftData
import UIKit

struct EditGameView: View {
```

Find the struct's existing `let` properties block (the parameters near the top — should include things like `gameID`, `proxiesAPI`, `syncTrigger`). Add `imagesAPI` next to the others. The exact existing block depends on Plan 3a's wiring — read the file, find where `proxiesAPI` or `syncTrigger` is declared, and add `let imagesAPI: ImagesAPI` adjacent.

Then update every call site of `EditGameView(...)` to pass `imagesAPI`. There's exactly one — in `GameDetailView.swift`. Find:

```swift
                .sheet(isPresented: $showEdit) {
                    EditGameView(gameID: gameID,
```

The lines following it close the call. Insert `imagesAPI: imagesAPI,` on the line after `gameID:`. (Read `GameDetailView.swift`'s existing call to see the exact argument order.)

- [x] **Step 6.2: Add image-state properties**

Find the `@State` block at the top of `EditGameView`'s body. After the `@State private var review = ""` (or whichever is the last existing `@State`), add:

```swift
    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil
    @State private var existingBackImage: String? = nil
    @State private var loaded = false
```

(If `loaded` already exists in the file, don't duplicate it.)

Add a computed lookup property after the `@State` block:

```swift
    private var currentGameServerId: Int? {
        (context.model(for: gameID) as? Game)?.serverId
    }
```

- [x] **Step 6.3: Insert two `CoverImagePickerSection`s at the top of the Form**

Find the `Form {` opening at the top of the `body` content. Right after the `Form {` line, insert (before any existing Section):

```swift
                CoverImagePickerSection(pendingNewImage: $pendingNewFrontImage,
                                        existingImageString: $existingFrontImage,
                                        kind: .game,
                                        serverId: currentGameServerId,
                                        face: .front,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Front cover")

                CoverImagePickerSection(pendingNewImage: $pendingNewBackImage,
                                        existingImageString: $existingBackImage,
                                        kind: .game,
                                        serverId: currentGameServerId,
                                        face: .back,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Back cover")
```

- [x] **Step 6.4: Load the existing image strings on `loadOnce`**

Find the existing `loadOnce()` (or equivalent — the function that pulls field values out of the Game model into `@State` when the sheet first appears). Add at the end of the function body:

```swift
        existingFrontImage = g.frontCoverImage
        existingBackImage  = g.backCoverImage
```

(`g` is the local `Game` reference inside `loadOnce()`. If the function uses a different variable name, adjust.)

If `EditGameView` doesn't already gate loading with `loaded = true`, wrap the load body:

```swift
    private func loadOnce() {
        guard !loaded, let g: Game = context.model(for: gameID) as? Game else { return }
        // ...existing field copies...
        existingFrontImage = g.frontCoverImage
        existingBackImage  = g.backCoverImage
        loaded = true
    }
```

And ensure the body has `.task { loadOnce() }` after the toolbar.

- [x] **Step 6.5: Encode + write images in `save()`, plus cache invalidation**

Find the `save()` function. Near the end, before `try? context.save()`, add:

```swift
        // Image upload (front): encode new pick → data URI; remove
        // clears the column; otherwise preserve existing string.
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            g.frontCoverImage = dataURI
        } else if pendingNewFrontImage == nil && existingFrontImage == nil && g.frontCoverImage != nil {
            g.frontCoverImage = nil
        }

        // Image upload (back): same rules.
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            g.backCoverImage = dataURI
        } else if pendingNewBackImage == nil && existingBackImage == nil && g.backCoverImage != nil {
            g.backCoverImage = nil
        }
```

(`g` is the local `Game` reference inside `save()`. Adjust if named differently.)

After `try? context.save()`, before any `syncTrigger.pingAfterMutation()` call, add cache invalidation:

```swift
        if let serverId = g.serverId {
            imagesAPI.invalidateGameCover(gameServerId: serverId)
        }
```

(`invalidateGameCover` is added by Task 8 — the call site compiles after Task 8 lands.)

- [x] **Step 6.6: Build check (expected: FAIL until Task 8 lands)**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: **BUILD FAILED** — `invalidateGameCover` not found on `ImagesAPI`. Task 8 adds it.

---

## Task 7: `GameDetailView` — tap-to-flip on the cover

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift`

Add a single `@State` flag and a `.onTapGesture` on the cover image so users can flip between front and back. The existing PhotosPicker upload button and "Set cover from URL…" button stay below the cover unchanged.

- [x] **Step 7.1: Add `showingBack` state**

Find the existing `@State` block at the top of `GameDetailView`. Add at the end of the block:

```swift
    @State private var showingBack = false
```

- [x] **Step 7.2: Apply tap-to-flip on the cover image**

Find the cover rendering — in `GameDetailView`'s body there's a `CoverImage(gameServerId: game.serverId, ...)` somewhere near the top. It currently uses `face: .front` (or no explicit face — `.front` is the default). Find that block and update it. The exact existing call depends on Plan 3a's layout; read the file's body to locate it.

Replace the existing front-only call:

```swift
CoverImage(gameServerId: game.serverId, face: .front, size: .full, api: imagesAPI)
```

(or whatever the current call looks like — match its exact argument order and the surrounding `.frame` modifiers) with a version that respects `showingBack`:

```swift
CoverImage(gameServerId: game.serverId,
           face: showingBack ? .back : .front,
           size: .full,
           api: imagesAPI)
    .onTapGesture {
        showingBack.toggle()
    }
```

(Add `.onTapGesture` either inside the same modifier chain as the existing `.frame`/`.clipShape` modifiers, or directly after the `CoverImage(...)` call — whichever fits the existing style.)

- [x] **Step 7.3: Build check (expected: still FAIL until Task 8)**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -20
```

Expected: still **BUILD FAILED** due to `invalidateGameCover` in `EditGameView`. Task 8 fixes it.

---

## Task 8: `ImagesAPI.invalidateGameCover(gameServerId:)`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Networking/ImagesAPI.swift`

Add a helper parallel to the existing `invalidateItemCover`. Game-cover cache filenames use the `cover_<id>_<face>_<size>.jpg` format (the format used by `downloadCover(gameServerId:…)`).

- [x] **Step 8.1: Add the helper**

Find the existing `invalidateItemCover` method:

```swift
    /// Purge cached item cover files for one item (both faces, both sizes).
    /// Called by Add/Edit save paths after writing a new data URI into
    /// `item.frontImage` so the next render fetches the new bytes instead
    /// of returning the stale cached file.
    func invalidateItemCover(itemServerId: Int) {
        for face in [Face.front, Face.back] {
            for size in [Size.thumb, Size.full] {
                let filename = "item_\(itemServerId)_\(face.rawValue)_\(size.rawValue).jpg"
                let dest = cacheRoot.appendingPathComponent(filename)
                try? FileManager.default.removeItem(at: dest)
            }
        }
    }
}
```

Insert this new method **immediately before** the closing `}` of the `ImagesAPI` struct (after `invalidateItemCover`):

```swift

    /// Purge cached game cover files for one game (both faces, both sizes).
    /// Called by Add/Edit save paths after writing a new data URI into
    /// `game.frontCoverImage` / `game.backCoverImage`.
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

- [x] **Step 8.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

Expected: **BUILD SUCCEEDED**.

---

## Task 9: Full test pass + bundle commit

**Files:** none changed in this task.

- [x] **Step 9.1: Clear iCloud conflict files (just in case)**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

- [x] **Step 9.2: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: **`** TEST SUCCEEDED **`**.

- [x] **Step 9.3: Pre-commit sanity check**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected listing:

- Modified: `ItemFormBody.swift`, `AddItemView.swift`, `EditItemView.swift`, `AddGameView.swift`, `EditGameView.swift`, `GameDetailView.swift`, `ImagesAPI.swift`
- Deleted: `Views/Items/ItemImagePicker.swift`
- Untracked: `Views/Common/CoverImagePicker.swift`
- Pre-existing junk: `js/completions.js`, iCloud `* 2.sh` / `* 2.php` conflict files

- [x] **Step 9.4: Bundle commit Tasks 1–8**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Common/CoverImagePicker.swift \
        ios/GameTracker/GameTracker/Views/Items/ItemImagePicker.swift \
        ios/GameTracker/GameTracker/Views/Items/ItemFormBody.swift \
        ios/GameTracker/GameTracker/Views/Items/AddItemView.swift \
        ios/GameTracker/GameTracker/Views/Items/EditItemView.swift \
        ios/GameTracker/GameTracker/Views/Detail/AddGameView.swift \
        ios/GameTracker/GameTracker/Views/Detail/EditGameView.swift \
        ios/GameTracker/GameTracker/Views/Detail/GameDetailView.swift \
        ios/GameTracker/GameTracker/Networking/ImagesAPI.swift
git commit -m "Expand image upload: games + back covers + URL paste"
```

### 🛑 User checkpoint — image upload expansion

Stop here. The owner should ⌘R in Xcode (iPhone 17 sim) and verify each flow independently. Each step is listed standalone so a failure can be reported precisely.

1. **Items front (regression):** Add an item → tap front cover's "Add a photo" → Choose from Library → pick → preview → Save. New row's thumbnail shows the picked image.
2. **Items back (new):** Edit an item → tap back cover's "Add a photo" → library → pick → Save. Detail view: tap front cover → flips to the new back cover.
3. **Items URL paste:** Edit an item → tap front cover → Paste URL → input a known https image URL → Fetch → preview shows → Save. Item now displays the fetched image.
4. **Games front + back during Add:** Library → `+` → fill in Title/Platform → front cover Take Photo (sim shows black; just Cancel) → front cover Choose from Library → library pick → back cover Choose from Library → library pick → Save. New game appears in Library with front cover; open detail → tap → back cover.
5. **Games URL paste during Add:** Library → `+` → fill in Title/Platform → front cover Paste URL → fetch → preview → Save. Game appears in Library with the URL-sourced cover.
6. **Games front during Edit:** Open an existing game → Edit → front cover → Change → library → pick → Save. Detail view's cover updates.
7. **Games back during Edit:** Open an existing game → Edit → back cover → Add a photo → library → pick → Save. Detail view: tap front cover → flips to the new back cover.
8. **Games URL paste during Edit:** Edit an existing game → front cover → Change → Paste URL → fetch → Save. Cover updates.
9. **Tap-to-flip on GameDetailView (new):** Open a game with both covers set → tap → back. Tap again → front.
10. **GameDetailView's existing "Upload cover photo…" PhotosPicker (regression):** Open a game with `serverId` set → scroll down → "Upload cover photo…" → pick from library. Upload succeeds; cover updates after sync.
11. **GameDetailView's existing "Set cover from URL…" sheet (regression):** Open a game with `serverId` set → "Set cover from URL…" → paste a URL → Save. Cover updates after sync.
12. **AddGameView no longer shows the inert URL field:** Library → `+` → confirm the form has Required, two cover-image sections, and the metadata-fetch section — no standalone "Paste image URL" text field.
13. **URL-paste errors surface clearly:** Try a 404 URL (e.g. `https://example.com/nonexistent.jpg`) → see "Server returned HTTP 404" in the URL dialog. Try a non-https URL → "URL must start with https://".
14. **No regression on Library / Items / Completions / Stats / Settings.**

Resume the implementer queue only after the owner confirms or reports a specific failure.

---

## Task 10: Manual smoke pass (collapsed by default)

**Files:** none.

If the checkpoint above covered every flow the owner cares about (Plans 3a-3d precedent), this task is a no-op — confirm and move to Task 11.

- [x] **Step 10.1: Optional walkthrough (skip if checkpoint coverage was sufficient)**

| # | Action | Expected |
|---|---|---|
| 1 | Sign in with a multi-game account | Library + Items + Completions load |
| 2 | Walk every checkpoint step | All pass |
| 3 | Library / Items / Completions / Stats / Settings | All work |

---

## Task 11: Push + open PR + wrap up

**Files:** none.

- [x] **Step 11.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk.

- [x] **Step 11.2: Push**

```bash
git push -u origin plan-3e-image-upload-expansion
```

- [x] **Step 11.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-22-ios-image-upload-expansion.md
git add docs/superpowers/plans/2026-05-22-ios-image-upload-expansion.md
git commit -m "Mark Plan 3e (iOS image upload expansion) complete"
git push
```

- [x] **Step 11.4: Open PR**

```bash
gh pr create --base main --head plan-3e-image-upload-expansion \
  --title "Plan 3e: iOS image upload expansion" \
  --body "$(cat <<'EOF'
## Summary

Extends Plan 3c's image-upload flow so the user can photograph, library-pick, or URL-paste **front + back covers** for both **games and items**, from both **Add and Edit screens**, with the new picker working even before the first sync.

- Refactored Plan 3c's `ItemImagePicker` → generic `CoverImagePicker` under \`Views/Common/\`, parameterised by kind (game/item) and face (front/back).
- Added a third capture source — **Paste URL** — with a client-side \`URLSession\` fetch (https-only, 30s timeout, 10 MB max, image content-type validation). Works even before the new row has a \`serverId\`, so URL input works during Add.
- Forms (\`AddItemView\`, \`EditItemView\`, \`AddGameView\`, \`EditGameView\`) now show **two** picker sections: front cover and back cover.
- \`GameDetailView\` gains tap-to-flip on the cover (mirroring \`ItemDetailView\`).
- \`AddGameView\`'s inert "Paste image URL" text field removed (intentionally inert since Plan 3a — now replaced by the new picker's URL option).
- \`ImagesAPI.invalidateGameCover(gameServerId:)\` parallels the existing item version; called by \`EditGameView\` save paths so the next render fetches the new bytes.

## What didn't change

- \`GameDetailView\`'s existing "Upload cover photo…" PhotosPicker (multipart to \`/api/v2/games/cover-upload.php\`) — untouched.
- \`GameDetailView\`'s existing "Set cover from URL…" sheet (server-side fetch via \`proxiesAPI.externalImage\`) — untouched.
- Server, sync layer, all models.

## Test Plan

- [x] \`xcodebuild test\` on iPhone 17 sim — full suite passes
- [x] Manual checkpoint: items front (regression), items back (new), items URL paste (new), games front + back during Add, games URL paste during Add and Edit, tap-to-flip, both existing GameDetailView paths (regression), URL-paste errors
- [x] No regression on Library / Items / Completions / Stats / Settings

## Not in scope (Plan 3f+ territory)

Multi-image extras gallery, in-app image editing (crop/rotate), image upload for completions, cache invalidation when the web app edits an image between iOS syncs, unifying the existing GameDetailView upload paths with the new picker, bumping the downscale size from 1024 to 2048 to match the existing GameDetailView helper.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [x] Every referenced symbol exists: `CoverKind`, `CameraPickerView`, `CoverImageProcessor`, `CoverImageURLFetcher`, `CoverImagePickerSection`, `ImagesAPI.invalidateItemCover`, `ImagesAPI.invalidateGameCover`, `CoverImage(gameServerId:)`, `CoverImage(itemServerId:)`, `Game.frontCoverImage`, `Game.backCoverImage`, `Item.frontImage`, `Item.backImage`, `ItemCategory`, `SyncTrigger.pingAfterMutation()`. (All landed via prior plans + Task 1 + Task 8 of this plan.)
- [x] No file is referenced by two different names across tasks.
- [x] `CoverImagePickerSection`'s parameter list `(pendingNewImage:, existingImageString:, kind:, serverId:, face:, imagesAPI:, sectionTitle:)` is identical across every call site in Tasks 2, 3, 4, 5, 6.
- [x] `CoverImageProcessor.dataURI(from:)` is the function name used in every save path in Tasks 3, 4, 5, 6 — not `ItemImageProcessor.dataURI` (the old name).
- [x] `ItemFormBody`'s new bindings `pendingNewFrontImage`, `pendingNewBackImage`, `existingFrontImage`, `existingBackImage` are named identically in the struct (Task 2) and in the call sites (Tasks 3, 4).
- [x] Game-cover cache filenames use `cover_<id>_…` prefix (Task 8) — matches the format used by `ImagesAPI.downloadCover(gameServerId:…)` in prior plans.
- [x] All commit messages cover the visible behaviour and bundle interdependent files (Plan 3c precedent).
- [x] No "TBD" or "implement later" anywhere except the meta-line in this self-review checklist.
