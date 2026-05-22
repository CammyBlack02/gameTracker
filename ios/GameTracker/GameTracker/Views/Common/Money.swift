import Foundation

/// Manual USD→GBP rate. Update as needed; promotable to a Settings
/// entry later if the user wants direct control. All call sites go
/// through `usdToGBP(_:)` so a refactor here propagates everywhere.
let USD_TO_GBP_RATE: Double = 0.78

/// Converts a USD amount into approximate GBP using the constant above.
/// No-op rounding; downstream formatter handles the display precision.
func usdToGBP(_ usd: Double) -> Double {
    usd * USD_TO_GBP_RATE
}

/// Formats a GBP amount as a localised currency string with £ prefix.
/// `1234.56` → `"£1,234.56"`. Falls back to `String(format:)` only if
/// the system formatter fails (extremely unlikely on iOS).
func formatGBP(_ amount: Double) -> String {
    let f = NumberFormatter()
    f.numberStyle = .currency
    f.currencyCode = "GBP"
    f.locale = Locale(identifier: "en_GB")
    return f.string(from: NSNumber(value: amount)) ?? String(format: "£%.2f", amount)
}
