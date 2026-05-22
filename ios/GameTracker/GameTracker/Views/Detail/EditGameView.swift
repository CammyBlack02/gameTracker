import SwiftUI
import SwiftData
import UIKit

struct EditGameView: View {
    let gameID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    // Working copies (we don't mutate the live model until "Save"
    // so cancel discards cleanly).
    @State private var title = ""
    @State private var platform = ""
    @State private var genre = ""
    @State private var series = ""
    @State private var condition = ""
    @State private var starRating: Int = 0
    @State private var metacritic: Int = 0
    @State private var played = false
    @State private var isPhysical = true
    @State private var digitalStore = ""
    @State private var pricePaid = ""
    @State private var pricechartingPrice = ""
    @State private var description = ""
    @State private var review = ""

    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil
    @State private var existingBackImage: String? = nil
    @State private var loaded = false

    private var currentGameServerId: Int? {
        (context.model(for: gameID) as? Game)?.serverId
    }

    var body: some View {
        NavigationStack {
            Form {
                CoverImagePickerSection(pendingNewImage: $pendingNewFrontImage,
                                        existingImageString: $existingFrontImage,
                                        kind: .game,
                                        serverId: currentGameServerId,
                                        face: .front,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Front cover")

                CoverImagePickerSection(pendingNewImage: $pendingNewBackImage,
                                        existingImageString: $existingBackImage,
                                        kind: .game,
                                        serverId: currentGameServerId,
                                        face: .back,
                                        imagesAPI: imagesAPI,
                                        sectionTitle: "Back cover")

                Section("Basics") {
                    TextField("Title", text: $title)
                    TextField("Platform", text: $platform)
                    TextField("Genre", text: $genre)
                    TextField("Series", text: $series)
                    TextField("Condition", text: $condition)
                }

                Section("Status") {
                    Toggle("Played", isOn: $played)
                    Stepper(value: $starRating, in: 0...10) {
                        Text("Stars: \(starRating)/10")
                    }
                    Stepper(value: $metacritic, in: 0...100) {
                        Text("Metacritic: \(metacritic)")
                    }
                }

                Section("Format") {
                    Toggle("Physical", isOn: $isPhysical)
                    if !isPhysical {
                        TextField("Digital store", text: $digitalStore)
                    }
                }

                Section("Price") {
                    TextField("Paid", text: $pricePaid).keyboardType(.decimalPad)
                    TextField("PriceCharting", text: $pricechartingPrice).keyboardType(.decimalPad)
                }

                Section("Notes") {
                    TextField("Description", text: $description, axis: .vertical).lineLimit(3...8)
                    TextField("Review", text: $review, axis: .vertical).lineLimit(3...8)
                }
            }
            .navigationTitle("Edit")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(title.isEmpty || platform.isEmpty)
                }
            }
            .task { loadOnce() }
            .themedBackground()
        }
    }

    private func loadOnce() {
        guard !loaded, let g: Game = context.model(for: gameID) as? Game else { return }
        title = g.title
        platform = g.platform
        genre = g.genre ?? ""
        series = g.series ?? ""
        condition = g.conditionValue ?? ""
        starRating = g.starRating ?? 0
        metacritic = g.metacriticRating ?? 0
        played = (g.played == 1)
        isPhysical = (g.isPhysical == 1)
        digitalStore = g.digitalStore ?? ""
        pricePaid = g.pricePaid.map { String(format: "%.2f", $0) } ?? ""
        pricechartingPrice = g.pricechartingPrice.map { String(format: "%.2f", $0) } ?? ""
        description = g.gameDescription ?? ""
        review = g.review ?? ""
        existingFrontImage = g.frontCoverImage
        existingBackImage  = g.backCoverImage
        loaded = true
    }

    private func save() {
        guard let g: Game = context.model(for: gameID) as? Game else { return }
        g.title = title
        g.platform = platform
        g.genre = genre.isEmpty ? nil : genre
        g.series = series.isEmpty ? nil : series
        g.conditionValue = condition.isEmpty ? nil : condition
        g.starRating = starRating == 0 ? nil : starRating
        g.metacriticRating = metacritic == 0 ? nil : metacritic
        g.played = played ? 1 : 0
        g.isPhysical = isPhysical ? 1 : 0
        g.digitalStore = (isPhysical || digitalStore.isEmpty) ? nil : digitalStore
        g.pricePaid = Double(pricePaid)
        g.pricechartingPrice = Double(pricechartingPrice)
        g.gameDescription = description.isEmpty ? nil : description
        g.review = review.isEmpty ? nil : review

        // Image upload (front): encode new pick → data URI; remove
        // clears the column; otherwise preserve existing string.
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            g.frontCoverImage = dataURI
        } else if pendingNewFrontImage == nil && existingFrontImage == nil && g.frontCoverImage != nil {
            g.frontCoverImage = nil
        }

        // Image upload (back): same rules.
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            g.backCoverImage = dataURI
        } else if pendingNewBackImage == nil && existingBackImage == nil && g.backCoverImage != nil {
            g.backCoverImage = nil
        }

        if g.syncState == .synced { g.syncState = .localModified }
        try? context.save()

        if let serverId = g.serverId {
            imagesAPI.invalidateGameCover(gameServerId: serverId)
        }

        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
