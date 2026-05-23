# iOS Library Invaders Mini-Game Implementation Plan (Plan 4d)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an endless Space-Invaders-style mini-game launched from the Library tab, where the invaders are the user's own game covers and the player drags a pixel cannon to shoot them down.

**Architecture:** SpriteKit `SKScene` embedded in SwiftUI via `SpriteView`. SwiftUI HUD overlay sits on top for score / wave / best / game-over panel. Pure-function helpers (`CollisionMath`, `WaveGenerator`) live outside the scene for unit testing. Cover textures load async from the existing `ImagesAPI` thumb cache with a 64-image LRU cap.

**Tech Stack:** Swift 5.10+, SwiftUI, SpriteKit, AVFoundation (audio session config), SwiftData (existing). No new server endpoints, no new packages.

**Predecessors:** Plans 3a–3e + 4a + 4b + 4c complete. Spec: [`docs/superpowers/specs/2026-05-23-ios-invaders-minigame-design.md`](../specs/2026-05-23-ios-invaders-minigame-design.md) committed at `4f013a5` on branch `plan-4d-invaders`.

**Execution rhythm:** Single bundle commit at end of Task 12, one user QA checkpoint, matching Plans 4a/4b/4c. The mini-game becomes reachable only via the Library toolbar button added in Task 11, so earlier tasks accumulate dark code that's only visible at integration time.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-4d-invaders`, branched off `main` (Plan 4c merged at `9a12a8e`). Spec commit (`4f013a5`) sits on top.
- **Pre-existing changes to leave alone:** `js/completions.js`, `scripts/generate-thumbnails 2.php`, `tests/v2/* 2.sh`, `ios/GameTracker/GameTrackerTests/Helpers 2/`.
- **iCloud Drive Swift conflict files:** clear before each test pass: `find ios/GameTracker -name "* [0-9].swift" -print -delete`.

---

## File structure

### New iOS files

```
ios/GameTracker/GameTracker/Views/Invaders/
  CollisionMath.swift              — pure axis-aligned box-overlap helper
  WaveGenerator.swift              — pure (Int) → WaveConfig
  CoverTextureLoader.swift         — async UIImage fetch + LRU cap of 64
  PlayerCannonNode.swift           — pixel cannon SKSpriteNode
  InvaderNode.swift                — cover-textured SKSpriteNode
  BulletNode.swift                 — small bullet SKSpriteNode (player + invader kinds)
  InvadersScene.swift              — SKScene: game loop, collisions, lifecycle (+ InvadersSceneDelegate protocol)
  InvadersHUD.swift                — SwiftUI overlay: score / wave / best / game-over panel
  InvadersGameView.swift           — SwiftUI host: SpriteView + HUD + Coordinator

ios/GameTracker/GameTracker/Resources/Sounds/Invaders/
  invaders_shoot.wav
  invaders_hit.wav
  invaders_invader_shoot.wav
  invaders_death.wav

ios/GameTracker/GameTrackerTests/
  WaveGeneratorTests.swift
  InvadersCollisionTests.swift
```

### Modified iOS files

| File | Change |
|---|---|
| `Views/Library/LibraryView.swift` | Add `gamecontroller.fill` leading-toolbar button (hidden when library is empty), `@State showInvaders`, `.fullScreenCover` presenting `InvadersGameView`. |

### Untouched

All other tabs, models, sync, networking, the CoverFlow code, themes, server code.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-23-ios-invaders-minigame.md` (this file)

- [ ] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current      # → plan-4d-invaders
git log --oneline -3           # spec commit on top of 4c merge
git status --short             # only pre-existing junk
```

Expected: branch is `plan-4d-invaders`; spec commit (`4f013a5`) sits on top of the 4c merge (`9a12a8e`).

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
git add docs/superpowers/plans/2026-05-23-ios-invaders-minigame.md
git commit -m "Add Plan 4d (Library Invaders mini-game) implementation plan"
```

---

## Task 1: `CollisionMath` (TDD)

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/CollisionMath.swift`
- Create: `ios/GameTracker/GameTrackerTests/InvadersCollisionTests.swift`

Pure helper. Lives outside the scene so it's unit-testable without SpriteKit.

- [ ] **Step 1.1: Write the failing tests**

Write `ios/GameTracker/GameTrackerTests/InvadersCollisionTests.swift`:

```swift
import XCTest
import CoreGraphics
@testable import GameTracker

final class InvadersCollisionTests: XCTestCase {

    func test_identical_rects_overlap() {
        let r = CGRect(x: 10, y: 20, width: 40, height: 30)
        XCTAssertTrue(CollisionMath.rectsOverlap(r, r))
    }

    func test_edge_touching_rects_do_not_overlap() {
        let a = CGRect(x: 0, y: 0, width: 10, height: 10)
        let b = CGRect(x: 10, y: 0, width: 10, height: 10)
        XCTAssertFalse(CollisionMath.rectsOverlap(a, b))
    }

    func test_disjoint_rects_do_not_overlap() {
        let a = CGRect(x: 0, y: 0, width: 10, height: 10)
        let b = CGRect(x: 100, y: 100, width: 10, height: 10)
        XCTAssertFalse(CollisionMath.rectsOverlap(a, b))
    }

    func test_partially_overlapping_rects_overlap() {
        let a = CGRect(x: 0, y: 0, width: 20, height: 20)
        let b = CGRect(x: 10, y: 10, width: 20, height: 20)
        XCTAssertTrue(CollisionMath.rectsOverlap(a, b))
    }

    func test_nested_rects_overlap() {
        let outer = CGRect(x: 0, y: 0, width: 100, height: 100)
        let inner = CGRect(x: 40, y: 40, width: 10, height: 10)
        XCTAssertTrue(CollisionMath.rectsOverlap(outer, inner))
    }
}
```

- [ ] **Step 1.2: Confirm build-for-testing fails**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build-for-testing 2>&1 \
  | grep -E "BUILD FAILED|error:" | head -3
```

Expected: BUILD FAILED — `CollisionMath` not found.

- [ ] **Step 1.3: Implement `CollisionMath.swift`**

Write `ios/GameTracker/GameTracker/Views/Invaders/CollisionMath.swift`:

```swift
import CoreGraphics

/// Pure axis-aligned bounding-box overlap test, extracted from
/// InvadersScene so the collision logic can be unit-tested without
/// mounting a SpriteKit scene. Strict — rectangles that just touch
/// on an edge are considered disjoint.
enum CollisionMath {
    static func rectsOverlap(_ a: CGRect, _ b: CGRect) -> Bool {
        return a.maxX > b.minX &&
               a.minX < b.maxX &&
               a.maxY > b.minY &&
               a.minY < b.maxY
    }
}
```

- [ ] **Step 1.4: Run only the collision tests — expect pass**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' \
  test -only-testing:GameTrackerTests/InvadersCollisionTests 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -3
```

Expected: `** TEST SUCCEEDED **`. (No commit yet — bundled at Task 12.)

---

## Task 2: `WaveGenerator` (TDD)

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/WaveGenerator.swift`
- Create: `ios/GameTracker/GameTrackerTests/WaveGeneratorTests.swift`

Pure deterministic ramp formula. The 3-way cycle (`+row`, `×1.12 speed`, `×1.15 fireRate`) is the *entire* difficulty curve.

- [ ] **Step 2.1: Write the failing tests**

Write `ios/GameTracker/GameTrackerTests/WaveGeneratorTests.swift`:

```swift
import XCTest
import CoreGraphics
@testable import GameTracker

final class WaveGeneratorTests: XCTestCase {

    func test_wave_1_is_baseline() {
        let c = WaveGenerator.config(for: 1)
        XCTAssertEqual(c.rows, 4)
        XCTAssertEqual(c.cols, 6)
        XCTAssertEqual(c.speed, 30, accuracy: 0.001)
        XCTAssertEqual(c.fireRate, 0.4, accuracy: 0.001)
    }

    func test_wave_2_adds_a_row() {
        // w=2 → w%3==2 → +1 row
        XCTAssertEqual(WaveGenerator.config(for: 2).rows, 5)
    }

    func test_wave_3_bumps_speed() {
        // w=3 → w%3==0 → speed ×1.12
        let c = WaveGenerator.config(for: 3)
        XCTAssertEqual(c.rows, 5)
        XCTAssertEqual(c.speed, 30 * 1.12, accuracy: 0.001)
    }

    func test_wave_4_bumps_fire_rate() {
        // w=4 → w%3==1 → fireRate ×1.15
        XCTAssertEqual(WaveGenerator.config(for: 4).fireRate,
                       0.4 * 1.15,
                       accuracy: 0.001)
    }

    func test_rows_cap_at_six() {
        // Row bumps at w=2 (→5), w=5 (→6), then capped.
        XCTAssertEqual(WaveGenerator.config(for: 50).rows, 6)
    }

    func test_speed_strictly_increases_after_three_waves() {
        XCTAssertGreaterThan(WaveGenerator.config(for: 6).speed,
                             WaveGenerator.config(for: 3).speed)
    }

    func test_fire_rate_strictly_increases_after_three_waves() {
        XCTAssertGreaterThan(WaveGenerator.config(for: 7).fireRate,
                             WaveGenerator.config(for: 4).fireRate)
    }
}
```

- [ ] **Step 2.2: Confirm build-for-testing fails**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build-for-testing 2>&1 \
  | grep -E "BUILD FAILED|error:" | head -3
```

Expected: BUILD FAILED — `WaveGenerator` / `WaveConfig` not found.

- [ ] **Step 2.3: Implement `WaveGenerator.swift`**

Write `ios/GameTracker/GameTracker/Views/Invaders/WaveGenerator.swift`:

```swift
import CoreGraphics

struct WaveConfig: Equatable {
    let rows: Int
    let cols: Int
    let speed: CGFloat       // points / second
    let fireRate: CGFloat    // bullets / second across the whole grid
}

/// Deterministic difficulty ramp. From a baseline at wave 1, each
/// subsequent wave advances one parameter on a 3-way cycle:
///   w % 3 == 2 → +1 row (cap at 6)
///   w % 3 == 0 → speed ×1.12
///   w % 3 == 1 → fireRate ×1.15
enum WaveGenerator {

    private static let baselineRows: Int = 4
    private static let baselineSpeed: CGFloat = 30
    private static let baselineFire: CGFloat = 0.4

    static func config(for wave: Int) -> WaveConfig {
        var rows = baselineRows
        var speed = baselineSpeed
        var fireRate = baselineFire

        if wave >= 2 {
            for w in 2...wave {
                switch w % 3 {
                case 2: rows = min(rows + 1, 6)
                case 0: speed *= 1.12
                default: fireRate *= 1.15
                }
            }
        }

        return WaveConfig(rows: rows, cols: 6, speed: speed, fireRate: fireRate)
    }
}
```

- [ ] **Step 2.4: Run only the wave generator tests — expect pass**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' \
  test -only-testing:GameTrackerTests/WaveGeneratorTests 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -3
```

Expected: `** TEST SUCCEEDED **`.

---

## Task 3: `CoverTextureLoader`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/CoverTextureLoader.swift`

Async wrapper around the existing `ImagesAPI` thumb cache with an in-memory LRU cap of 64 textures. Game logic doesn't block on this — it requests covers, takes whatever's available.

- [ ] **Step 3.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/CoverTextureLoader.swift`:

```swift
import UIKit
import SwiftData

/// Loads cover thumbnails as `UIImage` for SpriteKit sprites, backed by
/// the existing ImagesAPI thumb cache. Memory-bounded: holds at most
/// `cap` unique textures keyed by `persistentModelID`, evicting in
/// least-recently-used order when full.
@MainActor
final class CoverTextureLoader {

    private let imagesAPI: ImagesAPI
    private let cap: Int
    private var cache: [PersistentIdentifier: UIImage] = [:]
    private var order: [PersistentIdentifier] = []

    init(imagesAPI: ImagesAPI, cap: Int = 64) {
        self.imagesAPI = imagesAPI
        self.cap = cap
    }

    /// Returns the cached or freshly-loaded thumbnail. Returns nil
    /// when the cover hasn't been synced to local disk yet — the
    /// caller should keep the placeholder texture in that case.
    func fetch(game: Game) async -> UIImage? {
        let id = game.persistentModelID
        if let cached = cache[id] {
            touch(id)
            return cached
        }
        guard let serverId = game.serverId else { return nil }
        guard let url = try? await imagesAPI.downloadCover(
            gameServerId: serverId, face: .front, size: .thumb
        ) else { return nil }
        guard let img = UIImage(contentsOfFile: url.path) else { return nil }
        insert(id, image: img)
        return img
    }

    private func touch(_ id: PersistentIdentifier) {
        order.removeAll { $0 == id }
        order.append(id)
    }

    private func insert(_ id: PersistentIdentifier, image: UIImage) {
        cache[id] = image
        order.append(id)
        while order.count > cap {
            let evicted = order.removeFirst()
            cache.removeValue(forKey: evicted)
        }
    }
}
```

- [ ] **Step 3.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

---

## Task 4: `PlayerCannonNode`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/PlayerCannonNode.swift`

Pixel-art cannon drawn procedurally — no asset bundling required.

- [ ] **Step 4.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/PlayerCannonNode.swift`:

```swift
import SpriteKit
import UIKit

/// Classic Space Invaders cannon silhouette at the bottom of the
/// scene. Texture is procedurally drawn — three filled rectangles for
/// base, body, and barrel — so the asset catalogue stays untouched.
final class PlayerCannonNode: SKSpriteNode {

    static let nominalSize = CGSize(width: 36, height: 22)

    init() {
        let texture = SKTexture(image: Self.makeTextureImage())
        super.init(texture: texture, color: .clear, size: Self.nominalSize)
        self.name = "player"
    }

    required init?(coder aDecoder: NSCoder) { fatalError("unsupported") }

    private static func makeTextureImage() -> UIImage {
        let size = nominalSize
        return UIGraphicsImageRenderer(size: size).image { ctx in
            ctx.cgContext.setFillColor(UIColor.label.cgColor)
            // Base (wide rectangle at the bottom of the texture)
            ctx.cgContext.fill(CGRect(x: 0,
                                       y: size.height - 8,
                                       width: size.width,
                                       height: 8))
            // Body (narrower rectangle above the base)
            ctx.cgContext.fill(CGRect(x: 8,
                                       y: size.height - 14,
                                       width: size.width - 16,
                                       height: 6))
            // Barrel (thin rectangle at the top centre)
            ctx.cgContext.fill(CGRect(x: size.width / 2 - 2,
                                       y: 0,
                                       width: 4,
                                       height: 8))
        }
    }
}
```

- [ ] **Step 4.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 5: `InvaderNode`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/InvaderNode.swift`

One invader = one game cover. Uses an immediate placeholder if no cover is loaded yet; exposes `applyCover(_:)` so the scene can re-texture it once the loader delivers.

- [ ] **Step 5.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/InvaderNode.swift`:

```swift
import SpriteKit
import SwiftData
import UIKit

/// Sprite representing one game in the invader grid. Sized to a
/// nominal 48pt height with aspect-correct width so portrait cover
/// art retains its proportions.
final class InvaderNode: SKSpriteNode {

    static let nominalHeight: CGFloat = 48

    /// Persistent ID of the originating game. Used by the scene to
    /// re-texture this invader if its real cover arrives mid-game.
    let gameID: PersistentIdentifier

    /// Score awarded when this invader is destroyed. Set by the
    /// scene at wave-start as `10 * waveNumber`.
    var pointValue: Int = 10

    init(gameID: PersistentIdentifier,
         cover: UIImage?,
         platformLabel: String) {
        self.gameID = gameID
        let img = cover ?? Self.makePlaceholder(letter: platformLabel)
        let texture = SKTexture(image: img)
        super.init(texture: texture,
                   color: .clear,
                   size: Self.sizeFor(imageSize: img.size))
        self.name = "invader"
    }

    required init?(coder aDecoder: NSCoder) { fatalError("unsupported") }

    /// Replace this invader's texture once its real cover arrives.
    func applyCover(_ image: UIImage) {
        self.texture = SKTexture(image: image)
        self.size = Self.sizeFor(imageSize: image.size)
    }

    private static func sizeFor(imageSize size: CGSize) -> CGSize {
        let aspect = size.width / max(size.height, 1)
        return CGSize(width: nominalHeight * aspect, height: nominalHeight)
    }

    private static func makePlaceholder(letter: String) -> UIImage {
        let size = CGSize(width: 36, height: nominalHeight)
        return UIGraphicsImageRenderer(size: size).image { ctx in
            // Grey block
            ctx.cgContext.setFillColor(UIColor(white: 0.35, alpha: 1).cgColor)
            ctx.cgContext.fill(CGRect(origin: .zero, size: size))
            // First letter of the platform, centred
            let attrs: [NSAttributedString.Key: Any] = [
                .font: UIFont.monospacedSystemFont(ofSize: 20, weight: .bold),
                .foregroundColor: UIColor.white,
            ]
            let s = NSString(string: letter.prefix(1).uppercased())
            let textSize = s.size(withAttributes: attrs)
            s.draw(at: CGPoint(x: (size.width - textSize.width) / 2,
                               y: (size.height - textSize.height) / 2),
                   withAttributes: attrs)
        }
    }
}
```

- [ ] **Step 5.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 6: `BulletNode`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/BulletNode.swift`

Tiny rectangle. Two kinds (player vs invader) differ only by colour and Y-velocity sign.

- [ ] **Step 6.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/BulletNode.swift`:

```swift
import SpriteKit
import UIKit

final class BulletNode: SKSpriteNode {

    enum Kind { case player, invader }

    let kind: Kind
    let velocity: CGVector

    init(kind: Kind) {
        self.kind = kind
        let speed: CGFloat = (kind == .player) ? 500 : 250
        self.velocity = CGVector(dx: 0, dy: (kind == .player) ? speed : -speed)
        let texture = SKTexture(image: Self.makeTextureImage(kind: kind))
        super.init(texture: texture,
                   color: .clear,
                   size: CGSize(width: 2, height: 8))
        self.name = "bullet"
    }

    required init?(coder aDecoder: NSCoder) { fatalError("unsupported") }

    private static func makeTextureImage(kind: Kind) -> UIImage {
        let size = CGSize(width: 2, height: 8)
        return UIGraphicsImageRenderer(size: size).image { ctx in
            let color: UIColor = (kind == .player) ? .label : .systemRed
            ctx.cgContext.setFillColor(color.cgColor)
            ctx.cgContext.fill(CGRect(origin: .zero, size: size))
        }
    }
}
```

- [ ] **Step 6.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 7: `InvadersScene` (+ delegate protocol)

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/InvadersScene.swift`

The whole game loop lives here. Anchor point is bottom-centre so positions are intuitive (y > 0 = above the cannon). The delegate protocol is co-located rather than in its own file because it's three lines and only used by this scene.

Spec sections this implements: §3 (architecture), §4 (game loop), §8 (game over), §9 (reduce motion), §10 (edge cases — pause via `isPaused`, dt cap).

- [ ] **Step 7.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/InvadersScene.swift`:

```swift
import SpriteKit
import SwiftData
import UIKit

protocol InvadersSceneDelegate: AnyObject {
    func scoreDidChange(to score: Int)
    func waveDidStart(_ wave: Int)
    func gameDidEnd(finalScore: Int)
}

/// SpriteKit scene driving the Invaders mini-game. Owns the player,
/// the invader grid, in-flight bullets, and the per-frame update
/// loop. Scene origin is bottom-centre (`anchorPoint = (0.5, 0)`)
/// so positive Y is "up" and X is symmetrical around 0.
///
/// Sound files (`invaders_*.wav`) are looked up by name via
/// `SKAction.playSoundFileNamed` — they live in the app bundle and
/// are bundled by the synchronized Xcode group. If the file is
/// missing, SpriteKit logs a warning and continues silently.
final class InvadersScene: SKScene {

    // MARK: - Public surface

    weak var gameDelegate: InvadersSceneDelegate?

    private(set) var score: Int = 0 {
        didSet { gameDelegate?.scoreDidChange(to: score) }
    }
    private(set) var waveNumber: Int = 0

    func configure(games: [Game]) {
        self.games = games
    }

    /// Late-binding cover texture from `CoverTextureLoader`.
    func applyCover(_ image: UIImage, for gameID: PersistentIdentifier) {
        for invader in invaders where invader.gameID == gameID {
            invader.applyCover(image)
        }
    }

    // MARK: - State

    private var player: PlayerCannonNode!
    private var invaders: [InvaderNode] = []
    private var bullets: [BulletNode] = []
    private var games: [Game] = []
    private var isGameOver = false

    private var gridDirection: CGFloat = 1     // +1 right, -1 left
    private var currentSpeed: CGFloat = 30
    private var currentFireRate: CGFloat = 0.4
    private var lastFireTime: TimeInterval = 0
    private var lastUpdate: TimeInterval = 0

    private let reduceMotion = UIAccessibility.isReduceMotionEnabled

    // MARK: - Lifecycle

    override func didMove(to view: SKView) {
        super.didMove(to: view)
        backgroundColor = .clear
        anchorPoint = CGPoint(x: 0.5, y: 0)

        player = PlayerCannonNode()
        player.position = CGPoint(x: 0, y: 60)
        addChild(player)

        startNextWave()
    }

    // MARK: - Touch handling

    override func touchesBegan(_ touches: Set<UITouch>, with event: UIEvent?) {
        moveTo(touch: touches.first)
    }

    override func touchesMoved(_ touches: Set<UITouch>, with event: UIEvent?) {
        moveTo(touch: touches.first)
    }

    private func moveTo(touch: UITouch?) {
        guard let touch, let view else { return }
        let p = touch.location(in: view)
        let scenePoint = convertPoint(fromView: p)
        let half = size.width / 2 - player.size.width / 2
        player.position.x = max(-half, min(half, scenePoint.x))
    }

    // MARK: - Game loop

    override func update(_ currentTime: TimeInterval) {
        guard !isGameOver else { return }
        if lastUpdate == 0 { lastUpdate = currentTime }
        // Cap dt so a foreground transition doesn't teleport the grid.
        let dt = min(currentTime - lastUpdate, 1.0 / 30)
        lastUpdate = currentTime
        guard dt > 0 else { return }

        advanceInvaderGrid(dt: dt)
        moveBullets(dt: dt)
        resolveCollisions()
        rollInvaderFire(dt: dt)
        rollPlayerAutoFire(now: currentTime)

        if invaders.isEmpty { startNextWave() }
    }

    private func advanceInvaderGrid(dt: TimeInterval) {
        guard !invaders.isEmpty else { return }
        let dx = currentSpeed * CGFloat(dt) * gridDirection
        for inv in invaders { inv.position.x += dx }

        let halfWidth = size.width / 2 - 20
        let maxX = invaders.map { $0.position.x + $0.size.width / 2 }.max() ?? 0
        let minX = invaders.map { $0.position.x - $0.size.width / 2 }.min() ?? 0

        if maxX > halfWidth || minX < -halfWidth {
            gridDirection *= -1
            let rowHeight: CGFloat = 40
            for inv in invaders { inv.position.y -= rowHeight }
        }
    }

    private func moveBullets(dt: TimeInterval) {
        for b in bullets {
            b.position.x += b.velocity.dx * CGFloat(dt)
            b.position.y += b.velocity.dy * CGFloat(dt)
        }
        let bounds = CGRect(x: -size.width / 2,
                             y: 0,
                             width: size.width,
                             height: size.height).insetBy(dx: -20, dy: -20)
        let offscreen = bullets.filter { !bounds.contains($0.position) }
        for b in offscreen {
            b.removeFromParent()
            bullets.removeAll { $0 === b }
        }
    }

    private func resolveCollisions() {
        var killedInvaders: [InvaderNode] = []
        var consumedBullets: [BulletNode] = []

        // Player bullets vs invaders
        for b in bullets where b.kind == .player {
            for inv in invaders where !killedInvaders.contains(where: { $0 === inv }) {
                if CollisionMath.rectsOverlap(b.frame, inv.frame) {
                    killedInvaders.append(inv)
                    consumedBullets.append(b)
                    score += inv.pointValue
                    run(SKAction.playSoundFileNamed("invaders_hit.wav",
                                                     waitForCompletion: false))
                    break
                }
            }
        }

        // Invader bullets vs player
        for b in bullets where b.kind == .invader {
            if CollisionMath.rectsOverlap(b.frame, player.frame) {
                consumedBullets.append(b)
                endGame()
                break
            }
        }

        // Invaders reaching the floor (player Y level)
        let playerTop = player.frame.maxY
        if !isGameOver && invaders.contains(where: { $0.frame.minY <= playerTop }) {
            endGame()
        }

        for inv in killedInvaders {
            inv.removeFromParent()
            invaders.removeAll { $0 === inv }
        }
        for b in consumedBullets {
            b.removeFromParent()
            bullets.removeAll { $0 === b }
        }
    }

    private func rollInvaderFire(dt: TimeInterval) {
        let probability = min(1, Double(currentFireRate) * dt)
        guard Double.random(in: 0..<1) < probability else { return }

        // Bucket invaders by approximate column (40pt buckets), pick
        // the lowest one in each column, then choose a random shooter
        // from that "front row" set.
        var lowestByColumn: [Int: InvaderNode] = [:]
        for inv in invaders {
            let bucket = Int((inv.position.x + 1000) / 40)
            if let existing = lowestByColumn[bucket] {
                if inv.position.y < existing.position.y {
                    lowestByColumn[bucket] = inv
                }
            } else {
                lowestByColumn[bucket] = inv
            }
        }
        guard let shooter = lowestByColumn.values.randomElement() else { return }

        let bullet = BulletNode(kind: .invader)
        bullet.position = CGPoint(x: shooter.position.x,
                                   y: shooter.frame.minY - 4)
        addChild(bullet)
        bullets.append(bullet)
        run(SKAction.playSoundFileNamed("invaders_invader_shoot.wav",
                                         waitForCompletion: false))
    }

    private func rollPlayerAutoFire(now: TimeInterval) {
        if now - lastFireTime < 0.35 { return }
        lastFireTime = now
        let bullet = BulletNode(kind: .player)
        bullet.position = CGPoint(x: player.position.x,
                                   y: player.frame.maxY + 4)
        addChild(bullet)
        bullets.append(bullet)
        run(SKAction.playSoundFileNamed("invaders_shoot.wav",
                                         waitForCompletion: false))
    }

    // MARK: - Wave start

    private func startNextWave() {
        waveNumber += 1
        let cfg = WaveGenerator.config(for: waveNumber)
        currentSpeed = reduceMotion ? cfg.speed * 0.5 : cfg.speed
        currentFireRate = cfg.fireRate
        gridDirection = 1

        let spacingX: CGFloat = 50
        let spacingY: CGFloat = 50
        let topY = size.height - 100
        let startX = -spacingX * CGFloat(cfg.cols - 1) / 2

        for r in 0..<cfg.rows {
            for c in 0..<cfg.cols {
                guard let game = games.randomElement() else { continue }
                let invader = InvaderNode(gameID: game.persistentModelID,
                                          cover: nil,
                                          platformLabel: game.platform)
                invader.pointValue = 10 * waveNumber
                let finalPos = CGPoint(x: startX + CGFloat(c) * spacingX,
                                        y: topY - CGFloat(r) * spacingY)
                if reduceMotion {
                    invader.position = finalPos
                } else {
                    invader.position = CGPoint(x: finalPos.x, y: finalPos.y + 100)
                    invader.run(SKAction.move(to: finalPos, duration: 0.4))
                }
                addChild(invader)
                invaders.append(invader)
            }
        }
        gameDelegate?.waveDidStart(waveNumber)
    }

    // MARK: - End

    private func endGame() {
        guard !isGameOver else { return }
        isGameOver = true
        run(SKAction.playSoundFileNamed("invaders_death.wav",
                                         waitForCompletion: false))
        gameDelegate?.gameDidEnd(finalScore: score)
        isPaused = true
    }
}
```

- [ ] **Step 7.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 8: `InvadersHUD`

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/InvadersHUD.swift`

SwiftUI overlay layered on top of the SpriteView. Score / wave / best across the top; close button top-left; game-over panel centred.

- [ ] **Step 8.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/InvadersHUD.swift`:

```swift
import SwiftUI

struct InvadersHUD: View {

    let score: Int
    let wave: Int
    let bestScore: Int
    let gameOver: Bool
    let isNewBest: Bool
    let onPlayAgain: () -> Void
    let onClose: () -> Void

    var body: some View {
        ZStack {
            topBar
            if gameOver { gameOverPanel }
        }
        .animation(.easeInOut(duration: 0.3), value: gameOver)
    }

    private var topBar: some View {
        VStack {
            HStack(alignment: .top) {
                Button(action: onClose) {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title2)
                        .symbolRenderingMode(.hierarchical)
                }
                .accessibilityLabel("Close")
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    Text("SCORE")
                        .font(.caption.bold().monospaced())
                        .foregroundStyle(.secondary)
                    Text("\(score)")
                        .font(.title2.bold().monospaced())
                }
            }
            HStack {
                Text("WAVE \(wave)  •  BEST \(bestScore)")
                    .font(.caption.bold().monospaced())
                    .foregroundStyle(.secondary)
                Spacer()
            }
            .padding(.top, 4)
            Spacer()
        }
        .padding(16)
    }

    private var gameOverPanel: some View {
        VStack(spacing: 12) {
            if isNewBest {
                Text("NEW BEST")
                    .font(.caption.bold().monospaced())
                    .foregroundStyle(.yellow)
            }
            Text("\(score)")
                .font(.system(size: 60, weight: .bold, design: .monospaced))
            Text("BEST  \(bestScore)")
                .font(.callout.monospaced())
                .foregroundStyle(.secondary)
            HStack(spacing: 16) {
                Button("Play Again", action: onPlayAgain)
                    .buttonStyle(.borderedProminent)
                Button("Close", action: onClose)
                    .buttonStyle(.bordered)
            }
            .padding(.top, 8)
        }
        .padding(24)
        .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 16))
        .transition(.opacity.combined(with: .scale))
    }
}
```

- [ ] **Step 8.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 9: `InvadersGameView` (SwiftUI host + Coordinator)

**Files:**
- Create: `ios/GameTracker/GameTracker/Views/Invaders/InvadersGameView.swift`

Owns the `Coordinator` (`@Observable`), drives the SpriteView, embeds the HUD, manages `@AppStorage` best score, kicks off cover preloading, and pauses on backgrounding.

- [ ] **Step 9.1: Implement**

Write `ios/GameTracker/GameTracker/Views/Invaders/InvadersGameView.swift`:

```swift
import SwiftUI
import SpriteKit
import SwiftData
import AVFoundation

struct InvadersGameView: View {

    let games: [Game]
    let imagesAPI: ImagesAPI
    let onDismiss: () -> Void

    @State private var coordinator = Coordinator()
    @AppStorage("invadersBestScore") private var bestScore: Int = 0
    @Environment(\.scenePhase) private var scenePhase

    var body: some View {
        GeometryReader { geo in
            ZStack {
                Color(.systemBackground).ignoresSafeArea()
                SpriteView(scene: coordinator.scene(for: geo.size, games: games))
                    .ignoresSafeArea()
                    .accessibilityHidden(true)
                InvadersHUD(score: coordinator.score,
                            wave: coordinator.waveNumber,
                            bestScore: bestScore,
                            gameOver: coordinator.gameOver,
                            isNewBest: coordinator.finalScore > bestScore,
                            onPlayAgain: { coordinator.restart() },
                            onClose: onDismiss)
            }
            .task {
                configureAudioSession()
                await coordinator.prewarmCovers(games: games, imagesAPI: imagesAPI)
            }
            .onChange(of: scenePhase) { _, phase in
                coordinator.setPaused(phase != .active)
            }
            .onChange(of: coordinator.finalScore) { _, newValue in
                if newValue > bestScore { bestScore = newValue }
            }
        }
    }

    /// Ambient category: the device mute switch silences our SFX
    /// and the user's existing audio (music / podcast) continues
    /// mixing alongside.
    private func configureAudioSession() {
        try? AVAudioSession.sharedInstance().setCategory(.ambient, mode: .default)
        try? AVAudioSession.sharedInstance().setActive(true)
    }

    // MARK: - Coordinator

    @Observable
    @MainActor
    final class Coordinator: InvadersSceneDelegate {

        private var _scene: InvadersScene?
        private var loader: CoverTextureLoader?
        private var games: [Game] = []
        private var sceneSize: CGSize = .zero

        var score: Int = 0
        var waveNumber: Int = 1
        var gameOver: Bool = false
        var finalScore: Int = 0

        func scene(for size: CGSize, games: [Game]) -> SKScene {
            self.sceneSize = size
            if let existing = _scene { return existing }
            // Snapshot the games array on first call only — spec §6/§10:
            // sync writes mid-run must not change the active pool.
            self.games = games
            return makeScene()
        }

        private func makeScene() -> InvadersScene {
            let scene = InvadersScene(size: sceneSize)
            scene.scaleMode = .resizeFill
            scene.configure(games: games)
            scene.gameDelegate = self
            _scene = scene
            return scene
        }

        func prewarmCovers(games: [Game], imagesAPI: ImagesAPI) async {
            let loader = CoverTextureLoader(imagesAPI: imagesAPI)
            self.loader = loader
            for g in games.prefix(64).shuffled() {
                if let img = await loader.fetch(game: g) {
                    _scene?.applyCover(img, for: g.persistentModelID)
                }
            }
        }

        func setPaused(_ paused: Bool) {
            _scene?.isPaused = paused
        }

        func restart() {
            score = 0
            waveNumber = 1
            gameOver = false
            finalScore = 0
            // Capture the host SKView from the current scene before
            // makeScene() clobbers _scene with the new instance.
            let hostView = _scene?.view
            let fresh = makeScene()
            hostView?.presentScene(fresh)
        }

        // MARK: InvadersSceneDelegate

        func scoreDidChange(to score: Int) { self.score = score }
        func waveDidStart(_ wave: Int) { self.waveNumber = wave }
        func gameDidEnd(finalScore: Int) {
            self.finalScore = finalScore
            self.gameOver = true
        }
    }
}
```

- [ ] **Step 9.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 10: Wire into `LibraryView`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Library/LibraryView.swift`

Add `@State showInvaders`, the leading-toolbar button (hidden when `allGames.isEmpty`), and the `.fullScreenCover` presenting `InvadersGameView`.

- [ ] **Step 10.1: Add the state property**

Find the existing `@State` declarations in `LibraryView.swift` (around the platform-filter / navigation-path block) and add `showInvaders` alongside:

```swift
@State private var navigationPath = NavigationPath()
```

Becomes:

```swift
@State private var navigationPath = NavigationPath()
@State private var showInvaders = false
```

- [ ] **Step 10.2: Add the toolbar button**

In `LibraryView.swift`, find the existing `toolbarContent` `@ToolbarContentBuilder` and insert a new `ToolbarItem` at the leading edge (before the existing trailing items). The current block looks like:

```swift
@ToolbarContentBuilder
private var toolbarContent: some ToolbarContent {
    ToolbarItem(placement: .navigationBarTrailing) {
        Button { showAdd = true } label: { Image(systemName: "plus") }
    }
    ToolbarItem(placement: .navigationBarTrailing) {
        Menu {
            // ...
        } label: {
            Image(systemName: "ellipsis.circle")
        }
    }
}
```

Replace with (note the new leading item):

```swift
@ToolbarContentBuilder
private var toolbarContent: some ToolbarContent {
    if !allGames.isEmpty {
        ToolbarItem(placement: .navigationBarLeading) {
            Button { showInvaders = true } label: {
                Image(systemName: "gamecontroller.fill")
            }
            .accessibilityLabel("Play Invaders")
        }
    }
    ToolbarItem(placement: .navigationBarTrailing) {
        Button { showAdd = true } label: { Image(systemName: "plus") }
    }
    ToolbarItem(placement: .navigationBarTrailing) {
        Menu {
            // ... (unchanged — leave existing Picker / Filter contents alone)
        } label: {
            Image(systemName: "ellipsis.circle")
        }
    }
}
```

When transcribing, leave the existing Menu's inner body untouched — only the surrounding structure changes (add the leading-side `if !allGames.isEmpty { … }` block).

- [ ] **Step 10.3: Add the fullScreenCover**

Find the existing sheet/cover modifiers on the `NavigationStack` (sheet for showAdd, etc.) — around lines that look like:

```swift
.sheet(isPresented: $showFilter) {
    PlatformFilterSheet(selected: $platformFilter)
}
.task { try? await syncEngine.runOnce() }
```

Add the new `.fullScreenCover` modifier alongside (e.g. immediately after the platform-filter sheet):

```swift
.fullScreenCover(isPresented: $showInvaders) {
    InvadersGameView(games: filteredGames,
                     imagesAPI: imagesAPI,
                     onDismiss: { showInvaders = false })
}
```

- [ ] **Step 10.4: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

---

## Task 11: Source + bundle sound effects

**Files:**
- Create: `ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_shoot.wav`
- Create: `ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_hit.wav`
- Create: `ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_invader_shoot.wav`
- Create: `ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_death.wav`
- Modify: `ios/GameTracker/GameTracker/Views/Invaders/InvadersScene.swift` (attribution comment block)

Four short CC0 `.wav` files. Spec section 7. The synchronized Xcode group auto-includes anything dropped under `GameTracker/Resources/`.

- [ ] **Step 11.1: Confirm the Resources directory exists**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
mkdir -p ios/GameTracker/GameTracker/Resources/Sounds/Invaders
ls ios/GameTracker/GameTracker/Resources/
```

- [ ] **Step 11.2: Find four short CC0 .wav files**

Use WebSearch and WebFetch to locate CC0-licensed short retro-arcade sound effects on freesound.org (filter: License = "Creative Commons 0"). Searches that work well:

- `freesound.org "laser shoot" CC0 short`
- `freesound.org "8 bit explosion" CC0 short`
- `freesound.org "retro hit" CC0 short`
- `freesound.org "arcade death" CC0 short`

For each, pick a clip under 1 second. Download the .wav, rename to the target filename, and save under `ios/GameTracker/GameTracker/Resources/Sounds/Invaders/`.

Suggested duration / character:
- `invaders_shoot.wav` — ~50ms blip / laser
- `invaders_hit.wav` — ~80ms impact / zap
- `invaders_invader_shoot.wav` — distinct from player shoot; ~50ms low-pitched blip
- `invaders_death.wav` — ~250ms descending pop / 8-bit "doomed" sound

CC0 means no attribution required, but record the freesound IDs and authors in `InvadersScene.swift` as a comment for traceability.

- [ ] **Step 11.3: Verify files are in place**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
ls -la ios/GameTracker/GameTracker/Resources/Sounds/Invaders/
# Expected: four .wav files, each well under 100KB
```

- [ ] **Step 11.4: Add attribution comment to `InvadersScene.swift`**

Insert a comment block at the top of `InvadersScene.swift` (just below the imports), recording the source freesound IDs you used. Example shape:

```swift
import SpriteKit
import SwiftData
import UIKit

// MARK: - Sound asset attributions
// All clips are CC0 (no attribution required, recorded here for
// traceability and future replacement).
//
//   invaders_shoot.wav         — freesound.org/people/<author>/sounds/<id>/
//   invaders_hit.wav           — freesound.org/people/<author>/sounds/<id>/
//   invaders_invader_shoot.wav — freesound.org/people/<author>/sounds/<id>/
//   invaders_death.wav         — freesound.org/people/<author>/sounds/<id>/

protocol InvadersSceneDelegate: AnyObject { ... }
```

- [ ] **Step 11.5: Build check (verifies bundle inclusion compiles)**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**. (Audio playback itself is verified manually in Task 12's user QA — SpriteKit silently skips a missing file.)

---

## Task 12: Full test pass + bundle commit

**Files:** none modified in this task.

- [ ] **Step 12.1: Clear iCloud conflict files**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

- [ ] **Step 12.2: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: `** TEST SUCCEEDED **` (includes the new `WaveGeneratorTests` and `InvadersCollisionTests`).

- [ ] **Step 12.3: Pre-commit sanity**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected:
- Untracked: 9 new Swift files under `Views/Invaders/` + 2 test files + 4 .wav files
- Modified: `LibraryView.swift`
- Pre-existing junk untouched

- [ ] **Step 12.4: Bundle commit**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Views/Invaders/CollisionMath.swift \
        ios/GameTracker/GameTracker/Views/Invaders/WaveGenerator.swift \
        ios/GameTracker/GameTracker/Views/Invaders/CoverTextureLoader.swift \
        ios/GameTracker/GameTracker/Views/Invaders/PlayerCannonNode.swift \
        ios/GameTracker/GameTracker/Views/Invaders/InvaderNode.swift \
        ios/GameTracker/GameTracker/Views/Invaders/BulletNode.swift \
        ios/GameTracker/GameTracker/Views/Invaders/InvadersScene.swift \
        ios/GameTracker/GameTracker/Views/Invaders/InvadersHUD.swift \
        ios/GameTracker/GameTracker/Views/Invaders/InvadersGameView.swift \
        ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_shoot.wav \
        ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_hit.wav \
        ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_invader_shoot.wav \
        ios/GameTracker/GameTracker/Resources/Sounds/Invaders/invaders_death.wav \
        ios/GameTracker/GameTracker/Views/Library/LibraryView.swift \
        ios/GameTracker/GameTrackerTests/WaveGeneratorTests.swift \
        ios/GameTracker/GameTrackerTests/InvadersCollisionTests.swift
git commit -m "Add Library Invaders mini-game (SpriteKit, endless waves, your covers)"
```

### 🛑 User checkpoint — Library Invaders mini-game

⌘R in Xcode (iPhone 17 sim). Library tab → leading-side `gamecontroller.fill` button (visible only when you have ≥1 game). Tap it.

1. **Sheet opens full-screen** — pixel cannon at the bottom, a grid of invaders at the top, score / wave / best in the top HUD.
2. **Invaders are your covers** — within a wave or two, the placeholder grey squares are replaced with real game cover art.
3. **Drag** — the cannon slides left/right under your finger, clamped to screen edges.
4. **Auto-fire** — bullets shoot upward roughly every 0.35s without input.
5. **Hits** — when a bullet hits an invader, it disappears, the score jumps by `10 × waveNumber`, and you hear the hit sound.
6. **Invaders shoot back** — descending red bullets from the front row. Getting hit ends the run.
7. **Wave cleared** — after killing all invaders, a new wave drops in (animated fly-in unless Reduce Motion is on); difficulty bumps slightly.
8. **Floor reached** — if any invader's bottom edge reaches the cannon's top, the run ends.
9. **Game-over panel** — fades in over a frozen scene with the final score, BEST, and a "NEW BEST" badge if applicable. Play Again restarts at wave 1; Close dismisses.
10. **Background / foreground** — swipe up to the home screen, come back: invaders are frozen mid-step, then resume cleanly with no teleport.
11. **Sound** — four distinct SFX (player shoot, invader hit, invader shoot, death). Device mute switch silences them; your music continues underneath.
12. **No regression** on Library list / grid / CoverFlow modes; toolbar `+` and `…` menu still work normally.
13. **Empty library** — if you delete all your games, the gamecontroller button hides itself.

If anything misbehaves, report which step.

---

## Task 13: Push + open PR + wrap up

**Files:** none.

- [ ] **Step 13.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk.

- [ ] **Step 13.2: Push**

```bash
git push -u origin plan-4d-invaders
```

- [ ] **Step 13.3: Mark plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-23-ios-invaders-minigame.md
git add docs/superpowers/plans/2026-05-23-ios-invaders-minigame.md
git commit -m "Mark Plan 4d (Library Invaders mini-game) complete"
git push
```

- [ ] **Step 13.4: Open PR**

```bash
gh pr create --base main --head plan-4d-invaders \
  --title "Plan 4d: Library Invaders mini-game" \
  --body "$(cat <<'EOF'
## Summary

Adds an endless Space-Invaders-style mini-game launched from the Library tab. The **invaders are the user's own game covers** — the cover texture cached by the existing list/grid/CoverFlow modes is reused as SpriteKit sprite art. Drag the pixel cannon along the bottom; bullets auto-fire upward; descending covers shoot back; one hit ends the run.

### Architecture

- **SpriteKit** \`SKScene\` embedded in SwiftUI via \`SpriteView\`.
- SwiftUI HUD overlay for score / wave / best / game-over panel.
- Pure-function helpers (\`CollisionMath\`, \`WaveGenerator\`) with unit-test coverage.
- Cover textures loaded lazily from the existing \`ImagesAPI\` thumb cache, with a 64-image LRU cap.
- Best score persists locally via \`@AppStorage\` — no server work.

### Difficulty curve

Deterministic 3-way cycle from wave 2 onward: +1 row (cap 6), ×1.12 speed, ×1.15 fire rate. Wave 1 is the baseline (4 rows × 6 cols, 30 pt/s, 0.4 shots/sec).

### Accessibility

- Toolbar button has an explicit VoiceOver label.
- SpriteView is opted out of VoiceOver (arcade gameplay isn't meaningful to a screen reader); HUD labels remain accessible.
- Reduce Motion halves wave speed and skips the start-of-wave fly-in animation.

### Sound

Four short CC0 .wav files (player shoot, invader hit, invader shoot, player death). \`AVAudioSession\` ambient category — device mute respected, user's music continues underneath.

## Test plan

- [x] \`xcodebuild test\` — full suite passes including new \`WaveGeneratorTests\` (7 cases) and \`InvadersCollisionTests\` (5 cases)
- [x] Manual QA checkpoint on iPhone 17 simulator: drag, auto-fire, hit detection, invader fire, wave clear, game over, background/foreground pause, sound, theme switch, empty-library button hiding

## Not in scope (Plan 4e+)

- Online leaderboards / Game Center
- Cross-device high-score sync
- Power-ups, multi-shot, bonus UFO
- Background music
- In-game pause menu beyond auto-pause on backgrounding
- Mini-game from Items / Completions tabs

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [ ] Every referenced symbol exists: `CollisionMath.rectsOverlap`, `WaveGenerator.config(for:)`, `WaveConfig`, `CoverTextureLoader.fetch`, `PlayerCannonNode`, `InvaderNode.applyCover`, `InvaderNode.pointValue`, `InvaderNode.gameID`, `BulletNode.Kind`, `BulletNode.velocity`, `InvadersScene.configure`, `InvadersScene.applyCover`, `InvadersScene.gameDelegate`, `InvadersSceneDelegate` (3 methods), `InvadersHUD`, `InvadersGameView`, `InvadersGameView.Coordinator`. (All defined in Tasks 1–9.)
- [ ] `LibraryView` toolbar gains exactly one new leading item, hidden when `allGames.isEmpty`. Existing trailing items (plus, menu) are unchanged.
- [ ] `@AppStorage("invadersBestScore")` is used in `InvadersGameView` — same key used to read + write so the persisted value survives across runs.
- [ ] All sound calls use exact filenames matching files in `Resources/Sounds/` (`invaders_shoot.wav`, `invaders_hit.wav`, `invaders_invader_shoot.wav`, `invaders_death.wav`).
- [ ] No "TBD" / "implement later" anywhere.
- [ ] Pre-existing junk explicitly named in Task 0 — won't be staged in Task 12's commit.
- [ ] No server / API / database changes — Library Invaders is iOS-only.
