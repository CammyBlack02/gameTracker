import Foundation

/// Errors thrown by `APIClient`. Distinguishes transport failures, server
/// errors (decoded from the JSON envelope), and decoding failures.
enum APIError: Error, LocalizedError {
    /// URLSession transport-level failure (offline, TLS, etc.).
    case transport(URLError)
    /// Server returned a JSON error envelope.
    case server(code: String, message: String?, status: Int)
    /// Response body couldn't be decoded into the expected type.
    case decoding(String)
    /// Response had a status we didn't recognise (no JSON envelope).
    case unexpected(status: Int, bodyPrefix: String)

    var errorDescription: String? {
        switch self {
        case .transport(let e):                 return e.localizedDescription
        case .server(let code, let msg, _):     return msg ?? code
        case .decoding(let detail):             return "Decoding failed: \(detail)"
        case .unexpected(let status, let body): return "HTTP \(status): \(body)"
        }
    }

    /// Convenience for AuthManager: true if the user needs to log in again.
    var isAuthFailure: Bool {
        if case .server(let code, _, _) = self {
            return code == "invalid_token" || code == "missing_token"
        }
        return false
    }
}
