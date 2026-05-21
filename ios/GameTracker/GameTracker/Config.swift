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

    /// Toggle verbose URLSession logging in debug builds.
    static let verboseNetworking = true
}
