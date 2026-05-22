import SwiftUI

/// Shared form fields for Add/Edit completion. The owning view supplies
/// bindings plus an `imagesAPI` for the picker's cover thumbs.
struct CompletionFormBody: View {
    @Binding var pickedGame: Game?
    @Binding var dateCompleted: Date
    @Binding var hasDate: Bool
    @Binding var timeTaken: String
    @Binding var notes: String
    let imagesAPI: ImagesAPI

    @State private var showGamePicker = false

    var body: some View {
        Group {
            Section("Game") {
                Button {
                    showGamePicker = true
                } label: {
                    HStack {
                        if let g = pickedGame {
                            CoverImage(gameServerId: g.serverId, face: .front, size: .thumb, api: imagesAPI)
                                .frame(width: 32, height: 48)
                                .clipShape(RoundedRectangle(cornerRadius: 4))
                            VStack(alignment: .leading, spacing: 2) {
                                Text(g.title).font(.body.weight(.medium)).lineLimit(2)
                                Text(g.platform).font(.caption).foregroundStyle(.secondary)
                            }
                        } else {
                            Image(systemName: "gamecontroller").foregroundStyle(.secondary)
                            Text("Choose a game…").foregroundStyle(.secondary)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }

            Section("When") {
                Toggle("Set a completion date", isOn: $hasDate)
                if hasDate {
                    DatePicker("Completed",
                               selection: $dateCompleted,
                               displayedComponents: .date)
                }
            }

            Section("Details") {
                TextField("Time taken (e.g. 20h 30m)", text: $timeTaken)
                TextField("Notes", text: $notes, axis: .vertical).lineLimit(3...10)
            }
        }
        .sheet(isPresented: $showGamePicker) {
            GamePickerSheet(onPick: { pickedGame = $0 }, imagesAPI: imagesAPI)
        }
    }
}
