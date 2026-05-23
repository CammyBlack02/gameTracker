# iOS Library Invaders Mini-Game Design (Plan 4d)

**Status:** Approved 2026-05-23.

## Overview

Add an endless Space-Invaders-style mini-game launched from the Library tab, where the **invaders are the user's own game covers**. Players drag a pixel cannon along the bottom of the screen; bullets auto-fire upward; descending invaders (covers from the library) shoot back. One hit on the player ends the run. Difficulty escalates wave-by-wave. Best score is persisted locally per device.

Implemented in **SpriteKit**, embedded in SwiftUI via `SpriteView`. SpriteKit was already linked during Plan 4c, so no new framework dependency is introduced.

## Goals

- A self-contained "fun extra" that reuses the cover art the app already caches, so the mini-game feels personal to each user's library.
- Lightweight in scope: one SwiftUI sheet, one SpriteKit scene, no server changes, no migrations, no new API endpoints.
- Pure-function game logic (wave generator + collision helper) that is unit-testable without mounting a scene.
- Easy to delete or disable as one directory if it ever feels out of place.

## Non-goals (out of scope for 4d)

- **Online leaderboards / Game Center.** Best score lives in `@AppStorage` only.
- **Cross-device sync of high scores.** No server table, no sync work.
- **Background music / soundtrack.** Four short SFX only; no music bed.
- **Power-ups, multi-shot, shields, bonus UFO.** Pure base-rules Space Invaders.
- **Multiplayer / co-op.** Single-player only.
- **Pause menu beyond auto-pause on backgrounding.** No in-game pause button.
- **Settings for SFX volume / mute.** Honour the device mute switch via `AVAudioSession`'s ambient category; no in-game audio controls.
- **Tuning tools or debug UI.** Wave ramp is hard-coded.

## Section 1: Library integration

A new `gamecontroller.fill` button is added to the **leading** toolbar slot of `LibraryView` (the trailing slot already holds `+` and the `…` menu). Tapping it presents a **full-screen sheet** (`.fullScreenCover`) containing the mini-game.

The button is **hidden** when `allGames.isEmpty` — there are no covers to invade.

```swift
// LibraryView.swift toolbar additions
@State private var showInvaders = false

ToolbarItem(placement: .navigationBarLeading) {
    if !allGames.isEmpty {
        Button { showInvaders = true } label: {
            Image(systemName: "gamecontroller.fill")
        }
        .accessibilityLabel("Play Invaders")
    }
}

// ... and at the body level:
.fullScreenCover(isPresented: $showInvaders) {
    InvadersGameView(games: filteredGames, imagesAPI: imagesAPI)
}
```

`filteredGames` (already computed for list/grid) supplies the pool. The sheet captures an immutable snapshot of the array when it opens; we do **not** reactively re-fetch if SwiftData changes during a run.

## Section 2: Screen layout

The full-screen sheet contains a `ZStack`:

```
ZStack
├ SpriteView(scene: invadersScene)       (full-bleed, fills safe area)
└ InvadersHUD                            (overlay)
    ├ top-left:    "WAVE 03  •  BEST 12,400"
    ├ top-right:   "SCORE 3,140"
    ├ top-leading: xmark close button
    └ centre:      game-over panel (hidden during play)
```

The close button uses `xmark.circle.fill` in the top-left, padded for safe-area insets. Tapping it dismisses the sheet (and the scene tears down naturally).

The HUD layer is fully transparent during play; only the labels and close button are visible. During game-over, a semi-opaque rounded panel fades in over the centre with the score, "NEW BEST" badge (if applicable), "Play Again", and "Close" buttons.

## Section 3: Game architecture

```
InvadersGameView (SwiftUI)
 ├ owns: games snapshot, imagesAPI, @State currentScore, @State waveNumber, @State gameOver, @AppStorage bestScore
 ├ embeds: SpriteView(scene: InvadersScene)
 └ overlays: InvadersHUD

InvadersScene (SKScene)
 ├ owns: playerNode, [invaderNode], [bulletNode], waveGenerator
 ├ update(_:) – per-frame game loop (see Section 4)
 ├ touchesBegan / touchesMoved – player drag
 └ delegate callbacks → GameView (score changed, wave cleared, game over)

InvaderNode (SKSpriteNode)
 ├ created from a cover UIImage (or grey placeholder + first letter of platform)
 ├ sized to ~48pt tall, aspect-correct width
 └ stores: ref to the Game it represents (for kill scoring)

PlayerCannonNode (SKSpriteNode)
 ├ pixel-art cannon texture (24×16 pt, drawn procedurally from rectangles)
 └ x-position driven by drag gesture

BulletNode (SKSpriteNode)
 ├ tiny rectangle (2×8 pt), white
 ├ velocity = (0, +N) for player or (0, -N) for invader
 └ kind: .player | .invader

WaveGenerator (pure struct)
 ├ config(forWave: Int) → WaveConfig
 └ WaveConfig: rows, cols, speed, fireRate

CoverTextureLoader (helper)
 ├ async fetch(game) → UIImage via ImagesAPI thumb cache
 └ in-memory LRU cap of 64 textures
```

**Communication between scene and SwiftUI host** uses an `InvadersSceneDelegate` protocol so the scene doesn't import SwiftUI:

```swift
protocol InvadersSceneDelegate: AnyObject {
    func scoreDidChange(to score: Int)
    func waveDidStart(_ wave: Int)
    func gameDidEnd(finalScore: Int)
}
```

`InvadersGameView` conforms via a small `Coordinator`-style helper.

## Section 4: Game loop (`SKScene.update(_:)`)

Per-frame steps, executed in order:

1. **Compute `dt`** (delta time since last update; capped at 1/30s to stay sane after a foreground transition).
2. **Advance the invader grid.** The invader array is logically a grid, but each node has its own position. The grid as a whole moves `±gridSpeed * dt` horizontally. If any invader's x crosses the screen-edge margin, the grid reverses direction and every invader's y drops by one row-height (~40pt).
3. **Move bullets.** Each `BulletNode` advances along its velocity. Bullets that exit the scene's bounds are removed.
4. **Collisions** — axis-aligned bounding-box overlap, hand-rolled (no `SKPhysicsBody` — fewer side effects and easier to unit-test):
   - For each player bullet × invader: if overlap, remove both; `score += 10 * waveNumber`; play `invaders_hit.wav`; notify delegate.
   - For each invader bullet × player: if overlap, set `gameOver = true`; play `invaders_death.wav`; freeze the scene.
   - If `min(invader.y) <= playerNode.y + threshold`: set `gameOver = true` (the invaders reached the floor).
5. **Roll invader fire.** Each frame, with probability `min(1, waveFireRate * dt)`, pick a random **front-row** invader (the lowest one in each column) and spawn an invader bullet from its bottom centre.
6. **Player auto-fire.** Every `0.35s` (tracked via a `lastFireTime` accumulator), spawn a player bullet from the cannon's top centre.
7. **Check wave clear.** If the invader array is empty, increment `waveNumber`, ask `WaveGenerator.config(for: waveNumber)` for the next config, spawn it (fly-in animation: each invader slides down 100pt over 0.4s), play no sound (the next wave is its own moment).

The `dt` cap (step 1) prevents a "teleport step" when the app returns from background.

## Section 5: Wave generator

Pure deterministic struct, fully unit-testable.

```swift
struct WaveConfig {
    let rows: Int          // 4...6
    let cols: Int          // 6 always
    let speed: CGFloat     // points/sec
    let fireRate: CGFloat  // bullets/sec across the whole grid
}

struct WaveGenerator {
    static func config(for wave: Int) -> WaveConfig {
        // Wave 1 baseline
        var rows: Int = 4
        var speed: CGFloat = 30
        var fireRate: CGFloat = 0.4

        // For waves 2...N, ramp one parameter per wave on a 3-way cycle:
        //   wave % 3 == 2 → +1 row (cap at 6)
        //   wave % 3 == 0 → speed ×1.12
        //   wave % 3 == 1 → fireRate ×1.15
        for w in 2...max(wave, 2) {
            switch w % 3 {
            case 2: rows = min(rows + 1, 6)
            case 0: speed *= 1.12
            default: fireRate *= 1.15
            }
        }
        return WaveConfig(rows: rows, cols: 6, speed: speed, fireRate: fireRate)
    }
}
```

This is the **entire** difficulty curve. It is deterministic, easy to reason about, and trivial to test (`config(for: 1).rows == 4`, `config(for: 10).speed > config(for: 5).speed`, row cap at 6).

## Section 6: Cover texture loading

When the sheet opens, `InvadersGameView` kicks off a `Task` that pre-decodes covers:

```swift
.task {
    for game in games.prefix(64).shuffled() {
        if let img = await CoverTextureLoader.fetch(game: game, imagesAPI: imagesAPI) {
            scene.registerCover(img, for: game.persistentModelID)
        }
    }
}
```

Game logic doesn't block on this — the scene starts immediately and uses placeholder grey squares (with the game's platform first letter overlaid in white) until a texture lands. As covers arrive, the next-spawned invader picks one. This means the very first wave may render with a mix of placeholders and real covers; by wave 2 or 3 the pool is full.

If a game has no cached cover thumb at all (rare — only happens for newly-added games that haven't synced their cover), the placeholder grey-square texture is used permanently for that game during this run.

**Memory cap:** at most 64 unique `UIImage` textures held in memory; libraries larger than 64 sample 64 random games at sheet-open and cycle them across waves. This makes the working set bounded regardless of library size.

## Section 7: Sound effects

Four short CC0 `.wav` files bundled in the app under `Resources/Sounds/Invaders/`:

| File | Trigger | Notes |
|---|---|---|
| `invaders_shoot.wav` | Player bullet fires | ~50ms blip; played up to ~3×/sec |
| `invaders_hit.wav` | Player bullet kills an invader | ~80ms hit/zap |
| `invaders_invader_shoot.wav` | Invader fires | distinct from player shoot; ~50ms |
| `invaders_death.wav` | Player is hit / invaders reach the floor | ~250ms descending pop |

Played via `run(SKAction.playSoundFileNamed("…", waitForCompletion: false))` inside the relevant scene events.

**Audio session:** the scene configures `AVAudioSession` with `category = .ambient, mode = .default, options = []`. This means:
- The device mute switch silences the SFX (matches arcade-game expectations).
- The user's playing music / podcast continues uninterrupted; our SFX mix on top.

Asset sourcing is part of the implementation plan: the implementer will locate CC0 (or CC-BY with attribution noted in `About`) clips from freesound.org or similar and commit them with the code. Asset filenames + attributions are recorded inline as a comment in `InvadersScene.swift`.

## Section 8: Game over

When `gameDidEnd` fires:

1. SpriteKit scene freezes (the `isPaused` flag is set, so existing nodes stay where they are — frozen invaders mid-descent).
2. `InvadersGameView` flips `@State gameOver = true`.
3. The HUD overlay's game-over panel fades in (0.3s opacity transition):
   - **SCORE:** `X,XXX`
   - **BEST:** `Y,YYY` (with a "NEW BEST" badge if this run beat the stored value)
   - **Play Again** button — clears the scene, resets score/wave, starts a fresh wave 1
   - **Close** button — dismisses the sheet

If the run beat the stored best, `@AppStorage("invadersBestScore")` is updated immediately (before the panel fades in) so that subsequent runs see the new value.

## Section 9: Accessibility

- **Toolbar button:** `accessibilityLabel("Play Invaders")`.
- **VoiceOver:** the `SpriteView` is excluded from accessibility (`.accessibilityHidden(true)`) — arcade gameplay isn't meaningful to a screen reader. The HUD labels (score, wave, best) remain accessible.
- **Reduce Motion:** if `UIAccessibility.isReduceMotionEnabled` is `true`:
   - All `speed` values in `WaveConfig` are multiplied by 0.5.
   - The start-of-wave fly-in animation is skipped (invaders appear at final position).
- **Dynamic Type:** HUD score/wave/best labels use `font(.system(.body, design: .monospaced))` and respect Dynamic Type via SwiftUI defaults.
- **Colour:** cannon and bullets use `Color.primary`, so they read against both light and dark themed backgrounds.

## Section 10: Edge cases and failure modes

| Scenario | Behaviour |
|---|---|
| Library has 0 games | Toolbar button hidden. (No sheet entry point.) |
| Library has <8 games | Sample with replacement; some covers repeat across the wave grid. |
| Library has >64 games | Random 64-game subset chosen at sheet-open; that pool is reused for all waves of this run. |
| Cover image fetch fails | Grey placeholder square with white platform-first-letter overlay. Game continues. |
| App backgrounded mid-run | `scenePhase == .background` → `scene.isPaused = true`. On return to foreground, `isPaused = false`. The `dt` cap in update prevents a teleport step. |
| User taps Close mid-run | Sheet dismisses immediately; the in-progress run is discarded (not counted toward best). |
| User dies and taps Play Again | Same `games` snapshot is reused (no re-fetch); `scene` is reset to a fresh wave 1. |
| Sync writes mid-run change the library | Ignored. Game uses the snapshot captured at sheet-open. |

## Section 11: Testing strategy

**Unit-tested (pure logic):**

- `WaveGeneratorTests` — assertions on `config(for:)`:
  - Wave 1 = baseline (rows 4, speed 30, fire 0.4)
  - Row count caps at 6
  - Speed strictly increases between wave N and wave N+3
  - Fire rate strictly increases between wave N and wave N+3
- `InvadersCollisionTests` — a `static rectsOverlap(_:_:) -> Bool` helper extracted from `InvadersScene` and tested in isolation:
  - Identical rects overlap
  - Edge-touching rects don't overlap
  - Disjoint rects don't overlap
  - Nested rects overlap

**Not unit-tested (manual QA on simulator):**

- Drag-to-move feel
- Invader fire frequency at high waves
- Cover-load placeholder transition
- SFX cadence
- Game-over panel layout
- Background/foreground pause/resume

## File structure

### New iOS files

```
ios/GameTracker/GameTracker/Views/Invaders/
  InvadersGameView.swift           — SwiftUI host: SpriteView + HUD overlay + AppStorage
  InvadersScene.swift              — SKScene: update loop, collisions, scene lifecycle
  InvadersSceneDelegate.swift      — protocol bridging scene → SwiftUI host
  InvaderNode.swift                — SKSpriteNode wrapping a cover image
  PlayerCannonNode.swift           — SKSpriteNode for the pixel cannon
  BulletNode.swift                 — SKSpriteNode for player + invader bullets
  WaveGenerator.swift              — pure: (Int) → WaveConfig
  CollisionMath.swift              — pure: rectsOverlap helper
  CoverTextureLoader.swift         — async UIImage fetch with LRU cap
  InvadersHUD.swift                — SwiftUI overlay (score/wave/best/game-over)

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
| `Views/Library/LibraryView.swift` | Add leading-toolbar `gamecontroller.fill` button (hidden when library is empty), `@State showInvaders`, `.fullScreenCover` presentation. |

### Untouched

All other tabs, models, sync, networking, the CoverFlow code, themes, server code.

## Server / deploy impact

**None.** No API endpoints added, no database migrations, no PHP changes. The mini-game lives entirely in the iOS app.
