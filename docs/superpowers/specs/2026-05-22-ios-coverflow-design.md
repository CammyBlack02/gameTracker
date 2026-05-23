# iOS Library CoverFlow Design (Plan 4c)

**Status:** Approved 2026-05-22.

## Overview

Add a third view mode to the Library tab — **CoverFlow** — alongside the existing list and grid. Each game renders as a real 3D box (textured `SCNBox`) with the cover on the front, back-cover on the back, and the title displayed vertically on the spine. The user scrolls horizontally; the focused box sits front-facing at center, side boxes rotate ~45° away with perspective. Below the row, the focused game's title and platform are shown in plain text.

Inspired by Apple's iTunes CoverFlow and the boxed-game presentation common in modded Xbox 360 / PS Vita home screens. Uses **SceneKit** (iOS-native, ships with SwiftUI) for the 3D scene; embedded via `UIViewRepresentable`.

## Goals

- Provide a visually distinctive "browse my collection" mode that uses the cover art we already cache.
- Match the web app's existing CoverFlow aesthetic (3D perspective, side covers tilted) but render real 3D boxes with visible spines instead of flat rotated images.
- Preserve every existing Library affordance: sort, search, platform filter, swipe to delete (in list mode), and tap → game detail.
- Be honest about media types: a cart-game box (Switch / DS / GBA) is smaller than a disc-game box (PlayStation / Xbox / Wii). Both are still boxes; only the proportions change.

## Non-goals (out of scope for 4c)

- **CoverFlow on Items / Completions** — Library only.
- **Reflection on the floor below cases.** Classic iTunes touch, deliberately skipped.
- **Animated "open the case" interaction.** Tap = navigate, not "rotate open."
- **Platform-colored spines.** All spines use the theme accent color; no platform-color mapping table to maintain.
- **Box-art-accurate spine layout.** Spine = uniform "title rotated 90° in white on accent color." Real game spines have artwork; we don't replicate that.
- **Custom GameCube mini-disc geometry.** GameCube is treated as a disc-format game; we render the full DVD-sized box.
- **Cover-loading skeleton animation.** Placeholder is a solid color; cover snaps in when loaded.

## Section 1: Library integration

`LibraryView.ViewMode` gains a third case:

```swift
enum ViewMode: String, CaseIterable, Identifiable {
    case list
    case grid
    case coverflow
}
```

The existing toolbar Menu adds a third row:
- list (`list.bullet`)
- grid (`square.grid.2x2`)
- coverflow (`rectangle.stack.fill` — open to alternatives during implementation)

`LibraryView.content` switches:
```swift
case .coverflow:
    CoverFlowView(games: games,
                  imagesAPI: imagesAPI,
                  onSelectGame: { gameID in /* navigate */ })
```

The same `filteredGames` array, sort order, search results, and platform filter that drive list/grid drive CoverFlow. Empty state renders the existing `ContentUnavailableView` (no 3D scene).

## Section 2: View hierarchy

```
CoverFlowView                            (SwiftUI host)
  ├ CoverFlowSceneView                   (UIViewRepresentable → SCNView)
  │   ├ rebuilds CoverFlowScene when games[] changes
  │   ├ pan gesture → scroll math → focused index
  │   └ tap gesture → SCNHitTest → focused or navigate
  └ VStack (below row)
      Text(game.title).font(.title3).bold()
      Text(game.platform).font(.subheadline).foregroundStyle(.secondary)
```

The host owns `@State focusedIndex: Int` (the position in the games array currently centered). Both the scene's animation and the label below read from this.

## Section 3: Per-box 3D geometry + materials

Each game = one `SCNBox` node. Box dimensions vary by inferred media type:

| Media | W | H | D | Aspect notes |
|---|---|---|---|---|
| Disc (default) | 0.28 | 0.40 | 0.022 | Standard DVD case proportions |
| Cart | 0.22 | 0.32 | 0.018 | Slimmer + shorter — closer to a Switch / 3DS case |

(SceneKit "units" are arbitrary; camera and lighting are tuned to make these read as comfortable-sized boxes on screen.)

All 6 box faces share a single SCNBox geometry with `materialsArray` providing per-face textures:

| Face index | Face | Content |
|---|---|---|
| 0 | front (+Z) | `game.frontCoverImage` decoded to `UIImage`; assigned to `SCNMaterial.diffuse.contents`. Placeholder = dark color until loaded. |
| 1 | right (+X) — spine | Generated spine texture (Section 4) |
| 2 | back (−Z) | `game.backCoverImage` if non-nil. Else front-cover with a 60% black overlay applied via Core Image, so the "back" is recognizably the same case but darker. |
| 3 | left (−X) — spine | Same generated spine texture as face 1 |
| 4 | top (+Y) | Solid dark color (`Color.black.opacity(0.6)` baked to UIImage) |
| 5 | bottom (−Y) | Same as top |

Lighting: one `SCNLight(type: .ambient)` at 50% intensity, plus one `SCNLight(type: .directional)` from the front-top. Enough to differentiate the side faces without over-baking.

## Section 4: Spine texture

Each game's spine is generated at scene-build time as a `UIImage` via `SpriteKit` rendering:

```swift
SpineTextureBuilder.makeSpineTexture(
    title: game.title,
    background: theme.accent,
    width: 60,        // pixels — narrow texture, scaled by SceneKit to fit the spine's actual aspect
    height: 600       // tall, since the title runs lengthwise
) -> UIImage
```

Inside, build an `SKScene` of size 60×600 with:
- A solid background fill in `theme.accent`
- A `SKLabelNode` with `game.title`, white text, `Press Start 2P` font if the active theme requests pixel typography, otherwise system bold. The label is rotated 90° (zRotation = .pi / 2) so it reads bottom-to-top when the box is upright.
- Snapshot to `UIImage` via `SKView.texture(from:)` → `UIImage(cgImage:)`.

Generated once per game per theme change. Cached in a small `[NSManagedObjectID: UIImage]` dictionary inside `CoverFlowScene`.

## Section 5: Scroll math + interaction

### Layout

The scene has a single root node, `caseRow`. Each box is a child of `caseRow` positioned at `(spacing × indexOffset, 0, 0)` where `indexOffset = i - focusedIndex` and `spacing` is about 0.55 (slightly less than box width, so adjacent boxes overlap visually).

Per-box transforms based on `indexOffset`:

| indexOffset | translation (x, z) | rotation around Y | scale | opacity |
|---|---|---|---|---|
| 0 (focused) | (0, +0.15, 0) | 0° | 1.0 | 1.0 |
| ±1 | (±0.55, 0, 0) | ∓45° | 0.85 | 0.85 |
| ±2 | (±0.95, 0, 0) | ∓55° | 0.75 | 0.55 |
| ≥ ±3 | hidden (or out-of-frustum) | — | — | 0 |

The center box's slight forward translation (`z = +0.15` toward camera) and pulled-up Y gives it visual prominence.

### Pan-to-scroll

`UIPanGestureRecognizer` on the SCNView. Pan delta accumulates a fractional `scrollOffset: Double`. On `.ended`, snap `scrollOffset` to the nearest integer with a SceneKit transaction (`SCNTransaction.animationDuration = 0.35`). The animation interpolates all visible boxes' transforms simultaneously — SceneKit handles the keyframes.

### Tap

`UITapGestureRecognizer` on the SCNView. On tap:
- Hit-test via `SCNView.hitTest(_:options:)`.
- If the hit node belongs to a box at indexOffset 0 → call `onSelectGame(game.persistentModelID)` on the SwiftUI host.
- If the hit node belongs to a side box → snap that box to center (set `focusedIndex` to its index).

### Recycling (performance)

The scene caps visible boxes at 7 (center ± 3). Boxes outside this window are removed from the scene graph. As the user scrolls, boxes enter/exit the window — textures load async (cover from `ImagesAPI.downloadCover(...)`, spine from `SpineTextureBuilder` cache).

## Section 6: Platform → media inference

Tiny helper in its own file, pure function, table-driven, unit-tested.

```swift
enum MediaType { case disc, cart }

enum MediaTypeInfer {
    static func infer(from platform: String) -> MediaType {
        let p = platform.lowercased()
        for keyword in cartKeywords {
            if p.contains(keyword.lowercased()) { return .cart }
        }
        return .disc
    }

    private static let cartKeywords: [String] = [
        "NES",
        "SNES",
        "N64",
        "Game Boy",   // also matches "Game Boy Color", "Game Boy Advance"
        "GBA",
        "GB ",         // trailing space avoids matching "GBA"
        "DS",          // matches "Nintendo DS", "DSi", "3DS"
        "Switch",
        "Vita",
    ]
}
```

(GameCube uses mini-DVDs — treat as disc-format. Atari / Genesis / SMS — historically carts but rare in modern collections; user adds them as "Cartridge" by keyword if they want.)

### Tests (`MediaTypeInferTests.swift`)

Table-driven, ~20 platform strings:
- `"PlayStation 5"` → disc
- `"Xbox Series X"` → disc
- `"Nintendo Switch"` → cart
- `"Nintendo 3DS"` → cart
- `"Game Boy Advance"` → cart
- `"PC"` → disc (default for PC since storefront-distributed)
- `"PlayStation Vita"` → cart
- `"Super Nintendo (SNES)"` → cart
- `"GameCube"` → disc (mini-DVD)
- Empty string → disc

## Section 7: Cover & back-cover loading

Each box is built with placeholder materials (theme.accent for the spines, dark color for top/bottom, light gray for front/back). On scene-build, a `Task` per box:

```swift
Task {
    if let url = try? await imagesAPI.downloadCover(gameServerId: game.serverId,
                                                   face: .front, size: .full),
       let img = UIImage(contentsOfFile: url.path) {
        await MainActor.run {
            boxNode.geometry?.materials[0].diffuse.contents = img
        }
    }
    // similar for .back face on materials[2]
}
```

Tasks are cancelled when a box exits the visible window (via `Task.cancel()` tracked per-node), so swipe-scrolling through 100 games doesn't queue 100 concurrent downloads.

## Section 8: Theme integration

CoverFlow reads `@Environment(\.theme)` and uses:
- `theme.accent` for spine background colors
- `theme.fontName` (when non-nil — Game Boy's Press Start 2P) for spine label font; else system bold
- `theme.colorScheme` to choose label color contrast (white spine text in all themes since spine background is accent which is always saturated enough)

When the theme changes, the scene's spine textures regenerate (the cached `[ID: UIImage]` is cleared).

## Section 9: File structure

### New files

```
ios/GameTracker/GameTracker/Views/Library/
  CoverFlowView.swift              — SwiftUI host. SCNView + below-row label + nav binding.
  CoverFlowSceneView.swift         — UIViewRepresentable wrapping SCNView.
  CoverFlowScene.swift             — SCNScene factory: camera, lights, case row, scroll math.
  CoverFlowCaseNode.swift          — single SCNBox builder (geometry + materials + async cover load).
  SpineTextureBuilder.swift        — SpriteKit-based spine label texture.
  MediaTypeInfer.swift             — pure platform → MediaType helper.

ios/GameTracker/GameTrackerTests/
  MediaTypeInferTests.swift        — ~20 platform-string assertions.
```

### Modified files

- `Views/Library/LibraryView.swift` — `.coverflow` ViewMode case + new toolbar icon + `case .coverflow: CoverFlowView(...)` in the content switch.

### Untouched

- All other tabs (Items, Completions, Stats, Settings).
- All models, sync engine, networking, ImagesAPI, themes.
- Library's list and grid modes — bytes unchanged.

## Section 10: Risks / open questions for implementation

- **Tap precision on SCNView in a SwiftUI container.** SceneKit's hit-test works in scene coordinates; SwiftUI gestures arrive in view coordinates. The plan needs to verify the gesture-to-hit-test bridge works cleanly. If `SCNView` doesn't forward taps to a SwiftUI `.onTapGesture`, fall back to `UITapGestureRecognizer` on the underlying `SCNView` in `makeUIView(...)`.
- **Memory: holding N × cover UIImages in materials.** A 1024×1024 JPEG decoded is ~4 MB. Cap visible boxes at 7. If memory pressure appears, shrink the materials' texture size by downsizing the UIImage before assignment.
- **Theme change while CoverFlow is visible.** Rebuilding the scene on theme change works but is jarring (full rebuild = pause). Acceptable for v1; an incremental update is a future enhancement.
- **Searchable + scroll position.** When the filtered list changes, current focusedIndex may point at a removed game. Behavior: snap to index 0. The previous focus position is not preserved across filter changes (acceptable for v1).
- **Empty filtered result during search.** CoverFlow shows the same empty-state ContentUnavailableView as list/grid. No 3D scene rendered.
