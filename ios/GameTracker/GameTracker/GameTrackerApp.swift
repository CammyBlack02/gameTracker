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

    var body: some Scene {
        WindowGroup {
            ContentView()
        }
        .modelContainer(container)
    }
}
