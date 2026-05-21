import Foundation
import SwiftUI
import Observation

/// Source of truth for "is the user logged in?" — backed by the Keychain.
/// Observable so SwiftUI views auto-refresh when login state changes.
@Observable
final class AuthManager {

    enum State {
        case loggedOut
        case loggedIn(userId: Int, username: String)
    }

    private(set) var state: State = .loggedOut

    private let store: KeychainTokenStore
    /// Cached in-memory token so we don't hit the Keychain on every request.
    private var cachedToken: String?

    init(store: KeychainTokenStore = KeychainTokenStore()) {
        self.store = store
        loadFromKeychain()
    }

    /// Synchronous accessor used by APIClient's `tokenProvider`. Reads the
    /// in-memory cache to avoid sync-on-every-request keychain hits.
    var currentToken: String? { cachedToken }

    /// Load the saved token + user info at app launch.
    private func loadFromKeychain() {
        guard let token = (try? store.load()) ?? nil else { return }
        cachedToken = token
        // user_id and username are stored separately in UserDefaults (non-sensitive)
        let ud = UserDefaults.standard
        let uid = ud.integer(forKey: "gt.userId")
        let uname = ud.string(forKey: "gt.username") ?? ""
        if uid > 0 {
            state = .loggedIn(userId: uid, username: uname)
        }
    }

    /// Persist a fresh login result.
    func setLoggedIn(token: String, userId: Int, username: String) {
        try? store.save(token: token)
        cachedToken = token
        UserDefaults.standard.set(userId, forKey: "gt.userId")
        UserDefaults.standard.set(username, forKey: "gt.username")
        state = .loggedIn(userId: userId, username: username)
    }

    /// Clear local credentials. Caller is responsible for separately invoking
    /// AuthAPI.revoke() and wiping the local SwiftData store.
    func clearLocalSession() {
        try? store.delete()
        cachedToken = nil
        UserDefaults.standard.removeObject(forKey: "gt.userId")
        UserDefaults.standard.removeObject(forKey: "gt.username")
        state = .loggedOut
    }
}
