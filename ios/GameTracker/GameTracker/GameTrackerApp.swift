import SwiftUI
import SwiftData

@main
struct GameTrackerApp: App {
    let container: ModelContainer = {
        do {
            return try ModelContainerFactory.production()
        } catch {
            fatalError("Could not create SwiftData container: \(error)")
        }
    }()

    @State private var authManager = AuthManager()
    @State private var status = SyncStatus()

    private var apiClient: APIClient {
        APIClient(baseURL: Config.serverBaseURL,
                  tokenProvider: { [authManager] in authManager.currentToken })
    }

    private var authAPI: AuthAPI { AuthAPI(client: apiClient) }
    private var syncAPI: SyncAPI { SyncAPI(client: apiClient) }

    var body: some Scene {
        WindowGroup {
            RootViewContainer(authAPI: authAPI, syncAPI: syncAPI, status: status)
                .environment(authManager)
        }
        .modelContainer(container)
    }
}

/// Wraps RootView so we can grab the `modelContext` from the environment
/// (which is only injected *after* `.modelContainer(...)`) and build the
/// SyncEngine with it.
private struct RootViewContainer: View {
    @Environment(\.modelContext) private var context
    let authAPI: AuthAPI
    let syncAPI: SyncAPI
    @Bindable var status: SyncStatus

    var body: some View {
        RootView(authAPI: authAPI,
                 syncEngine: SyncEngine(context: context, syncAPI: syncAPI, status: status),
                 status: status)
    }
}
