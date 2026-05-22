import SwiftUI
import UIKit

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

// MARK: - applyAppKitAppearance (Retro Mac flourish + global font hook)

/// Applies UIKit appearance proxies based on the theme. Called from
/// `GameTrackerApp.body` whenever the chosen theme changes — UIKit
/// appearance is global mutable state, so it's set imperatively.
///
/// - Retro Mac: navigation bar background is a 3-stop platinum
///   gradient with a 1pt dark separator line.
/// - Game Boy: navigation bar title font is Press Start 2P.
/// - Otherwise: the appearance is overwritten with a freshly
///   `configureWithDefaultBackground()`-initialized
///   `UINavigationBarAppearance` — this clears any prior gradient
///   or title-font side effects from earlier theme selections.
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

    // Clear List/Form row backgrounds when the theme has a custom
    // background, so the underlying theme color shows through.
    // `.scrollContentBackground(.hidden)` doesn't clear row chrome —
    // we have to reach into UIKit appearance for that.
    let clearRows = theme.background != nil
    UITableView.appearance().backgroundColor = clearRows ? .clear : nil
    UITableViewCell.appearance().backgroundColor = clearRows ? .clear : nil
    UICollectionView.appearance().backgroundColor = clearRows ? .clear : nil
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

// MARK: - .themedBackground() modifier

/// Makes the underlying theme background visible by hiding default
/// Form/List/ScrollView surfaces and painting `theme.background`
/// (or the system semantic background if the theme doesn't override).
///
/// Apply on each top-level tab view + modal sheet root.
extension View {
    func themedBackground() -> some View {
        modifier(ThemedBackground())
    }
}

private struct ThemedBackground: ViewModifier {
    @Environment(\.theme) private var theme

    func body(content: Content) -> some View {
        content
            .scrollContentBackground(.hidden)
            .background((theme.background ?? Color(.systemBackground)).ignoresSafeArea())
    }
}
