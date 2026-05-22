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
