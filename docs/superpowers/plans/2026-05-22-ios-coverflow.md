# iOS Library CoverFlow Implementation Plan (Plan 4c)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a third Library view mode — CoverFlow — that renders games as real 3D textured boxes (`SCNBox`) in a horizontally scrolling row. Center box front-facing, side boxes rotated ~45° away, with the focused game's title + platform shown below.

**Architecture:** SceneKit (3D scene) embedded in SwiftUI via `UIViewRepresentable`. SpriteKit generates the spine textures (title rotated 90° on theme-accent background). Existing `@Query` filteredGames feeds the scene. Pan gesture → scroll math → snap-to-center. Tap → SCNHitTest → either snap-to-center (side box) or navigate to detail (focused box).

**Tech Stack:** Swift 5.10+, SwiftUI, SceneKit, SpriteKit, SwiftData (existing). No new server endpoints, no new packages.

**Predecessors:** Plans 3a–3e + 4a + 4b complete. Branch `plan-4c-coverflow` already created with design spec (`3cc058d`) committed. Spec: [`docs/superpowers/specs/2026-05-22-ios-coverflow-design.md`](../specs/2026-05-22-ios-coverflow-design.md).

**Execution rhythm:** Single bundle commit at end of Task 8. CoverFlow only becomes visible when LibraryView integration lands (Task 7), so single checkpoint at end of plan. Matches 4a/4b/3e pattern.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-4c-coverflow`, branched off `main` (Plan 4b merged at `af3de62`).
- **Pre-existing changes to leave alone:** `js/completions.js`, `scripts/generate-thumbnails 2.php`, `tests/v2/* 2.sh`, `ios/GameTracker/GameTrackerTests/Helpers 2/`.
- **iCloud Drive Swift conflict files:** clear before each test pass: `find ios/GameTracker -name "* [0-9].swift" -print -delete`.

---

## File structure

### New iOS files

```
ios/GameTracker/GameTracker/Views/Library/
  MediaTypeInfer.swift              — pure platform → MediaType helper
  SpineTextureBuilder.swift         — SpriteKit-based spine label texture
  CoverFlowCaseNode.swift           — single SCNBox factory (geometry + materials)
  CoverFlowScene.swift              — SCNScene factory: camera, lights, case row, scroll math
  CoverFlowSceneView.swift          — UIViewRepresentable wrapping SCNView + gestures
  CoverFlowView.swift               — SwiftUI host: scene view + label + nav binding

ios/GameTracker/GameTrackerTests/
  MediaTypeInferTests.swift         — ~20 platform-string assertions
```

### Modified iOS files

| File | Change |
|---|---|
| `Views/Library/LibraryView.swift` | Add `.coverflow` to ViewMode enum; new toolbar icon row; `case .coverflow: CoverFlowView(...)` in content switch. |

### Untouched

- All other tabs, models, sync, networking, themes.
- Library's list and grid modes — bytes unchanged.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-22-ios-coverflow.md` (this file)

- [ ] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current        # → plan-4c-coverflow
git log --oneline -3              # spec on top of 4b merge
git status --short                # only pre-existing junk
```

Expected: branch is `plan-4c-coverflow`; spec commit (`3cc058d`) sits on top of the 4b merge (`af3de62`).

- [ ] **Step 0.2: Clear iCloud Swift conflict files**

```bash
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

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
git add docs/superpowers/plans/2026-05-22-ios-coverflow.md
git commit -m "Add Plan 4c (Library CoverFlow) implementation plan"
```

---

## Task 1: `MediaTypeInfer` (TDD)

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Library/MediaTypeInfer.swift`
- Create: `ios/GameTracker/GameTrackerTests/MediaTypeInferTests.swift`

Pure function. Cart-format consoles (Switch, DS, 3DS, GBA, Game Boy, NES, SNES, N64, Vita) render in a narrower box; everything else (PlayStation, Xbox, GameCube, Wii, PC, etc.) renders in a wider DVD-case box.

- [ ] **Step 1.1: Write the failing tests**

Write `ios/GameTracker/GameTrackerTests/MediaTypeInferTests.swift`:

```swift
import XCTest
@testable import GameTracker

final class MediaTypeInferTests: XCTestCase {

    func test_modern_disc_consoles_are_disc() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 5"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 4"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Xbox Series X"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Xbox One"),            .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "GameCube"),            .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Wii"),                 .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Wii U"),               .disc)
    }

    func test_cart_consoles_are_cart() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo Switch"),     .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo 3DS"),        .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo DS"),         .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Game Boy Advance"),    .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Game Boy Color"),      .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation Vita"),    .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "GBA"),                 .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "SNES"),                .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "N64"),                 .cart)
    }

    func test_case_insensitive() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "nintendo switch"),     .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PLAYSTATION 5"),       .disc)
    }

    func test_pc_defaults_to_disc() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "PC"),                  .disc)
    }

    func test_empty_string_defaults_to_disc() {
        XCTAssertEqual(MediaTypeInfer.infer(from: ""),                    .disc)
    }

    func test_partial_match_with_super_nintendo_label() {
        // "Super Nintendo (SNES)" should still hit the SNES keyword.
        XCTAssertEqual(MediaTypeInfer.infer(from: "Super Nintendo (SNES)"), .cart)
    }
}
```

- [ ] **Step 1.2: Run tests — expect compile failure**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "BUILD FAILED|error:" | head -5
```

Expected: BUILD FAILED — `MediaTypeInfer` not found.

- [ ] **Step 1.3: Implement `MediaTypeInfer.swift`**

Write `ios/GameTracker/GameTracker/Views/Library/MediaTypeInfer.swift`:

```swift
import Foundation

/// Box shape for a game in CoverFlow.
enum MediaType {
    case disc      // DVD-case proportions
    case cart      // smaller Switch / 3DS / GBA-style case
}

/// Maps a user-entered platform string to a media type. Lives outside
/// the SwiftUI view tree so it's testable without mounting a scene.
enum MediaTypeInfer {

    /// Case-insensitive substring match against `cartKeywords`.
    /// Unknown / empty platforms default to `.disc` — that's the
    /// safer fallback because disc-format games make up most of the
    /// modern collection.
    static func infer(from platform: String) -> MediaType {
        let lower = platform.lowercased()
        for keyword in cartKeywords {
            if lower.contains(keyword.lowercased()) { return .cart }
        }
        return .disc
    }

    /// Substrings that indicate a cartridge-format game. Order
    /// doesn't matter — first match wins, all return `.cart`.
    private static let cartKeywords: [String] = [
        "nintendo switch",
        "switch",
        "3ds",
        "nintendo ds",
        "game boy",
        "gba",
        "snes",
        "super nintendo",
        "n64",
        "nintendo 64",
        "nes",         // also matches "SNES"; OK since both are carts
        "vita",
    ]
}
```

- [ ] **Step 1.4: Run tests — expect pass**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -3
```

Expected: `** TEST SUCCEEDED **`. (No commit yet — bundled at Task 8.)

---

## Task 2: `SpineTextureBuilder`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Library/SpineTextureBuilder.swift`

Uses SpriteKit's `SKView.texture(from:)` to snapshot an `SKScene` into a `UIImage` that SceneKit can use as a material texture. The scene is a 60×600 pixel canvas: theme-accent background + game title rotated 90°.

- [ ] **Step 2.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Library/SpineTextureBuilder.swift`:

```swift
import UIKit
import SpriteKit
import SwiftUI

/// Generates a tall, narrow `UIImage` for use as the spine texture of
/// a `CoverFlowCaseNode`. The title is rotated 90° so it reads
/// bottom-to-top when the box stands upright.
@MainActor
enum SpineTextureBuilder {

    /// Returns nil on snapshot failure (very rare; mainly for safety).
    static func makeSpine(title: String,
                          background: Color,
                          fontName: String? = nil,
                          width: CGFloat = 60,
                          height: CGFloat = 600) -> UIImage? {
        let scene = SKScene(size: CGSize(width: width, height: height))
        scene.scaleMode = .resizeFill
        scene.backgroundColor = UIColor(background)

        // Compose the title node, rotated 90° (counter-clockwise) so
        // text runs from the bottom toward the top.
        let label = SKLabelNode(text: title)
        label.fontColor = .white
        label.fontSize = 22
        label.fontName = resolvedFontName(preferred: fontName)
        label.verticalAlignmentMode = .center
        label.horizontalAlignmentMode = .center
        label.position = CGPoint(x: width / 2, y: height / 2)
        label.zRotation = .pi / 2   // 90° anti-clockwise
        // Clamp the displayed width so very long titles don't render
        // off the spine. SpriteKit doesn't truncate natively; we cap
        // text width via a length-based scale.
        scaleLabelToFit(label, maxLength: height - 40)
        scene.addChild(label)

        // Snapshot via an offscreen SKView.
        let view = SKView(frame: CGRect(origin: .zero,
                                         size: CGSize(width: width, height: height)))
        view.allowsTransparency = false
        view.isOpaque = true
        let texture = view.texture(from: scene)
        guard let cg = texture?.cgImage() else { return nil }
        return UIImage(cgImage: cg, scale: UIScreen.main.scale, orientation: .up)
    }

    /// Returns the bundled custom font name if it exists and the
    /// preference is set; otherwise falls back to a system bold font.
    private static func resolvedFontName(preferred: String?) -> String {
        if let name = preferred, UIFont(name: name, size: 12) != nil {
            return name
        }
        return "AvenirNext-Bold"   // a system font reliably present
    }

    /// Roughly scales the label so its rotated content fits the
    /// spine's long axis. Imperfect but good enough for v1.
    private static func scaleLabelToFit(_ label: SKLabelNode,
                                        maxLength: CGFloat) {
        guard label.frame.width > maxLength else { return }
        let scale = maxLength / label.frame.width
        label.fontSize *= max(scale, 0.4)   // floor at 40% so very long titles stay legible
    }
}
```

- [ ] **Step 2.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

---

## Task 3: `CoverFlowCaseNode`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Library/CoverFlowCaseNode.swift`

Factory that builds a single `SCNNode` containing an `SCNBox` geometry with 6 materials (front cover, back cover, two spines, top, bottom). Cover textures load async from `ImagesAPI`; the box renders with placeholder materials until they arrive.

- [ ] **Step 3.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Library/CoverFlowCaseNode.swift`:

```swift
import UIKit
import SceneKit
import SwiftUI

/// Builds a single textured `SCNNode` representing one game's box.
/// The node has a `name` set to the persistent ID string so hit-tests
/// can map back to the SwiftData row.
///
/// Box face order (Apple's SCNBox materialsArray indexing):
///   0: front (+Z)
///   1: right (+X) — spine
///   2: back  (-Z)
///   3: left  (-X) — spine
///   4: top   (+Y)
///   5: bottom (-Y)
@MainActor
enum CoverFlowCaseNode {

    /// Box dimensions in SceneKit "units". The camera + lighting
    /// elsewhere are tuned for these values.
    private static let discSize  = SCNVector3(0.28, 0.40, 0.022)
    private static let cartSize  = SCNVector3(0.22, 0.32, 0.018)

    /// Solid placeholder colors used until textures load.
    private static let placeholderFront  = UIColor(white: 0.85, alpha: 1.0)
    private static let placeholderDark   = UIColor(white: 0.10, alpha: 1.0)

    /// Build the node. Cover textures are kicked off as async work;
    /// the returned node renders with placeholders immediately and
    /// updates in place when the images arrive.
    static func make(game: Game,
                     imagesAPI: ImagesAPI,
                     theme: Theme) -> SCNNode {
        let media = MediaTypeInfer.infer(from: game.platform)
        let size  = (media == .cart) ? cartSize : discSize

        let box = SCNBox(width: CGFloat(size.x),
                         height: CGFloat(size.y),
                         length: CGFloat(size.z),
                         chamferRadius: 0.004)
        box.materials = makeInitialMaterials(game: game, theme: theme)

        let node = SCNNode(geometry: box)
        node.name = game.persistentModelID.uriRepresentation()?.absoluteString
            ?? "\(ObjectIdentifier(game).hashValue)"

        // Kick off async cover loads. Failures are logged and the
        // placeholder stays.
        Task { @MainActor in
            await applyCoverTextures(to: node, game: game, imagesAPI: imagesAPI)
        }
        return node
    }

    // MARK: - Material setup

    private static func makeInitialMaterials(game: Game, theme: Theme) -> [SCNMaterial] {
        let spineImage = SpineTextureBuilder.makeSpine(
            title: game.title,
            background: theme.accent,
            fontName: theme.fontName
        )

        return [
            material(contents: placeholderFront),              // 0 front
            material(contents: spineImage ?? placeholderDark), // 1 right spine
            material(contents: placeholderFront),              // 2 back
            material(contents: spineImage ?? placeholderDark), // 3 left spine
            material(contents: placeholderDark),               // 4 top
            material(contents: placeholderDark),               // 5 bottom
        ]
    }

    private static func material(contents: Any) -> SCNMaterial {
        let m = SCNMaterial()
        m.diffuse.contents = contents
        m.locksAmbientWithDiffuse = true
        return m
    }

    // MARK: - Async cover loading

    private static func applyCoverTextures(to node: SCNNode,
                                           game: Game,
                                           imagesAPI: ImagesAPI) async {
        guard let serverId = game.serverId else { return }
        // Front
        if let front = await loadImage(imagesAPI: imagesAPI,
                                       serverId: serverId,
                                       face: .front) {
            node.geometry?.materials[0].diffuse.contents = front
        }
        // Back — fall back to dimmed front if back not set.
        if let back = await loadImage(imagesAPI: imagesAPI,
                                      serverId: serverId,
                                      face: .back) {
            node.geometry?.materials[2].diffuse.contents = back
        } else if let frontFallback = node.geometry?.materials[0].diffuse.contents as? UIImage {
            node.geometry?.materials[2].diffuse.contents = dim(frontFallback, by: 0.6)
        }
    }

    private static func loadImage(imagesAPI: ImagesAPI,
                                  serverId: Int,
                                  face: ImagesAPI.Face) async -> UIImage? {
        guard let url = try? await imagesAPI.downloadCover(
            gameServerId: serverId, face: face, size: .full
        ) else { return nil }
        return UIImage(contentsOfFile: url.path)
    }

    /// Multiplies the image's pixels toward black. Used as a back-cover
    /// fallback when no real back-cover is available.
    private static func dim(_ image: UIImage, by factor: CGFloat) -> UIImage? {
        guard let cg = image.cgImage else { return nil }
        let size = CGSize(width: cg.width, height: cg.height)
        let renderer = UIGraphicsImageRenderer(size: size)
        return renderer.image { ctx in
            image.draw(in: CGRect(origin: .zero, size: size))
            ctx.cgContext.setFillColor(UIColor(white: 0, alpha: factor).cgColor)
            ctx.cgContext.fill(CGRect(origin: .zero, size: size))
        }
    }
}
```

- [ ] **Step 3.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 4: `CoverFlowScene`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Library/CoverFlowScene.swift`

Owns the `SCNScene`, the case row, the focused index, the camera + lights. Provides methods the SceneView calls when the games array changes or the user scrolls.

- [ ] **Step 4.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Library/CoverFlowScene.swift`:

```swift
import SceneKit
import SwiftUI

/// Manages the SCNScene's case row: layout math, recycling of off-screen
/// boxes, and animation between focused indices.
@MainActor
final class CoverFlowScene {

    let scene: SCNScene
    let cameraNode: SCNNode
    let caseRow: SCNNode

    /// Games array as last seen. The scene rebuilds case nodes when
    /// this changes.
    private(set) var games: [Game] = []

    /// Index of the currently focused (centered) game.
    private(set) var focusedIndex: Int = 0

    /// Visible-window radius. Boxes more than `windowRadius` cases
    /// away from `focusedIndex` are removed from the scene.
    private let windowRadius = 3

    /// Currently realized case nodes, keyed by game index.
    private var caseNodes: [Int: SCNNode] = [:]

    private let imagesAPI: ImagesAPI
    private var theme: Theme

    init(imagesAPI: ImagesAPI, theme: Theme) {
        self.imagesAPI = imagesAPI
        self.theme = theme

        scene = SCNScene()
        scene.background.contents = UIColor.clear

        caseRow = SCNNode()
        scene.rootNode.addChildNode(caseRow)

        // Camera
        let cam = SCNCamera()
        cam.fieldOfView = 50
        cameraNode = SCNNode()
        cameraNode.camera = cam
        cameraNode.position = SCNVector3(0, 0, 1.2)
        scene.rootNode.addChildNode(cameraNode)

        // Lights
        let ambient = SCNLight()
        ambient.type = .ambient
        ambient.intensity = 500
        let ambientNode = SCNNode()
        ambientNode.light = ambient
        scene.rootNode.addChildNode(ambientNode)

        let directional = SCNLight()
        directional.type = .directional
        directional.intensity = 700
        let dirNode = SCNNode()
        dirNode.light = directional
        dirNode.eulerAngles = SCNVector3(-Float.pi / 4, 0, 0)
        scene.rootNode.addChildNode(dirNode)
    }

    // MARK: - Public API

    func update(games: [Game]) {
        self.games = games

        // Reset focused index if it now falls outside the array.
        if focusedIndex >= games.count {
            focusedIndex = max(0, games.count - 1)
        }

        rebuildVisibleNodes()
    }

    func updateTheme(_ theme: Theme) {
        self.theme = theme
        // Spines are regenerated by rebuilding nodes. Clear cache.
        caseNodes.values.forEach { $0.removeFromParentNode() }
        caseNodes.removeAll()
        rebuildVisibleNodes()
    }

    /// Smoothly snap the row to a new focused index.
    func snap(to index: Int, animated: Bool = true) {
        let clamped = max(0, min(games.count - 1, index))
        focusedIndex = clamped

        SCNTransaction.begin()
        SCNTransaction.animationDuration = animated ? 0.35 : 0.0
        rebuildVisibleNodes()
        SCNTransaction.commit()
    }

    /// Game at a given index (or nil if out of range).
    func game(at index: Int) -> Game? {
        guard index >= 0, index < games.count else { return nil }
        return games[index]
    }

    /// Find the index of a node by name, if any.
    func index(of nodeName: String?) -> Int? {
        guard let name = nodeName else { return nil }
        return caseNodes.first { _, node in node.name == name }?.key
    }

    // MARK: - Layout

    private func rebuildVisibleNodes() {
        let lo = max(0, focusedIndex - windowRadius)
        let hi = min(games.count - 1, focusedIndex + windowRadius)

        // Remove nodes outside the new window.
        for (i, node) in caseNodes where i < lo || i > hi {
            node.removeFromParentNode()
            caseNodes.removeValue(forKey: i)
        }

        // Add nodes for newly-visible indices.
        for i in lo...hi where caseNodes[i] == nil {
            guard let game = game(at: i) else { continue }
            let node = CoverFlowCaseNode.make(game: game,
                                              imagesAPI: imagesAPI,
                                              theme: theme)
            caseRow.addChildNode(node)
            caseNodes[i] = node
        }

        // Position + rotate all visible nodes based on offset from focus.
        for (i, node) in caseNodes {
            let offset = i - focusedIndex
            applyTransform(to: node, offset: offset)
        }
    }

    /// Map indexOffset → (position, rotation, scale, opacity).
    private func applyTransform(to node: SCNNode, offset: Int) {
        let absOff = abs(offset)
        let sign = Float(offset.signum())   // -1, 0, or +1

        // Translation
        let x: Float
        let y: Float
        let z: Float
        switch absOff {
        case 0: (x, y, z) = (0,           0.15,  0.15)
        case 1: (x, y, z) = (0.55 * sign, 0,     0)
        case 2: (x, y, z) = (0.95 * sign, 0,    -0.05)
        default: (x, y, z) = (1.5 * sign,  0,    -0.10)
        }
        node.position = SCNVector3(x, y, z)

        // Rotation around Y (anti-clockwise for positive offsets, so
        // boxes to the right face left).
        let yaw: Float
        switch absOff {
        case 0: yaw =  0
        case 1: yaw = -sign * (.pi / 4)         // 45°
        case 2: yaw = -sign * (.pi / 180 * 55)  // 55°
        default: yaw = -sign * (.pi / 180 * 60)
        }
        node.eulerAngles = SCNVector3(0, yaw, 0)

        // Scale
        let scale: Float
        switch absOff {
        case 0: scale = 1.0
        case 1: scale = 0.85
        case 2: scale = 0.75
        default: scale = 0.6
        }
        node.scale = SCNVector3(scale, scale, scale)

        // Opacity
        node.opacity = CGFloat(max(0, 1.0 - Double(absOff) * 0.25))
    }
}
```

- [ ] **Step 4.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 5: `CoverFlowSceneView` — UIViewRepresentable + gestures

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Library/CoverFlowSceneView.swift`

Wraps an `SCNView` for SwiftUI. Hosts the pan + tap gesture recognizers. Reports the focused index up to the SwiftUI host via a binding, and reports tap-on-focused-box via a closure.

- [ ] **Step 5.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Library/CoverFlowSceneView.swift`:

```swift
import SwiftUI
import SceneKit
import UIKit

struct CoverFlowSceneView: UIViewRepresentable {

    let games: [Game]
    let imagesAPI: ImagesAPI
    let theme: Theme
    @Binding var focusedIndex: Int
    /// Called when the user taps the currently-focused box.
    let onActivateFocused: () -> Void

    final class Coordinator: NSObject, UIGestureRecognizerDelegate {
        let scene: CoverFlowScene
        var pannedFromIndex: Int = 0
        var parent: CoverFlowSceneView

        init(parent: CoverFlowSceneView) {
            self.parent = parent
            self.scene = CoverFlowScene(imagesAPI: parent.imagesAPI,
                                         theme: parent.theme)
        }

        @objc func handlePan(_ pan: UIPanGestureRecognizer) {
            guard let view = pan.view else { return }
            let dx = pan.translation(in: view).x
            // Convert pan distance to a fractional index offset.
            // Empirical: ~120pt per case feels natural.
            let fractional = -Double(dx) / 120.0

            switch pan.state {
            case .began:
                pannedFromIndex = scene.focusedIndex
            case .changed:
                // Live preview during the pan: float index, no snap.
                let target = pannedFromIndex + Int(fractional.rounded())
                let clamped = max(0, min(scene.games.count - 1, target))
                if clamped != scene.focusedIndex {
                    scene.snap(to: clamped, animated: false)
                    parent.focusedIndex = clamped
                }
            case .ended, .cancelled:
                // Snap with animation to final index (with a velocity
                // kick — flick > 1500 pt/s adds an extra step).
                let velocity = pan.velocity(in: view).x
                var target = scene.focusedIndex
                if abs(velocity) > 1500 {
                    target += velocity < 0 ? 1 : -1
                }
                let clamped = max(0, min(scene.games.count - 1, target))
                scene.snap(to: clamped, animated: true)
                parent.focusedIndex = clamped
            default:
                break
            }
        }

        @objc func handleTap(_ tap: UITapGestureRecognizer) {
            guard let scnView = tap.view as? SCNView else { return }
            let location = tap.location(in: scnView)
            let hits = scnView.hitTest(location, options: nil)
            guard let hit = hits.first else { return }

            // Walk up the parent chain until we find a node our scene
            // tracks (by name).
            var node: SCNNode? = hit.node
            while let n = node {
                if let idx = scene.index(of: n.name) {
                    if idx == scene.focusedIndex {
                        parent.onActivateFocused()
                    } else {
                        scene.snap(to: idx, animated: true)
                        parent.focusedIndex = idx
                    }
                    return
                }
                node = n.parent
            }
        }
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(parent: self)
    }

    func makeUIView(context: Context) -> SCNView {
        let scnView = SCNView(frame: .zero)
        scnView.scene = context.coordinator.scene.scene
        scnView.allowsCameraControl = false
        scnView.autoenablesDefaultLighting = false
        scnView.backgroundColor = .clear

        let pan = UIPanGestureRecognizer(target: context.coordinator,
                                          action: #selector(Coordinator.handlePan(_:)))
        pan.delegate = context.coordinator
        scnView.addGestureRecognizer(pan)

        let tap = UITapGestureRecognizer(target: context.coordinator,
                                          action: #selector(Coordinator.handleTap(_:)))
        scnView.addGestureRecognizer(tap)

        context.coordinator.scene.update(games: games)
        return scnView
    }

    func updateUIView(_ scnView: SCNView, context: Context) {
        // Update parent reference so coordinator's writeback uses the
        // latest binding closure.
        context.coordinator.parent = self
        context.coordinator.scene.updateTheme(theme)
        context.coordinator.scene.update(games: games)
        if context.coordinator.scene.focusedIndex != focusedIndex {
            context.coordinator.scene.snap(to: focusedIndex, animated: true)
        }
    }
}
```

- [ ] **Step 5.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 6: `CoverFlowView` — SwiftUI host

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Library/CoverFlowView.swift`

The SwiftUI entry point. Owns `@State focusedIndex`, renders the SCNView + below-row label, and triggers navigation when the user activates the focused box.

- [ ] **Step 6.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Library/CoverFlowView.swift`:

```swift
import SwiftUI
import SwiftData

struct CoverFlowView: View {
    let games: [Game]
    let imagesAPI: ImagesAPI
    let onSelectGame: (PersistentIdentifier) -> Void

    @Environment(\.theme) private var theme
    @State private var focusedIndex: Int = 0

    private var focused: Game? {
        guard focusedIndex >= 0, focusedIndex < games.count else { return nil }
        return games[focusedIndex]
    }

    var body: some View {
        VStack(spacing: 12) {
            CoverFlowSceneView(games: games,
                                imagesAPI: imagesAPI,
                                theme: theme,
                                focusedIndex: $focusedIndex,
                                onActivateFocused: activateFocused)
                .frame(maxWidth: .infinity)
                .frame(minHeight: 360)

            VStack(spacing: 2) {
                Text(focused?.title ?? "")
                    .font(.title3.bold())
                    .lineLimit(1)
                Text(focused?.platform ?? "")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            .padding(.horizontal, 16)
            .padding(.bottom, 12)
        }
        .onChange(of: games.count) { _, _ in
            // If the filter shrunk the list, ensure focus stays in range.
            if focusedIndex >= games.count {
                focusedIndex = max(0, games.count - 1)
            }
        }
    }

    private func activateFocused() {
        guard let game = focused else { return }
        onSelectGame(game.persistentModelID)
    }
}
```

- [ ] **Step 6.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 7: Integrate into `LibraryView`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Library/LibraryView.swift`

Add the `.coverflow` case to `ViewMode`, a new toolbar icon, and the `case .coverflow:` branch in the content switch.

- [ ] **Step 7.1: Add `.coverflow` to the ViewMode enum**

Find the existing `ViewMode` declaration in `LibraryView.swift`:

```swift
    enum ViewMode: String, CaseIterable, Identifiable {
        case list, grid
        var id: String { rawValue }
        var systemImage: String { self == .list ? "list.bullet" : "square.grid.2x2" }
    }
```

Replace with:

```swift
    enum ViewMode: String, CaseIterable, Identifiable {
        case list, grid, coverflow
        var id: String { rawValue }
        var systemImage: String {
            switch self {
            case .list:      return "list.bullet"
            case .grid:      return "square.grid.2x2"
            case .coverflow: return "rectangle.stack.fill"
            }
        }
    }
```

- [ ] **Step 7.2: Add the third branch in the `content` switch**

Find the content switch in `LibraryView.swift`:

```swift
            switch viewMode {
            case .list:
                List {
                    // ...
                }
            case .grid:
                ScrollView {
                    // ...
                }
            }
```

Add a `.coverflow` branch after `.grid`:

```swift
            case .coverflow:
                CoverFlowView(games: games,
                              imagesAPI: imagesAPI,
                              onSelectGame: { id in
                                  navigationPath.append(id)
                              })
```

If `LibraryView` doesn't currently track a `NavigationPath` (it uses `NavigationLink(value:)` rather than a manual path), the easiest wiring is to keep the navigation destination on `NavigationStack` and use a `@State navigationPath = NavigationPath()` bound to it. Read the file first; if the existing pattern doesn't use a path, you'll need to add one. Equivalent shape:

```swift
@State private var navigationPath = NavigationPath()
...
NavigationStack(path: $navigationPath) {
    // existing content with the new .coverflow branch using
    // navigationPath.append(id) on selection
}
```

- [ ] **Step 7.3: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

---

## Task 8: Full test pass + bundle commit

**Files:** none modified in this task.

- [ ] **Step 8.1: Clear iCloud conflict files**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

- [ ] **Step 8.2: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: `** TEST SUCCEEDED **`. Allow up to 8 minutes. Retry once if first run flakes with no `error:` lines.

- [ ] **Step 8.3: Pre-commit sanity**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected:
- Untracked: 6 new Swift files under `Views/Library/` + 1 test file
- Modified: `LibraryView.swift`
- Pre-existing junk untouched

- [ ] **Step 8.4: Bundle commit**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Library/MediaTypeInfer.swift \
        ios/GameTracker/GameTracker/Views/Library/SpineTextureBuilder.swift \
        ios/GameTracker/GameTracker/Views/Library/CoverFlowCaseNode.swift \
        ios/GameTracker/GameTracker/Views/Library/CoverFlowScene.swift \
        ios/GameTracker/GameTracker/Views/Library/CoverFlowSceneView.swift \
        ios/GameTracker/GameTracker/Views/Library/CoverFlowView.swift \
        ios/GameTracker/GameTracker/Views/Library/LibraryView.swift \
        ios/GameTracker/GameTrackerTests/MediaTypeInferTests.swift
git commit -m "Add CoverFlow view mode to Library (3D game-case carousel)"
```

### 🛑 User checkpoint — Library CoverFlow

⌘R in Xcode (iPhone 17 sim). Open Library, tap the view-mode menu, pick CoverFlow.

1. **Renders** — the focused box sits front-facing in the center; side boxes are tilted away. Below the row, the focused game's title + platform appear.
2. **Swipe horizontally** — boxes slide; snap-to-center on release; the title/platform label updates.
3. **Flick** (fast swipe) — the row advances an extra step beyond where you released.
4. **Tap a side box** — that box animates to center; you don't navigate yet.
5. **Tap the center box** — navigates to that game's detail screen. Back returns to CoverFlow with the same focused box.
6. **Disc vs cart shapes** — a PlayStation/Xbox game's box is wider than a Switch/3DS/Game Boy game's box.
7. **Spines** — visible on side boxes (tilted away), show the game title rotated 90° in white on the theme's accent color.
8. **Back covers** — visible when a side box is rotated far enough to show its `-Z` face. If the game has no real back cover, a dimmed copy of the front is shown.
9. **Empty filter / no games** — the existing `ContentUnavailableView` appears instead of the 3D scene.
10. **Theme switch (Settings → Appearance)** — spine accent color updates to match the new theme; the rest of the scene picks up the new color scheme.
11. **No regression on list / grid view modes**.

If anything misbehaves, report which step.

---

## Task 9: Push + open PR + wrap up

**Files:** none.

- [ ] **Step 9.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk.

- [ ] **Step 9.2: Push**

```bash
git push -u origin plan-4c-coverflow
```

- [ ] **Step 9.3: Mark plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-22-ios-coverflow.md
git add docs/superpowers/plans/2026-05-22-ios-coverflow.md
git commit -m "Mark Plan 4c (Library CoverFlow) complete"
git push
```

- [ ] **Step 9.4: Open PR**

```bash
gh pr create --base main --head plan-4c-coverflow \
  --title "Plan 4c: Library CoverFlow (3D game-case carousel)" \
  --body "$(cat <<'EOF'
## Summary

Adds a third Library view mode — **CoverFlow** — alongside the existing list and grid. Games render as real 3D textured boxes (\`SCNBox\`) in a horizontally scrolling row. Center box front-facing and full-size; side boxes rotated ~45° away with perspective. Below the row, the focused game's title + platform are shown.

### Architecture

SceneKit scene embedded in SwiftUI via \`UIViewRepresentable\`. SpriteKit generates the spine textures (title rotated 90° on theme-accent background). The existing \`@Query\` filteredGames array, sort, search, and platform filter all apply to CoverFlow — same data, different presentation.

### Per-box rendering

- 6 face materials: front cover, back cover, two spines (generated SpriteKit textures), top + bottom (dark color).
- Disc-format games (PlayStation/Xbox/Wii/GameCube/etc.) → DVD-case proportions.
- Cart-format games (Switch/3DS/DS/GBA/Game Boy/SNES/N64/Vita) → narrower cart-box proportions.
- Platform-to-media inference is a pure function with ~15 unit tests.

### Interaction

- Pan to scroll. Snap-to-center on release. Flick (>1500 pt/s) adds momentum.
- Tap side box → animate to center. Tap focused box → navigate to game detail.
- Visible-window cap of 7 boxes (center ± 3); cover textures load async; off-window boxes are removed.

## Test plan

- [x] \`xcodebuild test\` — full suite passes including new \`MediaTypeInferTests\`
- [x] Manual checkpoint: renders + swipe + flick + tap + media-type shape + spines + back covers + empty filter + theme switch
- [x] No regression on list / grid modes

## Not in scope (Plan 4d+)

- CoverFlow on Items / Completions (Library only).
- Reflection on the floor below cases.
- Animated "open the case" interaction.
- Platform-colored spines (uses theme accent universally).
- Custom GameCube mini-disc geometry.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [ ] Every referenced symbol exists: `MediaType`, `MediaTypeInfer.infer`, `SpineTextureBuilder.makeSpine`, `CoverFlowCaseNode.make`, `CoverFlowScene` (init / update / snap / index(of:) / game(at:)), `CoverFlowSceneView`, `CoverFlowView`, `Theme`, `ImagesAPI.Face`, `ImagesAPI.downloadCover(gameServerId:face:size:)`. (Symbols defined in Tasks 1, 2, 3, 4, 5, 6 or pre-existed.)
- [ ] `LibraryView.ViewMode` has exactly 3 cases after Task 7. Each case has a corresponding `systemImage`.
- [ ] `CoverFlowSceneView`'s `Binding<Int>` for `focusedIndex` is wired from `CoverFlowView`'s `@State`. Coordinator writes back to it via `parent.focusedIndex = ...` after re-syncing the parent reference in `updateUIView`.
- [ ] Box face indices `[0=front, 1=right, 2=back, 3=left, 4=top, 5=bottom]` match Apple's SCNBox material order — confirmed in Task 3.
- [ ] No "TBD" / "implement later" anywhere.
- [ ] Pre-existing junk explicitly named in Task 0 — won't be staged in Task 8's commit.
