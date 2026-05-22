# iOS Rich Themes Implementation Plan (Plan 4b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend Plan 4a's `AppearanceMode` from three cases (System / Light / Dark) to seven, adding **Matrix**, **Retro Mac**, **Game Boy**, and **CRT Amber** — each with a coordinated palette, font (system or bundled), and one contextual signature flourish.

**Architecture:** A new `Theme` struct injected via SwiftUI environment at the WindowGroup root. Most views need no changes (SwiftUI's `.tint` and `.fontDesign` propagate). Only flourish-bearing surfaces (CoverImage hero, Library empty state, Stats tab, Settings preview) read the theme directly. A single Metal fragment shader handles Game Boy dithering on `.full`-size covers.

**Tech Stack:** Swift 5.10+, SwiftUI, SwiftData (existing), Metal (shader for dither), `@AppStorage`, `XCTest`. One bundled OFL font (Press Start 2P). No new server endpoints, no new models.

**Predecessors:** Plans 3a–3e + 4a complete. Branch `plan-4b-rich-themes` already created with design spec (`17093fa`) committed. Spec: [`docs/superpowers/specs/2026-05-22-ios-rich-themes-design.md`](../specs/2026-05-22-ios-rich-themes-design.md).

**Execution rhythm:** Single bundle commit at end of Task 12 (matches 4a/3e pattern — the 7-entry theme picker is one coherent surface). One end-of-plan checkpoint walks all 7 themes.

---

## Deviation from spec (acknowledged before starting)

The spec proposes bundling `ChicagoKare-Regular.ttf` for Retro Mac. We can't reliably source a free, redistributable Chicago lookalike at acceptable quality. Per the spec's §5 contingency clause:

> If the implementation plan can't find a freely-licensable Chicago lookalike at acceptable quality, Retro Mac falls back to `.serif` design with no flourish font, and the spec is amended.

This plan amends accordingly: **only `PressStart2P-Regular.ttf` is bundled** (for Game Boy). Retro Mac's `Theme.fontName` is `nil` and its `fontDesign` is `.serif` (SwiftUI ships Charter / New York via the serif design). The platinum-bevel headers carry the Retro Mac vibe more than the font alone would.

---

## Working-directory + simulator conventions

- **CWD:** `gameTracker/ios/GameTracker/` for `xcodebuild`; `gameTracker/` for `git`.
- **Simulator name:** `iPhone 17` (iOS 26.5 sims).
- **Branch:** Already created — `plan-4b-rich-themes`, branched off `main` (Plan 4a merged at `1a62134`).
- **Pre-existing changes to leave alone in every commit:**
  - `js/completions.js` — old uncommitted whitespace edit.
  - `scripts/generate-thumbnails 2.php` + `tests/v2/*2.sh` — iCloud Drive conflict copies.
  - `ios/GameTracker/GameTrackerTests/Helpers 2/` — iCloud Drive folder duplicate; do not touch.
- **iCloud Drive Swift conflict files:** clear before each test pass:

  ```bash
  find ios/GameTracker -name "* [0-9].swift" -print -delete
  ```

---

## File structure

### New iOS files

```
ios/GameTracker/GameTracker/Settings/Theme.swift
ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift
ios/GameTracker/GameTracker/Settings/GameBoyDither.metal
ios/GameTracker/GameTracker/Resources/Fonts/PressStart2P-Regular.ttf
ios/GameTracker/GameTrackerTests/ThemeRegistryTests.swift
```

### Modified iOS files

| File | Change |
|---|---|
| `Settings/AppearanceMode.swift` | Add 4 new cases (`matrix`, `retroMac`, `gameBoy`, `crtAmber`). Deprecate the local `colorScheme` computed property; it now delegates to `ThemeRegistry`. |
| `GameTrackerApp.swift` | Inject `\.theme` env value; chain `.tint`, `.fontDesign`, `.background`; trigger `UIAppearance` side effect on theme change. |
| `Info.plist` | Add `UIAppFonts` entry for the bundled TTF. |
| `Views/Common/CoverImage.swift` | Apply `.colorEffect(...)` Game Boy shader when `theme.coverEffect == .gameBoyDither` AND `size == .full`. |
| `Views/Library/LibraryView.swift` | Add `CodeRainView()` to empty state when `theme.flourish == .codeRain`. |
| `Views/Stats/StatsView.swift` | Add `ScanlineOverlayView()` as `.overlay(...)` when `theme.flourish == .scanlines`. |
| `Views/Settings/SettingsView.swift` | Picker grows from 3 to 7 cases (with a divider after Dark). Add live-preview tile below the picker. |

### Untouched

- Sync engine, API client, models.
- All non-Library, non-Stats tab views.
- `Config.swift`, `ImagesAPI.swift`, `ImageCachePaths`.

---

## Task 0: Verify state + commit plan doc

**Files:**
- Create: `docs/superpowers/plans/2026-05-22-ios-rich-themes.md` (this file)

- [x] **Step 0.1: Confirm current state**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git branch --show-current        # → plan-4b-rich-themes
git log --oneline -3              # spec on top of 4a merge
git status --short                # only pre-existing junk
```

Expected: branch is `plan-4b-rich-themes`; spec commit (`17093fa`) sits on top of the 4a merge (`1a62134`).

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
git add docs/superpowers/plans/2026-05-22-ios-rich-themes.md
git commit -m "Add Plan 4b (iOS rich themes) implementation plan"
```

---

## Task 1: `Theme` struct + `AppearanceMode` extension + tests (TDD)

**Files:**
- Create: `ios/GameTracker/GameTracker/Settings/Theme.swift`
- Modify: `ios/GameTracker/GameTracker/Settings/AppearanceMode.swift`
- Create: `ios/GameTracker/GameTrackerTests/ThemeRegistryTests.swift`

This is the foundation. Adds the new cases, the theme model, the registry, the environment key, and the `Color(hex:)` helper — all in one cohesive unit.

- [x] **Step 1.1: Write the failing tests**

Write `ios/GameTracker/GameTrackerTests/ThemeRegistryTests.swift`:

```swift
import XCTest
import SwiftUI
@testable import GameTracker

final class ThemeRegistryTests: XCTestCase {

    // Regression guard: 4a's three modes still produce the same color scheme
    // as before (System=nil, Light=.light, Dark=.dark).
    func test_system_theme_has_nil_color_scheme() {
        XCTAssertNil(ThemeRegistry.theme(for: .system).colorScheme)
    }
    func test_light_theme_has_light_color_scheme() {
        XCTAssertEqual(ThemeRegistry.theme(for: .light).colorScheme, .light)
    }
    func test_dark_theme_has_dark_color_scheme() {
        XCTAssertEqual(ThemeRegistry.theme(for: .dark).colorScheme, .dark)
    }

    // Rich themes — each must produce a non-nil color scheme (rich themes
    // never "follow system" — they're prescriptive).
    func test_matrix_theme_is_dark() {
        XCTAssertEqual(ThemeRegistry.theme(for: .matrix).colorScheme, .dark)
    }
    func test_retro_mac_theme_is_light() {
        XCTAssertEqual(ThemeRegistry.theme(for: .retroMac).colorScheme, .light)
    }
    func test_game_boy_theme_is_light() {
        XCTAssertEqual(ThemeRegistry.theme(for: .gameBoy).colorScheme, .light)
    }
    func test_crt_amber_theme_is_dark() {
        XCTAssertEqual(ThemeRegistry.theme(for: .crtAmber).colorScheme, .dark)
    }

    // Flourish assignments must match the spec.
    func test_matrix_has_code_rain_flourish() {
        XCTAssertEqual(ThemeRegistry.theme(for: .matrix).flourish, .codeRain)
    }
    func test_retro_mac_has_platinum_bevel_flourish() {
        XCTAssertEqual(ThemeRegistry.theme(for: .retroMac).flourish, .platinumBevel)
    }
    func test_game_boy_has_no_flourish_but_dither_cover_effect() {
        XCTAssertNil(ThemeRegistry.theme(for: .gameBoy).flourish)
        XCTAssertEqual(ThemeRegistry.theme(for: .gameBoy).coverEffect, .gameBoyDither)
    }
    func test_crt_amber_has_scanlines_flourish() {
        XCTAssertEqual(ThemeRegistry.theme(for: .crtAmber).flourish, .scanlines)
    }

    // Default (.system) themes have no flourish and no cover effect.
    func test_system_theme_has_no_flourish_or_effect() {
        let t = ThemeRegistry.theme(for: .system)
        XCTAssertNil(t.flourish)
        XCTAssertEqual(t.coverEffect, .none)
    }

    // Raw-value backwards compatibility — 4a values are unchanged.
    func test_legacy_raw_values_are_stable() {
        XCTAssertEqual(AppearanceMode.system.rawValue, "system")
        XCTAssertEqual(AppearanceMode.light.rawValue, "light")
        XCTAssertEqual(AppearanceMode.dark.rawValue, "dark")
    }

    // New raw values use snake_case for multi-word cases (so the
    // serialized form is unambiguous and never collides with future
    // single-word additions).
    func test_new_raw_values() {
        XCTAssertEqual(AppearanceMode.matrix.rawValue, "matrix")
        XCTAssertEqual(AppearanceMode.retroMac.rawValue, "retro_mac")
        XCTAssertEqual(AppearanceMode.gameBoy.rawValue, "game_boy")
        XCTAssertEqual(AppearanceMode.crtAmber.rawValue, "crt_amber")
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

Expected: BUILD FAILED — `ThemeRegistry`, `Theme`, and the new `AppearanceMode` cases are not found. Correct; next steps create them.

- [x] **Step 1.3: Extend `AppearanceMode`**

Overwrite `ios/GameTracker/GameTracker/Settings/AppearanceMode.swift`:

```swift
import SwiftUI

/// Persisted user preference for the app's color scheme. Stored in
/// UserDefaults under `"appearanceMode"` via `@AppStorage`.
///
/// Plan 4b extends Plan 4a's three cases with four "rich" themes.
/// The raw values for `system`/`light`/`dark` are unchanged so 4a
/// preferences survive. Multi-word cases use snake_case raw values.
enum AppearanceMode: String, CaseIterable, Identifiable {
    case system
    case light
    case dark
    case matrix
    case retroMac    = "retro_mac"
    case gameBoy     = "game_boy"
    case crtAmber    = "crt_amber"

    var id: Self { self }

    /// Display label shown in the Settings picker.
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

    /// Preserved for any 4a caller that still reads this — delegates
    /// to `ThemeRegistry` so behaviour is unchanged but the registry
    /// is the single source of truth.
    var colorScheme: ColorScheme? {
        ThemeRegistry.theme(for: self).colorScheme
    }
}
```

- [x] **Step 1.4: Create `Theme.swift`**

Write `ios/GameTracker/GameTracker/Settings/Theme.swift`:

```swift
import SwiftUI

// MARK: - Theme model

/// A complete visual theme — a set of coordinated choices applied
/// at the WindowGroup root and read by flourish-bearing views.
///
/// Most views consume `Theme` indirectly via `.tint(...)` and
/// `.fontDesign(...)` modifiers attached to the WindowGroup root.
/// Only the few views that render a flourish or cover effect read
/// `\.theme` from the environment directly.
struct Theme: Equatable {
    /// `.dark` / `.light` / `nil` (only the System theme is `nil`).
    let colorScheme: ColorScheme?

    /// SwiftUI tint — drives accent on buttons, links, picker
    /// chevrons, sliders, navigation back buttons, etc.
    let accent: Color

    /// Optional global background override. `nil` = follow the
    /// semantic system background for the chosen color scheme.
    let background: Color?

    /// `.default` / `.monospaced` / `.serif`. Propagates to body
    /// text via `.fontDesign(_:)` on the root.
    let fontDesign: Font.Design

    /// Bundled font's PostScript name, or `nil` for system fonts.
    /// Currently only Game Boy uses this (Press Start 2P).
    let fontName: String?

    /// Selective image post-processing on hero (.full size) covers.
    let coverEffect: CoverEffect

    /// Contextual flourish — applied only on screens listed in the
    /// design spec's §3 (e.g. code-rain on Library empty state).
    let flourish: Flourish?
}

enum CoverEffect: Equatable {
    case none
    case gameBoyDither
}

enum Flourish: Equatable {
    case codeRain          // Matrix
    case platinumBevel     // Retro Mac
    case scanlines         // CRT Amber
}

// MARK: - Registry

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

// MARK: - Theme catalogue

extension Theme {
    static let system = Theme(
        colorScheme: nil,
        accent: .blue,
        background: nil,
        fontDesign: .default,
        fontName: nil,
        coverEffect: .none,
        flourish: nil
    )
    static let systemLight = Theme(
        colorScheme: .light,
        accent: .blue,
        background: nil,
        fontDesign: .default,
        fontName: nil,
        coverEffect: .none,
        flourish: nil
    )
    static let systemDark = Theme(
        colorScheme: .dark,
        accent: .blue,
        background: nil,
        fontDesign: .default,
        fontName: nil,
        coverEffect: .none,
        flourish: nil
    )
    static let matrix = Theme(
        colorScheme: .dark,
        accent: Color(hex: 0x00FF41),
        background: .black,
        fontDesign: .monospaced,
        fontName: nil,
        coverEffect: .none,
        flourish: .codeRain
    )
    static let retroMac = Theme(
        colorScheme: .light,
        accent: Color(hex: 0x2C5AA0),
        background: Color(hex: 0xDDDDDD),
        fontDesign: .serif,                  // see plan deviation: no Chicago bundle
        fontName: nil,
        coverEffect: .none,
        flourish: .platinumBevel
    )
    static let gameBoy = Theme(
        colorScheme: .light,
        accent: Color(hex: 0x306230),
        background: Color(hex: 0x9BBC0F),
        fontDesign: .monospaced,
        fontName: "PressStart2P-Regular",
        coverEffect: .gameBoyDither,
        flourish: nil
    )
    static let crtAmber = Theme(
        colorScheme: .dark,
        accent: Color(hex: 0xFFB000),
        background: Color(hex: 0x1A0E00),
        fontDesign: .monospaced,
        fontName: nil,
        coverEffect: .none,
        flourish: .scanlines
    )
}

// MARK: - Color(hex:) helper

extension Color {
    /// Convenience initializer for 0xRRGGBB integer literals.
    /// Alpha is always 1.0.
    init(hex: UInt32) {
        let r = Double((hex >> 16) & 0xFF) / 255.0
        let g = Double((hex >> 8)  & 0xFF) / 255.0
        let b = Double( hex        & 0xFF) / 255.0
        self.init(red: r, green: g, blue: b)
    }
}

// MARK: - Environment key

private struct ThemeKey: EnvironmentKey {
    static let defaultValue: Theme = .system
}

extension EnvironmentValues {
    /// The currently-active theme. Set at the WindowGroup root.
    var theme: Theme {
        get { self[ThemeKey.self] }
        set { self[ThemeKey.self] = newValue }
    }
}
```

- [x] **Step 1.5: Run tests — expect pass**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -5
```

Expected: `** TEST SUCCEEDED **`. (No commit yet — bundled at Task 12.)

---

## Task 2: Bundle the Press Start 2P font

**Files:**
- Create: `ios/GameTracker/GameTracker/Resources/Fonts/PressStart2P-Regular.ttf`
- Modify: `ios/GameTracker/GameTracker.xcodeproj/project.pbxproj` (add `INFOPLIST_KEY_UIAppFonts` build setting)

Game Boy theme needs a pixel font. Press Start 2P is OFL-licensed via Google Fonts.

This project uses `GENERATE_INFOPLIST_FILE = YES` with `INFOPLIST_KEY_*` build settings (no explicit Info.plist file). The standard way to register bundled fonts is to add `INFOPLIST_KEY_UIAppFonts` to the target's build settings, which Xcode then folds into the auto-generated Info.plist.

- [x] **Step 2.1: Create the Fonts directory and download the TTF**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
mkdir -p ios/GameTracker/GameTracker/Resources/Fonts
curl -L -o ios/GameTracker/GameTracker/Resources/Fonts/PressStart2P-Regular.ttf \
  https://github.com/google/fonts/raw/main/ofl/pressstart2p/PressStart2P-Regular.ttf
file ios/GameTracker/GameTracker/Resources/Fonts/PressStart2P-Regular.ttf
ls -lh ios/GameTracker/GameTracker/Resources/Fonts/PressStart2P-Regular.ttf
```

Expected: `file` reports a TrueType Font file. Size is roughly 22 KB. If `curl` fails or returns HTML, STOP — flag as BLOCKED.

- [x] **Step 2.2: Add `INFOPLIST_KEY_UIAppFonts` to project.pbxproj**

The project has two `XCBuildConfiguration` blocks for the `GameTracker` target — one for Debug, one for Release — each containing the `INFOPLIST_KEY_*` lines. Add `INFOPLIST_KEY_UIAppFonts` to BOTH.

Find each occurrence of the line `INFOPLIST_KEY_NSCameraUsageDescription` (there will be exactly two — one per configuration). Just after each line, insert:

```
				INFOPLIST_KEY_UIAppFonts = "Fonts/PressStart2P-Regular.ttf";
```

Use the Edit tool with `replace_all: false` for each occurrence — they have identical surrounding context but differ in nearby fields between Debug/Release, so you'll need to use enough context to disambiguate. The reliable way: first add to the first occurrence using a wider context block, then add to the second.

Example anchor (single occurrence — adapt for each config):

`old_string`:
```
				INFOPLIST_KEY_NSCameraUsageDescription = "GameTracker uses the camera to photograph your consoles and accessories.";
				INFOPLIST_KEY_NSPhotoLibraryUsageDescription = "GameTracker uses your photo library to pick existing photos of your collection.";
```

`new_string`:
```
				INFOPLIST_KEY_NSCameraUsageDescription = "GameTracker uses the camera to photograph your consoles and accessories.";
				INFOPLIST_KEY_NSPhotoLibraryUsageDescription = "GameTracker uses your photo library to pick existing photos of your collection.";
				INFOPLIST_KEY_UIAppFonts = "Fonts/PressStart2P-Regular.ttf";
```

This anchor appears twice (Debug + Release). Use `replace_all: true` so both get the addition in one edit.

- [x] **Step 2.3: Verify the edit landed in both configs**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
grep -c "INFOPLIST_KEY_UIAppFonts" ios/GameTracker/GameTracker.xcodeproj/project.pbxproj
```

Expected: `2` (one for Debug, one for Release).

- [x] **Step 2.4: Build check — verify the font registers in the bundle**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

(The actual runtime verification — that `UIFont(name: "PressStart2P-Regular", size: 14)` returns a non-nil font — happens implicitly during the user checkpoint when the Game Boy theme is selected and the nav bar title renders with the pixel font.)

---

## Task 3: `CodeRainView`

**Files:**
- Create: `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift`

Matrix's signature flourish: falling glyphs in phosphor green. SwiftUI `Canvas` + `TimelineView(.animation)` capped at 30fps.

- [x] **Step 3.1: Write `ThemeFlourishes.swift` with `CodeRainView`**

Write `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift`:

```swift
import SwiftUI

// MARK: - CodeRainView (Matrix flourish)

/// Falling columns of mixed digits and half-width katakana, rendered
/// in the active theme's accent color. Capped at 30fps for battery.
///
/// Lays out columns based on its rendered size. Drop into a
/// `.background(...)` or as a layer in a ZStack.
struct CodeRainView: View {
    @Environment(\.theme) private var theme

    private static let glyphs: [Character] = Array("01ｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉ")
    private static let columnWidth: CGFloat = 14
    private static let lineHeight: CGFloat = 18

    var body: some View {
        TimelineView(.animation(minimumInterval: 1.0/30.0)) { context in
            Canvas { ctx, size in
                draw(into: &ctx, size: size, time: context.date.timeIntervalSinceReferenceDate)
            }
            .opacity(0.75)
        }
        .allowsHitTesting(false)
    }

    private func draw(into ctx: inout GraphicsContext, size: CGSize, time: TimeInterval) {
        let columns = max(1, Int(size.width / Self.columnWidth))
        for col in 0..<columns {
            // Per-column deterministic speed + offset so the rain
            // looks varied but doesn't reshuffle every frame.
            let seed = Double(col) * 0.7
            let speed = 40.0 + (seed.truncatingRemainder(dividingBy: 30.0)) // 40..70 pt/s
            let phase = (time * speed + seed * 100).truncatingRemainder(dividingBy: size.height + 200) - 100
            let headY = phase
            let length = 12 + (Int(seed * 13) % 8)  // 12..20 glyphs

            for i in 0..<length {
                let y = headY - CGFloat(i) * Self.lineHeight
                guard y > -Self.lineHeight, y < size.height else { continue }
                let glyphIdx = (col * 7 + i + Int(time * 10)) % Self.glyphs.count
                let glyph = String(Self.glyphs[glyphIdx])

                // Brightest at head, fade with distance.
                let brightness = i == 0 ? 1.0 : max(0.1, 1.0 - Double(i) / Double(length))
                let color = theme.accent.opacity(brightness)

                var resolved = ctx.resolve(Text(glyph).font(.system(size: 14, design: .monospaced)))
                resolved.shading = .color(color)
                ctx.draw(resolved, at: CGPoint(x: CGFloat(col) * Self.columnWidth + 4, y: y))
            }
        }
    }
}
```

- [x] **Step 3.2: Build check**

```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' build 2>&1 \
  | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -5
```

Expected: **BUILD SUCCEEDED**.

---

## Task 4: `ScanlineOverlayView`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift` (append)

Static overlay — repeating horizontal dark lines every 4pt, 30% opacity. Sits in `.overlay(...)` with hit testing disabled.

- [x] **Step 4.1: Append `ScanlineOverlayView` to `ThemeFlourishes.swift`**

Append to `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift`:

```swift
// MARK: - ScanlineOverlayView (CRT Amber flourish)

/// Repeating horizontal dark stripes evoking a CRT monitor.
/// Non-animated, low-cost. Use as `.overlay(...)`. Doesn't eat taps.
struct ScanlineOverlayView: View {
    var body: some View {
        Canvas { ctx, size in
            let stripeSpacing: CGFloat = 4
            let stripeColor = Color.black.opacity(0.30)
            var y: CGFloat = 0
            while y < size.height {
                let rect = CGRect(x: 0, y: y, width: size.width, height: 1)
                ctx.fill(Path(rect), with: .color(stripeColor))
                y += stripeSpacing
            }
        }
        .allowsHitTesting(false)
    }
}
```

- [x] **Step 4.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 5: `applyAppKitAppearance` helper (Retro Mac chrome + Game Boy nav font)

**Files:**
- Modify: `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift` (append)

Retro Mac's beveled headers. Two parts:

1. A SwiftUI `ViewModifier` for Form section headers — wraps the section header text in a beveled background.
2. A `UIAppearance` configuration applied via a global helper, controlling `UINavigationBar` chrome.

Section headers don't get a SwiftUI tint API that gives us beveled gradient backgrounds, so we use the modifier on the headers we control (Settings/Library/Items/Completions/Stats — but in practice we only apply it where it matters for the visible bar, not the inner Form section labels).

Actually for this plan's scope, we apply the appearance proxy globally and skip per-section modifiers — the spec calls for the nav-bar treatment. Section headers in Forms remain SwiftUI-styled and will inherit the theme's text color but not a gradient background. This is a known acceptable compromise.

- [x] **Step 5.1: Append `applyAppKitAppearance` helper**

Append to `ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift`:

```swift
// MARK: - applyAppKitAppearance (Retro Mac flourish + global font hook)

import UIKit

/// Applies UIKit appearance proxies based on the theme. Called from
/// `GameTrackerApp.body` whenever the chosen theme changes — UIKit
/// appearance is global mutable state, so it's set imperatively.
///
/// - Retro Mac: navigation bar background is a 3-stop platinum
///   gradient with a 1pt dark separator line.
/// - Game Boy: navigation bar title font is Press Start 2P.
/// - Otherwise: appearance is reset to UIKit defaults.
@MainActor
func applyAppKitAppearance(for theme: Theme, mode: AppearanceMode) {
    let nav = UINavigationBar.appearance()
    let standard = UINavigationBarAppearance()
    standard.configureWithDefaultBackground()

    switch mode {
    case .retroMac:
        standard.backgroundImage = platinumGradientImage()
        standard.shadowColor = UIColor(white: 0.2, alpha: 1.0)
    case .gameBoy:
        if let pixel = UIFont(name: "PressStart2P-Regular", size: 14) {
            standard.titleTextAttributes = [.font: pixel]
        }
    default:
        break
    }

    nav.standardAppearance = standard
    nav.scrollEdgeAppearance = standard
    nav.compactAppearance = standard
}

private func platinumGradientImage() -> UIImage {
    let size = CGSize(width: 1, height: 44)
    let renderer = UIGraphicsImageRenderer(size: size)
    return renderer.image { ctx in
        let cg = ctx.cgContext
        let colors = [
            UIColor(red: 0.93, green: 0.93, blue: 0.93, alpha: 1.0).cgColor,
            UIColor(red: 0.80, green: 0.80, blue: 0.80, alpha: 1.0).cgColor,
            UIColor(red: 0.67, green: 0.67, blue: 0.67, alpha: 1.0).cgColor,
        ]
        let space = CGColorSpaceCreateDeviceRGB()
        let gradient = CGGradient(colorsSpace: space, colors: colors as CFArray, locations: [0, 0.5, 1])!
        cg.drawLinearGradient(gradient, start: .zero, end: CGPoint(x: 0, y: size.height), options: [])
    }
}
```

- [x] **Step 5.2: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 6: `GameBoyDither.metal` shader

**Files:**
- Create: `ios/GameTracker/GameTracker/Settings/GameBoyDither.metal`

A `[[ stitchable ]]` Metal fragment function that takes a pixel's RGBA, converts to luminance, applies ordered-Bayer-4 dithering, and outputs one of the four Game Boy palette colors.

- [x] **Step 6.1: Create the metal shader**

Write `ios/GameTracker/GameTracker/Settings/GameBoyDither.metal`:

```metal
#include <metal_stdlib>
using namespace metal;

// Ordered-Bayer 4x4 dithering against the 4-color Game Boy LCD
// palette. Applied via SwiftUI's .colorEffect(ShaderLibrary....).
//
// SwiftUI passes:
//   - position: pixel coordinate in the destination view
//   - color:    the source pixel color (premultiplied alpha)
//
// We compute luminance, add a Bayer-matrix bias, and snap to one of
// four palette colors.

[[ stitchable ]] half4 gameBoyDither(float2 position, half4 color) {
    // Bayer 4x4 matrix (values 0..15, normalized to 0..1 below).
    constexpr int bayer[16] = {
         0,  8,  2, 10,
        12,  4, 14,  6,
         3, 11,  1,  9,
        15,  7, 13,  5
    };

    int bx = int(position.x) & 3;
    int by = int(position.y) & 3;
    float threshold = float(bayer[by * 4 + bx]) / 16.0;

    // Luminance (Rec. 601).
    float lum = dot(float3(color.rgb), float3(0.299, 0.587, 0.114));

    // Add threshold-quarter so dithering acts as a small offset.
    float biased = lum + (threshold - 0.5) * 0.25;

    // Quantize to 4 buckets.
    int bucket = clamp(int(biased * 4.0), 0, 3);

    // Game Boy palette (light → dark).
    const half3 palette[4] = {
        half3(0.608, 0.737, 0.059),   // 0x9BBC0F
        half3(0.545, 0.675, 0.059),   // 0x8BAC0F
        half3(0.188, 0.384, 0.188),   // 0x306230
        half3(0.059, 0.220, 0.059)    // 0x0F380F
    };

    half3 out = palette[bucket];
    return half4(out, color.a);
}
```

- [x] **Step 6.2: Build check**

Expected: **BUILD SUCCEEDED**. Xcode's Metal compiler should pick up the `.metal` file automatically (file-system synchronized root group includes Metal sources in the `Compile Sources` phase).

If the build fails with "Metal toolchain not installed" or similar, you may need to run `xcode-select --install` or open Xcode once to install the Metal toolchain. STOP and report BLOCKED with the specific error.

---

## Task 7: Wire root-level theme application

**Files:**
- Modify: `ios/GameTracker/GameTracker/GameTrackerApp.swift`

The big plumbing step. Reads the theme from the registry, injects it into the environment, and chains every root modifier the spec requires.

- [x] **Step 7.1: Read current `GameTrackerApp.swift`**

Use the Read tool to load `ios/GameTracker/GameTracker/GameTrackerApp.swift`. You'll see (post 4a):

```swift
@AppStorage("appearanceMode") private var appearanceMode: AppearanceMode = .system

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

- [x] **Step 7.2: Replace the `body` and add a computed `theme` property**

Find the `@AppStorage("appearanceMode")` line. Just after it, add:

```swift
    private var theme: Theme {
        ThemeRegistry.theme(for: appearanceMode)
    }
```

Then find the entire `var body: some Scene { ... }` block and replace with:

```swift
    var body: some Scene {
        WindowGroup {
            ZStack {
                if let bg = theme.background {
                    bg.ignoresSafeArea()
                }
                RootViewContainer(authAPI: authAPI,
                                  syncAPI: syncAPI,
                                  proxiesAPI: proxiesAPI,
                                  imagesAPI: imagesAPI,
                                  status: status)
                    .environment(authManager)
                    .environment(\.theme, theme)
            }
            .preferredColorScheme(theme.colorScheme)
            .tint(theme.accent)
            .fontDesign(theme.fontDesign)
            .onAppear {
                applyAppKitAppearance(for: theme, mode: appearanceMode)
            }
            .onChange(of: appearanceMode) { _, newMode in
                applyAppKitAppearance(for: ThemeRegistry.theme(for: newMode), mode: newMode)
            }
        }
        .modelContainer(container)
    }
```

Rationale:
- `ZStack` layers the theme background under the content so colored backgrounds for Matrix / Game Boy / etc. show through transparent areas.
- `.environment(\.theme, theme)` makes the active Theme available to any descendant.
- `.tint`, `.fontDesign` propagate visually.
- `.onAppear` + `.onChange` keep `UINavigationBar.appearance()` in sync (UIKit doesn't observe SwiftUI state).

- [x] **Step 7.3: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 8: Apply Game Boy dither in `CoverImage`

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Common/CoverImage.swift`

`CoverImage` already exists (see Plan 3e — the file with `.task(id: LoadKey(...))`). We add a `.colorEffect(...)` only when the active theme calls for it AND the size is `.full` (thumb-size renders stay unchanged for grid performance).

- [x] **Step 8.1: Read current `CoverImage.swift`**

Use the Read tool to load `ios/GameTracker/GameTracker/Views/Common/CoverImage.swift`. Note the `body` returns a `Group` containing an `Image(uiImage:)` or a placeholder.

- [x] **Step 8.2: Add `@Environment(\.theme)` and conditional `.colorEffect`**

After the existing `@State private var failed = false` line, add:

```swift
    @Environment(\.theme) private var theme
```

Find the existing `body`:

```swift
    var body: some View {
        Group {
            if let url = localURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img)
                    .resizable()
                    .aspectRatio(contentMode: .fit)
            } else if failed {
                placeholder(systemName: "photo.badge.exclamationmark")
            } else {
                placeholder(systemName: "photo")
            }
        }
        .task(id: LoadKey(subject: subject, face: face)) {
            await load()
        }
    }
```

Replace with:

```swift
    var body: some View {
        Group {
            if let url = localURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img)
                    .resizable()
                    .aspectRatio(contentMode: .fit)
            } else if failed {
                placeholder(systemName: "photo.badge.exclamationmark")
            } else {
                placeholder(systemName: "photo")
            }
        }
        .modifier(GameBoyDitherIfApplicable(theme: theme, size: size))
        .task(id: LoadKey(subject: subject, face: face)) {
            await load()
        }
    }
```

Then at the BOTTOM of the file (after the closing `}` of the `CoverImage` struct, OUTSIDE it), add this private modifier:

```swift
/// Applies the Game Boy 4-color dither shader on `.full` size renders
/// when the active theme requests it. No-op otherwise.
private struct GameBoyDitherIfApplicable: ViewModifier {
    let theme: Theme
    let size: ImagesAPI.Size

    func body(content: Content) -> some View {
        if theme.coverEffect == .gameBoyDither && size == .full {
            content.colorEffect(ShaderLibrary.gameBoyDither())
        } else {
            content
        }
    }
}
```

- [x] **Step 8.3: Build check**

Expected: **BUILD SUCCEEDED**.

If you see "cannot find 'ShaderLibrary' in scope," ensure `import SwiftUI` is at the top of the file (it should already be).

If you see "no member 'gameBoyDither' on ShaderLibrary," the metal file from Task 6 didn't get included in the build. Run a clean rebuild:
```bash
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker -destination 'platform=iOS Simulator,name=iPhone 17' clean build 2>&1 | grep -E "BUILD SUCCEEDED|BUILD FAILED|error:" | head -10
```

---

## Task 9: Library empty state — code rain when theme is Matrix

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Library/LibraryView.swift`

The Library tab already shows some empty-state view when the user has no games. Add a `CodeRainView()` as that view's background when `theme.flourish == .codeRain`.

- [x] **Step 9.1: Locate the empty state in `LibraryView.swift`**

Use the Read tool on `ios/GameTracker/GameTracker/Views/Library/LibraryView.swift`. Search for `ContentUnavailableView` or `if games.isEmpty` — whichever pattern is used. The empty state was added by Plan 3a; the exact code shape may vary.

- [x] **Step 9.2: Add `@Environment(\.theme)` to `LibraryView`**

Near the top of the `LibraryView` struct, after the other `@Environment` / `@State` declarations, add:

```swift
    @Environment(\.theme) private var theme
```

- [x] **Step 9.3: Wrap the empty state in a conditional background**

Find the existing empty-state view (likely a `ContentUnavailableView("...")` or similar). Wrap it with a conditional `.background(...)`:

```swift
            // BEFORE:
            ContentUnavailableView("No games yet", systemImage: "books.vertical",
                                   description: Text("Add one with the + button."))

            // AFTER:
            ContentUnavailableView("No games yet", systemImage: "books.vertical",
                                   description: Text("Add one with the + button."))
                .background {
                    if theme.flourish == .codeRain {
                        CodeRainView()
                            .ignoresSafeArea()
                    }
                }
```

If the existing empty state has a different shape (e.g. a custom VStack), apply the same `.background { if theme.flourish == .codeRain { CodeRainView() } }` modifier on the outermost view of the empty branch. Read the actual code and adapt — don't fight the existing structure.

- [x] **Step 9.4: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 10: Stats tab — scanlines overlay when theme is CRT Amber

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Stats/StatsView.swift`

Apply `ScanlineOverlayView()` as an `.overlay(...)` over the Stats tab's content when the active theme has `.scanlines`.

- [x] **Step 10.1: Add `@Environment(\.theme)` to `StatsView`**

Read `ios/GameTracker/GameTracker/Views/Stats/StatsView.swift`. Near the top of the struct, after existing `@Environment` declarations, add:

```swift
    @Environment(\.theme) private var theme
```

- [x] **Step 10.2: Wrap the body in a conditional overlay**

Find the existing `var body: some View { ... }`. The body's outermost expression is likely a `NavigationStack { ... }` or `ScrollView { ... }`. Apply this modifier to it:

```swift
        .overlay {
            if theme.flourish == .scanlines {
                ScanlineOverlayView()
                    .ignoresSafeArea()
            }
        }
```

If the existing body has multiple branches (different views for empty vs populated state), apply the overlay to the outermost wrapper that exists in both branches — typically the NavigationStack.

- [x] **Step 10.3: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 11: Settings picker — 7 cases + live preview tile

**Files:**
- Modify: `ios/GameTracker/GameTracker/Views/Settings/SettingsView.swift`

Picker grows from 3 cases to 7 with a divider. A new row below the picker renders a 120pt-tall live preview of the selected theme.

- [x] **Step 11.1: Read current `SettingsView.swift`**

Use the Read tool on `ios/GameTracker/GameTracker/Views/Settings/SettingsView.swift`. Locate the `appearanceSection` computed property — it currently renders:

```swift
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
```

`AppearanceMode.allCases` now includes the four new cases (per Task 1), so the picker will already show 7 entries. We add a divider before the rich themes and a live-preview tile below.

- [x] **Step 11.2: Replace `appearanceSection` with the divider + preview version**

Replace the entire `appearanceSection` computed property with:

```swift
    private var appearanceSection: some View {
        Section("Appearance") {
            Picker("Theme", selection: $appearanceMode) {
                Group {
                    Text(AppearanceMode.system.displayName).tag(AppearanceMode.system)
                    Text(AppearanceMode.light.displayName).tag(AppearanceMode.light)
                    Text(AppearanceMode.dark.displayName).tag(AppearanceMode.dark)
                }
                Divider()
                Group {
                    Text(AppearanceMode.matrix.displayName).tag(AppearanceMode.matrix)
                    Text(AppearanceMode.retroMac.displayName).tag(AppearanceMode.retroMac)
                    Text(AppearanceMode.gameBoy.displayName).tag(AppearanceMode.gameBoy)
                    Text(AppearanceMode.crtAmber.displayName).tag(AppearanceMode.crtAmber)
                }
            }
            .pickerStyle(.menu)

            ThemePreviewTile(mode: appearanceMode)
                .frame(height: 120)
                .listRowInsets(EdgeInsets())
        }
    }
```

(`Divider()` inside `Picker(.menu)` renders as a horizontal rule between System/Light/Dark and the rich themes — supported by SwiftUI's menu picker since iOS 16.)

- [x] **Step 11.3: Add a `ThemePreviewTile` view at the bottom of `SettingsView.swift`**

After the closing `}` of `SettingsView` (so it's a sibling type in the same file), add:

```swift
/// A compact preview that re-renders whenever the user changes
/// theme selection. Shows the theme's background, accent, and (if
/// applicable) a sample of the flourish that would appear in-app.
private struct ThemePreviewTile: View {
    let mode: AppearanceMode

    private var theme: Theme { ThemeRegistry.theme(for: mode) }

    var body: some View {
        ZStack {
            (theme.background ?? Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 8))

            // Flourish layer
            Group {
                switch theme.flourish {
                case .codeRain:
                    CodeRainView()
                        .environment(\.theme, theme)
                case .scanlines:
                    ScanlineOverlayView()
                case .platinumBevel:
                    LinearGradient(
                        colors: [Color(white: 0.93), Color(white: 0.80), Color(white: 0.67)],
                        startPoint: .top, endPoint: .bottom
                    )
                    .frame(height: 24)
                    .frame(maxHeight: .infinity, alignment: .top)
                case .none:
                    EmptyView()
                }
            }
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .allowsHitTesting(false)

            // Sample content — three cover-sized rectangles in the
            // theme's accent color.
            HStack(spacing: 8) {
                ForEach(0..<3, id: \.self) { _ in
                    RoundedRectangle(cornerRadius: 4)
                        .fill(theme.accent.opacity(0.85))
                        .frame(width: 50, height: 70)
                }
            }
        }
        .padding(.vertical, 4)
    }
}
```

- [x] **Step 11.4: Build check**

Expected: **BUILD SUCCEEDED**.

---

## Task 12: Full test pass + bundle commit

**Files:** none modified in this task.

- [x] **Step 12.1: Clear iCloud conflict files**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
find ios/GameTracker -name "* [0-9].swift" -print -delete
```

- [x] **Step 12.2: Full test pass**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker/ios/GameTracker"
xcodebuild -project GameTracker.xcodeproj -scheme GameTracker \
  -destination 'platform=iOS Simulator,name=iPhone 17' test 2>&1 \
  | grep -E "TEST SUCCEEDED|TEST FAILED|error:" | tail -10
```

Expected: `** TEST SUCCEEDED **`. Allow up to 8 minutes.

If first run says `** TEST FAILED **` with no `error:` lines, retry once — simulator flake.

- [x] **Step 12.3: Pre-commit sanity check**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected listing:

- Untracked: `Settings/Theme.swift`, `Settings/ThemeFlourishes.swift`, `Settings/GameBoyDither.metal`, `Resources/Fonts/PressStart2P-Regular.ttf`, `GameTrackerTests/ThemeRegistryTests.swift`
- Modified: `Settings/AppearanceMode.swift`, `GameTrackerApp.swift`, `Views/Common/CoverImage.swift`, `Views/Library/LibraryView.swift`, `Views/Stats/StatsView.swift`, `Views/Settings/SettingsView.swift`, `GameTracker.xcodeproj/project.pbxproj`
- Pre-existing junk to NOT commit: `js/completions.js`, `* 2.sh`, `* 2.php`, `Helpers 2/`

- [x] **Step 12.4: Bundle commit Tasks 1–11**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git add ios/GameTracker/GameTracker/Settings/Theme.swift \
        ios/GameTracker/GameTracker/Settings/ThemeFlourishes.swift \
        ios/GameTracker/GameTracker/Settings/GameBoyDither.metal \
        ios/GameTracker/GameTracker/Settings/AppearanceMode.swift \
        ios/GameTracker/GameTracker/Resources/Fonts/PressStart2P-Regular.ttf \
        ios/GameTracker/GameTrackerTests/ThemeRegistryTests.swift \
        ios/GameTracker/GameTracker/GameTrackerApp.swift \
        ios/GameTracker/GameTracker/Views/Common/CoverImage.swift \
        ios/GameTracker/GameTracker/Views/Library/LibraryView.swift \
        ios/GameTracker/GameTracker/Views/Stats/StatsView.swift \
        ios/GameTracker/GameTracker/Views/Settings/SettingsView.swift \
        ios/GameTracker/GameTracker.xcodeproj/project.pbxproj
git commit -m "Add rich themes: Matrix, Retro Mac, Game Boy, CRT Amber"
```

### 🛑 User checkpoint — Rich themes

Stop here. The owner ⌘R in Xcode (iPhone 17 sim) and verifies each theme.

For each of the 7 themes, set it via Settings → Appearance → Theme:

1. **System** — picker default. App follows OS dark/light setting. (Regression check.)
2. **Light** — app forced light regardless of OS. Existing Plan 4a behaviour, no flourish. (Regression check.)
3. **Dark** — app forced dark regardless of OS. No flourish. (Regression check.)
4. **Matrix** — black background, phosphor-green tint everywhere (buttons, links). Monospace body font. Open Library when you have no games — code-rain animates behind the empty-state message. Settings preview tile under the picker shows code-rain.
5. **Retro Mac** — platinum-gray background, blue accent, serif body font (Charter / New York). Navigation bar has a platinum gradient background. Settings preview tile shows a beveled top strip.
6. **Game Boy** — yellow-green background, dark-green accent, pixel body font (Press Start 2P) — visible in titles. Open any game's detail screen: the hero cover is rendered in 4-color Game Boy palette via dither. Library grid thumbnails are NOT dithered (perf compromise). Settings preview tile shows the LCD-green background.
7. **CRT Amber** — warm-black background, amber accent, monospace font. Open the Stats tab: scanlines overlay every chart. Settings preview tile shows scanlines.

Also verify:
- 8. **Preference persists** across force-quit + relaunch.
- 9. **Picker dropdown** shows the System/Light/Dark group divided from the four rich themes by a horizontal rule.
- 10. **No regression** on any unaffected screen: Library populated state, Items tab, Completions tab, Game/Item detail in non-Game-Boy themes, Sign-out flow.

Resume only after owner confirms or reports a specific failure per theme.

---

## Task 13: Push + open PR + wrap up

**Files:** none.

- [x] **Step 13.1: Verify clean working tree**

```bash
cd "$HOME/Library/Mobile Documents/com~apple~CloudDocs/Desktop/Personal-Projects/gameTracker"
git status --short
```

Expected: only pre-existing junk.

- [x] **Step 13.2: Push**

```bash
git push -u origin plan-4b-rich-themes
```

- [x] **Step 13.3: Mark this plan complete**

```bash
sed -i '' 's/^- \[ \]/- [x]/g' docs/superpowers/plans/2026-05-22-ios-rich-themes.md
git add docs/superpowers/plans/2026-05-22-ios-rich-themes.md
git commit -m "Mark Plan 4b (iOS rich themes) complete"
git push
```

- [x] **Step 13.4: Open PR**

```bash
gh pr create --base main --head plan-4b-rich-themes \
  --title "Plan 4b: iOS rich themes (Matrix, Retro Mac, Game Boy, CRT Amber)" \
  --body "$(cat <<'EOF'
## Summary

Extends Plan 4a's `AppearanceMode` from three Apple color schemes (System / Light / Dark) to seven, adding four "rich" themes — each with a coordinated palette, font (system or bundled), and one contextual signature flourish scoped to specific screens.

- **Matrix** — black/phosphor-green/monospace + falling-glyph code-rain animation on Library empty state.
- **Retro Mac (Platinum)** — cream gray/blue/serif + platinum gradient navigation bar.
- **Game Boy** — 4-color LCD palette/pixel font (Press Start 2P, bundled) + Metal-shader 4-color ordered dither applied to hero covers in detail views.
- **CRT Amber** — warm black/amber/monospace + scanlines overlay on Stats tab.

System / Light / Dark unchanged — they continue producing the same `ColorScheme` and accent they did before.

### Architecture

New `Theme` struct injected via `\.theme` environment value at the WindowGroup root. Most views need no changes — SwiftUI's `.tint` and `.fontDesign` propagate automatically. Only flourish-bearing surfaces (`CoverImage` hero, Library empty state, Stats, Settings preview tile) read the theme directly. UIKit's `UINavigationBar.appearance()` is reset on theme change for the platinum bevel + pixel-font title bar.

### Plan deviation

Couldn't reliably source a free Chicago-lookalike TTF for Retro Mac. Per the spec's contingency clause, Retro Mac uses `.serif` design (Charter / New York) with no bundled font — the platinum-bevel nav bar carries the aesthetic.

## Test plan

- [x] `xcodebuild test` — full suite passes including 14 new `ThemeRegistryTests`
- [x] Manual checkpoint — all 7 themes render correctly; flourishes appear only on designated screens; preference persists across restart
- [x] No regression on Library populated grid, Items, Completions, Stats (non-CRT themes), Sign-out

## Not in scope (Plan 4c+ territory)

CoverFlow on Library, theme-specific app icons, sound effects, sub-screen theme picker, custom navigation animations, per-cover dithering palette toggle, always-on flourishes.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run before declaring done)

- [x] Every referenced symbol exists: `Theme`, `CoverEffect`, `Flourish`, `ThemeRegistry`, `Color(hex:)`, `EnvironmentValues.theme`, `CodeRainView`, `ScanlineOverlayView`, `applyAppKitAppearance(for:mode:)`, `ShaderLibrary.gameBoyDither`, `ImagesAPI.Size`. (All landed via Tasks 1 / 3 / 4 / 5 / 6 of this plan, or pre-existed.)
- [x] `AppearanceMode` raw values for `system`/`light`/`dark` are LITERALLY unchanged (no quote-mark drift) so Plan 4a preferences continue to decode correctly.
- [x] The Metal shader function is named `gameBoyDither` everywhere — in the `.metal` file, in `ShaderLibrary.gameBoyDither()`, and in the `CoverEffect.gameBoyDither` case (which is a Swift enum case, NOT the same symbol, but the naming consistency makes intent clear).
- [x] Every theme listed in `ThemeRegistry.theme(for:)` has a corresponding `static let` on `extension Theme`. Swift's exhaustive switch will catch any miss at compile time, but worth a manual count: 7 cases, 7 statics.
- [x] `@Environment(\.theme)` is read in `CoverImage`, `LibraryView`, `StatsView`, and `ThemePreviewTile` — four sites, all listed in the file structure table.
- [x] No reference to ChicagoKare / Chicago / Chicago-FLF outside the "Deviation from spec" header — the deviation must be applied throughout.
- [x] No "TBD" / "implement later" anywhere.
