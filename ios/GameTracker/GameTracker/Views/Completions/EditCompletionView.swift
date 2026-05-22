import SwiftUI
import SwiftData

struct EditCompletionView: View {
    let completionID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var pickedGame: Game?
    @State private var dateCompleted: Date = Date()
    @State private var hasDate: Bool = false
    @State private var timeTaken: String = ""
    @State private var notes: String = ""
    @State private var loaded = false

    private var canSave: Bool { pickedGame != nil }

    var body: some View {
        NavigationStack {
            Form {
                CompletionFormBody(pickedGame: $pickedGame,
                                   dateCompleted: $dateCompleted,
                                   hasDate: $hasDate,
                                   timeTaken: $timeTaken,
                                   notes: $notes,
                                   imagesAPI: imagesAPI)
            }
            .navigationTitle("Edit completion")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(!canSave)
                }
            }
            .task { loadOnce() }
        }
    }

    private func loadOnce() {
        guard !loaded, let c: GameCompletion = context.model(for: completionID) as? GameCompletion else { return }
        if let sid = c.gameServerId {
            let p = #Predicate<Game> { $0.serverId == sid }
            pickedGame = (try? context.fetch(FetchDescriptor(predicate: p)))?.first
        }
        if let d = c.dateCompleted {
            dateCompleted = d
            hasDate = true
        } else {
            hasDate = false
        }
        timeTaken = c.timeTaken ?? ""
        notes     = c.notes ?? ""
        loaded = true
    }

    private func save() {
        guard let c: GameCompletion = context.model(for: completionID) as? GameCompletion,
              let game = pickedGame else { return }
        c.title          = game.title
        c.platform       = game.platform
        c.gameServerId   = game.serverId
        c.dateCompleted  = hasDate ? dateCompleted : nil
        c.completionYear = hasDate ? Calendar.current.component(.year, from: dateCompleted) : nil
        c.timeTaken      = timeTaken.isEmpty ? nil : timeTaken
        c.notes          = notes.isEmpty ? nil : notes
        if c.syncState == .synced { c.syncState = .localModified }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
