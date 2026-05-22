# iOS Rich Themes Design (Plan 4b)

**Status:** Approved 2026-05-22.

## Overview

Extend Plan 4a's `AppearanceMode` from three Apple color schemes (System / Light / Dark) to seven, adding four "rich" themes: **Matrix**, **Retro Mac (Platinum)**, **Game Boy**, and **CRT Amber**. Each rich theme combines a coordinated color palette, a font choice (bundled or system), and one contextual signature flourish — never always-on, only on specific screens — so the daily reading experience stays comfortable while the theme still feels distinctive.

The architecture introduces a `Theme` struct injected via SwiftUI environment at the WindowGroup root. Most views need no changes: SwiftUI's tint and font-design propagate automatically. Only the few views that render flourishes (CoverImage hero shots, Library empty state, Stats tab, etc.) read the theme directly.

## Goals

- Ship four visually distinct themes the user can pick from Settings, without rewriting view code across the codebase.
- Keep daily-use comfort: never run animation behind every tab.
- Preserve the Plan 4a forward-compat promise — `AppearanceMode` raw values for `system`/`light`/`dark` stay unchanged; existing user preferences carry over.
- Make adding more themes in the future a one-row table edit.

## Non-goals (out of scope for 4b)

- **Theme-specific app icon.** Alt-icon support requires Info.plist alt-icon entries and runtime API; separate plan if wanted.
- **Sound effects.** Game Boy boot chime, Mac startup sound, etc. Future plan.
- **Sub-screen theme picker with live previews.** The Settings picker stays a `.menu` style with an inline live-preview tile *below* the picker (one preview reflecting the current selection). A full per-option preview grid is future work.
- **Custom navigation animations** (Retro Mac shrink-into-tab, Game Boy fade-cut). Standard SwiftUI transitions.
- **Per-theme cover dithering settings.** Game Boy ships with one fixed 4-color palette; no user toggle for 2-color mode or alternative palettes.
- **Always-on flourishes.** All flourishes are explicitly scoped to specific screens (see §3).

## Section 1: `Theme` model and registry

### The `Theme` struct

New file `ios/GameTracker/GameTracker/Settings/Theme.swift`:

```swift
struct Theme: Equatable {
    /// `.dark` / `.light` / `nil` (follow OS for System theme only).
    let colorScheme: ColorScheme?

    /// SwiftUI tint applied at the WindowGroup root — drives accent
    /// across buttons, links, picker chevrons, sliders, etc.
    let accent: Color

    /// nil = no override (themes that don't change the global
    /// background fall back to SwiftUI's semantic backgrounds).
    /// Non-nil = applied as the WindowGroup root background.
    let background: Color?

    /// `.default` / `.monospaced` / `.serif`. Inherited by Form/List
    /// text via `.fontDesign(_:)` on the root.
    let fontDesign: Font.Design

    /// Optional bundled font name (e.g. "ChicagoKare-Regular"). When
    /// non-nil, takes precedence over `fontDesign` for body text.
    let fontName: String?

    /// Selective image post-processing on hero (.full size) covers.
    let coverEffect: CoverEffect

    /// Contextual visual flourish — applied only on the screen
    /// surfaces the theme registry says it applies to. Multiple
    /// surfaces can share one flourish.
    let flourish: Flourish?
}

enum CoverEffect: Equatable {
    case none
    case gameBoyDither    // 4-color ordered-Bayer dither
}

enum Flourish: Equatable {
    case codeRain         // Matrix
    case platinumBevel    // Retro Mac
    case scanlines        // CRT Amber
}
```

### `ThemeRegistry`

Single source of truth mapping `AppearanceMode` → `Theme`. Lives in the same file:

```swift
enum ThemeRegistry {
    static func theme(for mode: AppearanceMode) -> Theme {
        switch mode {
        case .system:    return .system
        case .light:     return .systemLight
        case .dark:      return .systemDark
        case .matrix:    return .matrix
        case .retroMac:  return .retroMac
        case .gameBoy:   return .gameBoy
        case .crtAmber:  return .crtAmber
        }
    }
}

extension Theme {
    static let system     = Theme(colorScheme: nil,    accent: .blue,                  background: nil,                                  fontDesign: .default,    fontName: nil,                       coverEffect: .none,           flourish: nil)
    static let systemLight = Theme(colorScheme: .light, accent: .blue,                  background: nil,                                  fontDesign: .default,    fontName: nil,                       coverEffect: .none,           flourish: nil)
    static let systemDark  = Theme(colorScheme: .dark,  accent: .blue,                  background: nil,                                  fontDesign: .default,    fontName: nil,                       coverEffect: .none,           flourish: nil)
    static let matrix      = Theme(colorScheme: .dark,  accent: Color(hex: 0x00FF41),   background: Color.black,                          fontDesign: .monospaced, fontName: nil,                       coverEffect: .none,           flourish: .codeRain)
    static let retroMac    = Theme(colorScheme: .light, accent: Color(hex: 0x2C5AA0),   background: Color(hex: 0xDDDDDD),                 fontDesign: .default,    fontName: "ChicagoKare-Regular",     coverEffect: .none,           flourish: .platinumBevel)
    static let gameBoy     = Theme(colorScheme: .light, accent: Color(hex: 0x306230),   background: Color(hex: 0x9BBC0F),                 fontDesign: .monospaced, fontName: "PressStart2P-Regular",    coverEffect: .gameBoyDither,  flourish: nil)
    static let crtAmber    = Theme(colorScheme: .dark,  accent: Color(hex: 0xFFB000),   background: Color(hex: 0x1A0E00),                 fontDesign: .monospaced, fontName: nil,                       coverEffect: .none,           flourish: .scanlines)
}
```

(Implementation note: `Color(hex:)` is a small initializer helper added in the same file — straightforward 0xRRGGBB conversion.)

### Environment injection

A new `EnvironmentValues.theme` value, defaulted to `.system`:

```swift
private struct ThemeKey: EnvironmentKey {
    static let defaultValue: Theme = .system
}

extension EnvironmentValues {
    var theme: Theme {
        get { self[ThemeKey.self] }
        set { self[ThemeKey.self] = newValue }
    }
}
```

## Section 2: `AppearanceMode` extension

Modify `ios/GameTracker/GameTracker/Settings/AppearanceMode.swift`:

```swift
enum AppearanceMode: String, CaseIterable, Identifiable {
    case system
    case light
    case dark
    case matrix
    case retroMac      = "retro_mac"
    case gameBoy       = "game_boy"
    case crtAmber      = "crt_amber"

    var id: Self { self }

    var displayName: String {
        switch self {
        case .system:    return "System"
        case .light:     return "Light"
        case .dark:      return "Dark"
        case .matrix:    return "Matrix"
        case .retroMac:  return "Retro Mac"
        case .gameBoy:   return "Game Boy"
        case .crtAmber:  return "CRT Amber"
        }
    }

    /// Deprecated alias for the previous `colorScheme` property.
    /// New code reads `ThemeRegistry.theme(for:)`'s `colorScheme`
    /// directly. Kept on the enum only as long as Settings still
    /// shows .system/.light/.dark — but every consumer is migrated
    /// in this plan.
    var colorScheme: ColorScheme? { ThemeRegistry.theme(for: self).colorScheme }
}
```

(The `retro_mac` / `game_boy` / `crt_amber` raw values use snake_case so they're not visually ambiguous with the lowercase `matrix`. The `colorScheme` computed property is preserved purely for the brief migration window — once `GameTrackerApp` and `SettingsView` read `\.theme` from environment, that property has no callers and can be removed in a follow-up.)

## Section 3: Flourishes — where each applies

| Flourish | Theme | Applied on (and ONLY on) |
|---|---|---|
| `.codeRain` | Matrix | Library empty state (no games yet); Settings appearance picker's live-preview tile |
| `.platinumBevel` | Retro Mac | All `NavigationStack` title bars + Form section headers, every tab |
| `.gameBoyDither` (cover effect, not flourish) | Game Boy | Hero covers in `GameDetailView` and `ItemDetailView` only (NOT Library/Items grid thumbnails) |
| `.scanlines` | CRT Amber | Stats tab (the most "monitor display" surface); Settings appearance picker's live-preview tile |

**Why these specific surfaces:**
- Library empty state is rare enough that animation there isn't distracting and gives Matrix users an "easter egg moment."
- Retro Mac's beveled headers are the one element that survives constant exposure — bevels are static; no animation cost.
- Game Boy dither on every Library thumbnail would be a performance hit (recomputing dither for ~50 covers on every scroll) and make titles hard to read at thumbnail size. Detail-only is the sweet spot.
- Stats already has the "data screen" feel, so scanlines fit; Library/Items would feel cluttered.

## Section 4: Reusable flourish views

New file `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift`:

### `CodeRainView`

SwiftUI `Canvas` + `TimelineView(.animation)`. Falling columns of random characters drawn from `["0","1","ｱ","ｲ","ｳ","ｴ","ｵ","ｶ","ｷ","ｸ","ｹ","ｺ"]` (mix of digits + katakana). Each column has its own speed, head position, and length. Brightest character at the head; trailing fade. 60fps target, but `TimelineView(.animation(minimumInterval: 1.0/30.0))` to cap at 30fps for battery. Color comes from `theme.accent`.

Usage:
```swift
CodeRainView()
    .frame(maxWidth: .infinity, maxHeight: .infinity)
    .opacity(0.7)
```

### `ScanlineOverlayView`

Static (non-animated) `LinearGradient(stops: [...])` repeated vertically via a tiled background image generated at runtime. ~50% transparent dark stripes every 4pt. Sits in a `.overlay(...)` above the target content, with `.allowsHitTesting(false)` so it doesn't eat taps.

Usage:
```swift
StatsView(...)
    .overlay(ScanlineOverlayView().allowsHitTesting(false))
```

### `PlatinumHeaderStyle`

A `ViewModifier` that applies a 3-stop linear gradient background (`Color(hex: 0xEEEEEE)` → `Color(hex: 0xCCCCCC)` → `Color(hex: 0xAAAAAA)`) with a 1pt dark bottom border. Used for both navigation bar appearance (`.toolbarBackground(...)` content) and Form section headers.

For nav bars, a `UIAppearance` proxy on `UINavigationBar` is set when the theme is Retro Mac (and reset when not). For section headers, a `View` modifier wraps the header text.

### Game Boy cover dithering

Not a standalone view — a modifier applied inside `CoverImage`. Uses SwiftUI's `.colorEffect(_:)` with a Metal fragment shader (iOS 17+) that performs ordered Bayer-4 dithering against the 4-color Game Boy palette:

```
#0F380F  (darkest, ~black-green)
#306230  (dark green)
#8BAC0F  (light green)
#9BBC0F  (lightest, ~yellow-green)
```

Shader pseudocode:
```
input rgb → luminance (0..1)
threshold = bayer4[x % 4][y % 4] / 16.0
quantize luminance + threshold to one of 4 palette indices
output palette[index]
```

The shader file lives at `ios/GameTracker/GameTracker/Settings/GameBoyDither.metal`. Fallback when shader load fails: `.saturation(0).colorMultiply(Color(hex: 0x8BAC0F))` — desaturated and tinted, not true dithering but visually adjacent.

## Section 5: Bundled fonts

Two TTF files added under `ios/GameTracker/GameTracker/Resources/Fonts/`:

- **ChicagoKare-Regular.ttf** — public-domain Susan Kare bitmap reimplementation; closest free analogue to original Chicago. Bundle size ~25 KB.
- **PressStart2P-Regular.ttf** — OFL-licensed pixel font from Google Fonts. Bundle size ~22 KB.

Registered in `Info.plist`:

```xml
<key>UIAppFonts</key>
<array>
    <string>Fonts/ChicagoKare-Regular.ttf</string>
    <string>Fonts/PressStart2P-Regular.ttf</string>
</array>
```

PostScript names (used in `Font.custom("...", size:)`) match the filename prefix.

If the implementation plan can't find a freely-licensable Chicago lookalike at acceptable quality, Retro Mac falls back to `.monospaced` design with no flourish font, and the spec is amended.

## Section 6: Root-level application

Modify `GameTrackerApp.swift`:

```swift
@AppStorage("appearanceMode") private var appearanceMode: AppearanceMode = .system

private var theme: Theme {
    ThemeRegistry.theme(for: appearanceMode)
}

var body: some Scene {
    WindowGroup {
        RootViewContainer(...)
            .environment(authManager)
            .environment(\.theme, theme)
            .preferredColorScheme(theme.colorScheme)
            .tint(theme.accent)
            .fontDesign(theme.fontDesign)
            .background(theme.background ?? Color.clear)
    }
    .modelContainer(container)
}
```

`.preferredColorScheme(theme.colorScheme)` continues working exactly as in 4a. `.tint(...)` and `.fontDesign(...)` are the new global hooks; `.background(...)` overrides only when the theme demands a custom backdrop.

For `theme.fontName` (Chicago, Press Start 2P): SwiftUI doesn't ship a "global font name" modifier. The plan applies these via a small `UIAppearance` override on `UILabel.appearance().font` and `UIBarButtonItem.appearance().setTitleTextAttributes(...)` when the theme is Retro Mac or Game Boy. SwiftUI Text views via `.font(.custom("PressStart2P-Regular", size: ...))` are NOT used view-by-view — the appearance proxy handles 90% of text; remaining edge cases (chart axis labels, etc.) stay in system font and are accepted as a known minor inconsistency.

## Section 7: Settings picker UX

The existing picker in `SettingsView` grows from 3 to 7 cases. Layout stays the same `.menu`-style `Picker("Theme", selection: $appearanceMode)`. Order in the dropdown:

```
System
Light
Dark
─────────
Matrix
Retro Mac
Game Boy
CRT Amber
```

Below the picker, a new row renders a **live preview tile** — a small (full-width × 120pt) preview of "what your covers will look like in this theme." Contents:

- Background: `theme.background ?? appropriate neutral`
- A miniature "Library" header bar styled with the theme's flourish if applicable
- 3 sample cover thumbnails (from the user's actual library if any; otherwise an SF Symbol placeholder)
- Tiny scanlines / code-rain corner if the flourish applies to that surface in the registry

The preview re-renders when the picker selection changes — gives the user a "wait, what does Matrix look like" check without leaving Settings.

## Section 8: View consumers (what changes)

Most views: no change. They inherit `.tint`, `.fontDesign`, `.preferredColorScheme` from root.

The handful that DO change:

- **`CoverImage`** — reads `\.theme.coverEffect` from environment; applies `.colorEffect(...)` shader when `coverEffect == .gameBoyDither` AND `size == .full`. Thumb-size renders unchanged.
- **`LibraryView`** — reads `\.theme.flourish`; if `.codeRain`, the existing empty-state view gets `CodeRainView()` as a `.background(...)`.
- **`StatsView`** — reads `\.theme.flourish`; if `.scanlines`, applies `.overlay(ScanlineOverlayView())`.
- **`SettingsView`** — adds the live-preview tile under the picker; expanded picker case list.
- **NavigationStack title bars (cross-cutting)** — when theme is Retro Mac, a `UINavigationBar.appearance()` configuration applies the platinum gradient. This is a one-shot side effect at theme-change time, wrapped in a small `applyAppKitAppearance(for: Theme)` helper called from `GameTrackerApp` on `.onChange(of: theme)`.

## Section 9: Testing

- **Unit tests** (new): `ThemeRegistryTests.swift`
  - Every `AppearanceMode` case returns a `Theme` (no fatal traps).
  - System/Light/Dark themes produce the same color scheme as the old `AppearanceMode.colorScheme` (regression guard for 4a behaviour).
  - Each rich theme's `colorScheme` is non-nil (rich themes never "follow system").

- **Unit tests** (new): `ThemeBackwardsCompatTests.swift`
  - Existing raw values "system", "light", "dark" still decode to the right enum case.
  - New raw values "retro_mac", "game_boy", "crt_amber" decode correctly.

- **Manual checkpoint** at end of plan: cycle through all 7 themes, verify each tab still renders correctly and each flourish appears on its designated surface only.

## Section 10: Final section ordering check

The spec covers — in order of implementation:

1. `Theme` struct + registry + environment key (§1)
2. `AppearanceMode` extended (§2)
3. Flourish view files (§4)
4. Bundled fonts + Info.plist (§5)
5. Root-level wiring in `GameTrackerApp` (§6)
6. Settings picker + live preview (§7)
7. View consumers updated (§8)
8. Tests (§9)

Implementation plan will split these into bite-sized tasks.
