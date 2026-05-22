import SwiftUI
import SwiftData

struct AddCompletionView: View {
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var pickedGame: Game?
    @State private var dateStarted: Date = Date()
    @State private var hasStartDate: Bool = false
    @State private var dateCompleted: Date = Date()
    @State private var hasDate: Bool = true
    @State private var timeTaken: String = ""
    @State private var notes: String = ""
    @State private var showGamePicker = false

    private var canSave: Bool { pickedGame != nil }

    var body: some View {
        NavigationStack {
            Form {
                CompletionFormBody(pickedGame: $pickedGame,
                                   dateStarted: $dateStarted,
                                   hasStartDate: $hasStartDate,
                                   dateCompleted: $dateCompleted,
                                   hasDate: $hasDate,
                                   timeTaken: $timeTaken,
                                   notes: $notes,
                                   imagesAPI: imagesAPI,
                                   onTapGame: { showGamePicker = true })
            }
            .navigationTitle("Log a completion")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(!canSave)
                }
            }
            .sheet(isPresented: $showGamePicker) {
                GamePickerSheet(onPick: { pickedGame = $0 }, imagesAPI: imagesAPI)
            }
        }
        .themedBackground()
    }

    private func save() {
        guard let game = pickedGame else { return }
        let c = GameCompletion(title: game.title, syncState: .localNew)
        c.gameServerId   = game.serverId
        c.platform       = game.platform
        c.dateStarted    = hasStartDate ? dateStarted : nil
        c.dateCompleted  = hasDate ? dateCompleted : nil
        c.completionYear = hasDate ? Calendar.current.component(.year, from: dateCompleted) : nil
        c.timeTaken      = timeTaken.isEmpty ? nil : timeTaken
        c.notes          = notes.isEmpty ? nil : notes
        context.insert(c)
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
