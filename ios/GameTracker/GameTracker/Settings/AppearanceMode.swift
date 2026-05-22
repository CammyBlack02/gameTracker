import SwiftUI

/// Persisted user preference for the app's color scheme. Stored in
/// UserDefaults under `"appearanceMode"` via `@AppStorage`.
///
/// Plan 4b will extend the case list with rich themes (Matrix, retro
/// Mac, etc.). The raw values `"system" | "light" | "dark"` are stable
/// across plans — adding new cases never invalidates an existing
/// preference.
enum AppearanceMode: String, CaseIterable, Identifiable {
    case system
    case light
    case dark

    var id: Self { self }

    /// Display label shown in the Settings picker.
    var displayName: String {
        switch self {
        case .system: return "System"
        case .light:  return "Light"
        case .dark:   return "Dark"
        }
    }

    /// Passed to a root-level `.preferredColorScheme(...)` modifier.
    /// `nil` means "no override — follow OS appearance."
    var colorScheme: ColorScheme? {
        switch self {
        case .system: return nil
        case .light:  return .light
        case .dark:   return .dark
        }
    }
}
