import SwiftUI

struct RootView: View {
    @Environment(AuthManager.self) private var authManager
    let authAPI: AuthAPI
    let syncEngine: SyncEngine
    @Bindable var status: SyncStatus

    var body: some View {
        switch authManager.state {
        case .loggedOut:
            LoginView(authAPI: authAPI)
        case .loggedIn:
            DebugHomeView(syncEngine: syncEngine, status: status)
        }
    }
}
