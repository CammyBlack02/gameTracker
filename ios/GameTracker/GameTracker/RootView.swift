import SwiftUI

/// Top-level switcher: shows LoginView when logged-out, DebugHomeView
/// (a placeholder for the real tabs) when logged-in.
struct RootView: View {
    @Environment(AuthManager.self) private var authManager
    let authAPI: AuthAPI

    var body: some View {
        switch authManager.state {
        case .loggedOut:
            LoginView(authAPI: authAPI)
        case .loggedIn:
            DebugHomeView()
        }
    }
}
