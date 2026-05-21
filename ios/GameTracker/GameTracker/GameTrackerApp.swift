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

    /// Built lazily so it always reads the current token from `authManager`.
    private var apiClient: APIClient {
        APIClient(baseURL: Config.serverBaseURL,
                  tokenProvider: { [authManager] in authManager.currentToken })
    }

    private var authAPI: AuthAPI { AuthAPI(client: apiClient) }

    var body: some Scene {
        WindowGroup {
            RootView(authAPI: authAPI)
                .environment(authManager)
        }
        .modelContainer(container)
    }
}
