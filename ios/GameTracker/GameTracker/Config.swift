import Foundation

/// Compile-time / launch-time configuration. Single source of truth so
/// individual call sites don't hard-code the server URL.
enum Config {
    /// Base URL for the deployed `/api/v2/` endpoints. Override at runtime
    /// by setting `GT_SERVER_BASE_URL` in the scheme's environment.
    static var serverBaseURL: URL {
        if let override = ProcessInfo.processInfo.environment["GT_SERVER_BASE_URL"],
           let url = URL(string: override) {
            return url
        }
        return URL(string: "https://cammysgametracker.duckdns.org")!
    }

    /// Convenience helper for building `/api/v2/...` URLs.
    static func v2(_ path: String) -> URL {
        serverBaseURL.appendingPathComponent("api/v2").appendingPathComponent(path)
    }

    /// Verbose URLSession logging — DEBUG builds only. Fable §9 quick win:
    /// release builds shouldn't ship the extra logging path.
    #if DEBUG
    static let verboseNetworking = true
    #else
    static let verboseNetworking = false
    #endif
}
