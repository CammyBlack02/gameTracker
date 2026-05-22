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
