import SwiftUI
import SwiftData
import UIKit

struct AddGameView: View {
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var title = ""
    @State private var platform = ""
    @State private var genre = ""
    @State private var pricechartingPrice = ""
    @State private var metacritic: Int = 0
    @State private var fetchInFlight = false
    @State private var saveInFlight = false
    @State private var errorMessage: String?
    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil   // always nil for Add
    @State private var existingBackImage: String? = nil    // always nil for Add

    private var canSave: Bool {
        !title.isEmpty && !platform.isEmpty && !saveInFlight
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Required") {
                    TextField("Title", text: $title)
                    TextField("Platform", text: $platform)
                }

                CoverImagePickerSection(pendingNewImage: $pendingNewFrontImage,
                                        existingImageString: $existingFrontImage,
                                        kind: .game,
                                        serverId: nil,
                                        face: .front,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Front cover")

                CoverImagePickerSection(pendingNewImage: $pendingNewBackImage,
                                        existingImageString: $existingBackImage,
                                        kind: .game,
                                        serverId: nil,
                                        face: .back,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Back cover")

                Section {
                    Button {
                        Task { await fetchMetadata() }
                    } label: {
                        if fetchInFlight {
                            HStack { ProgressView(); Text("Fetching…") }
                        } else {
                            Label("Fetch metadata (PriceCharting + Metacritic)",
                                  systemImage: "magnifyingglass")
                        }
                    }
                    .disabled(title.isEmpty || platform.isEmpty || fetchInFlight)

                    if !genre.isEmpty           { TextField("Genre", text: $genre) }
                    if !pricechartingPrice.isEmpty {
                        TextField("PriceCharting price", text: $pricechartingPrice)
                    }
                    if metacritic > 0 {
                        Stepper(value: $metacritic, in: 0...100) {
                            Text("Metacritic: \(metacritic)")
                        }
                    }
                }

                if let err = errorMessage {
                    Section { Text(err).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Add game")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }
                        .disabled(!canSave)
                }
            }
        }
    }

    // MARK: - Metadata fetch

    private func fetchMetadata() async {
        errorMessage = nil
        fetchInFlight = true
        defer { fetchInFlight = false }
        do {
            async let pc = proxiesAPI.priceCharting(title: title, platform: platform)
            async let mc = proxiesAPI.metacritic(title: title, platform: platform)
            let (pcRes, mcRes) = try await (pc, mc)

            if let g = pcRes["genre"]?.stringValue, !g.isEmpty { genre = g }
            if let p = pcRes["price"]?.stringValue { pricechartingPrice = p }
            // v1 metacritic returns `rating` (0–100), not `score`.
            if let r = mcRes["rating"]?.intValue { metacritic = r }
        } catch {
            errorMessage = "Metadata fetch failed: \(error.localizedDescription)"
        }
    }

    // MARK: - Save

    private func save() async {
        errorMessage = nil
        saveInFlight = true
        defer { saveInFlight = false }

        // 1. Insert local row (localNew). SyncEngine will push it on next runOnce.
        let game = Game(title: title, platform: platform, syncState: .localNew)
        game.genre = genre.isEmpty ? nil : genre
        game.pricechartingPrice = Double(pricechartingPrice)
        game.metacriticRating = metacritic == 0 ? nil : metacritic
        context.insert(game)

        do {
            try context.save()
        } catch {
            errorMessage = "Save failed: \(error.localizedDescription)"
            return
        }

        // 2. Encode any picked images as data URIs into the new game's
        // image columns. The sync push delivers them with the new row.
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            game.frontCoverImage = dataURI
        }
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            game.backCoverImage = dataURI
        }
        try? context.save()

        // 3. Trigger immediate-ish sync so we get the server_id back ASAP.
        syncTrigger.pingAfterMutation()

        dismiss()
    }
}
