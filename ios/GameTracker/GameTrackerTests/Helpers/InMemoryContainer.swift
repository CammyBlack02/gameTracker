import Foundation
import SwiftData
@testable import GameTracker

enum InMemoryContainer {
    /// A fresh in-memory container + a `ModelContext` ready to use.
    /// Each call returns isolated storage — tests can't interfere with each other.
    static func make() throws -> (ModelContainer, ModelContext) {
        let container = try ModelContainerFactory.inMemory()
        let context = ModelContext(container)
        return (container, context)
    }
}
