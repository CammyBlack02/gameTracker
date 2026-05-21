import Foundation

/// Auth endpoints: login (issue token) and revoke (logout).
struct AuthAPI {
    let client: APIClient

    /// POST /api/v2/auth/token.php
    /// Returns the freshly issued token + user info.
    func login(username: String, password: String, deviceName: String?) async throws -> TokenResponseDTO {
        var fields: [String: String] = ["username": username, "password": password]
        if let device = deviceName { fields["device_name"] = device }
        return try await client.postForm("/api/v2/auth/token.php", fields: fields)
    }

    /// POST /api/v2/auth/revoke.php — invalidates the *currently used* Bearer token.
    func revoke() async throws -> RevokeResponseDTO {
        return try await client.postForm("/api/v2/auth/revoke.php", fields: [:])
    }
}
