import Foundation
import SwiftData

/// Builds the SwiftData container that's attached to the app's environment.
/// Centralised so the production code and tests use the same schema.
enum ModelContainerFactory {
    /// All `@Model` types in the app. SwiftData uses this list to build
    /// the underlying SQLite schema.
    static let schema = Schema([
        Game.self,
        Item.self,
        GameCompletion.self,
        GameImage.self,
        ItemImage.self,
        SyncMetadata.self,
    ])

    /// On-disk container for production.
    static func production() throws -> ModelContainer {
        let config = ModelConfiguration(schema: schema, isStoredInMemoryOnly: false)
        return try ModelContainer(for: schema, configurations: [config])
    }

    /// In-memory container for unit tests. Each call returns a fresh DB.
    static func inMemory() throws -> ModelContainer {
        let config = ModelConfiguration(schema: schema, isStoredInMemoryOnly: true)
        return try ModelContainer(for: schema, configurations: [config])
    }
}
