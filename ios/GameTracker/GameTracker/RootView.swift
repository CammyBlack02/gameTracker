import SwiftUI

struct RootView: View {
    @Environment(AuthManager.self) private var authManager
    let authAPI: AuthAPI
    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    @Bindable var status: SyncStatus

    var body: some View {
        switch authManager.state {
        case .loggedOut:
            LoginView(authAPI: authAPI)
        case .loggedIn:
            RootTabView(syncEngine: syncEngine,
                        syncTrigger: syncTrigger,
                        imagesAPI: imagesAPI,
                        proxiesAPI: proxiesAPI,
                        authAPI: authAPI,
                        status: status)
        }
    }
}
