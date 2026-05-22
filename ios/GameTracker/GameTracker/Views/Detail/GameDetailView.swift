import SwiftUI
import SwiftData
import PhotosUI

struct GameDetailView: View {
    let gameID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var showEdit = false
    @State private var confirmDelete = false
    @State private var showCoverURL = false
    @State private var coverURLInput = ""
    @State private var coverURLInFlight = false
    @State private var coverURLError: String?
    @State private var photoItem: PhotosPickerItem?
    @State private var photoInFlight = false
    @State private var photoError: String?
    @State private var showingBack = false

    var body: some View {
        if let game: Game = context.model(for: gameID) as? Game {
            content(for: game)
                .navigationTitle(game.title)
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .topBarTrailing) {
                        Button("Edit") { showEdit = true }
                    }
                }
                .sheet(isPresented: $showEdit) {
                    EditGameView(gameID: gameID,
                                 imagesAPI: imagesAPI,
                                 syncTrigger: syncTrigger)
                }
                .alert("Delete this game?", isPresented: $confirmDelete) {
                    Button("Delete", role: .destructive) { delete(game) }
                    Button("Cancel", role: .cancel) {}
                } message: {
                    Text("This will remove the game from your library on phone and server.")
                }
        } else {
            ContentUnavailableView("Game unavailable", systemImage: "questionmark.circle")
        }
    }

    @ViewBuilder
    private func content(for game: Game) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                CoverImage(gameServerId: game.serverId,
                           face: showingBack ? .back : .front,
                           size: .full,
                           api: imagesAPI)
                    .frame(maxWidth: .infinity)
                    .frame(maxHeight: 320)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        showingBack.toggle()
                    }

                ExtrasGallery(extras: extras(for: game), imagesAPI: imagesAPI)

                Group {
                    field("Title", game.title)
                    field("Platform", game.platform)
                    field("Genre", game.genre)
                    field("Series", game.series)
                    field("Edition", game.specialEdition)
                    field("Condition", game.conditionValue)
                    field("Star rating", game.starRating.map { "\($0)/10" })
                    field("Metacritic", game.metacriticRating.map(String.init))
                    field("Played", game.played == 1 ? "Yes" : "No")
                    field("Physical", game.isPhysical == 1 ? "Yes" : "Digital")
                    if game.isPhysical == 0 {
                        field("Store", game.digitalStore)
                    }
                    field("Price paid", game.pricePaid.map { formatGBP($0) })
                    field("Pricecharting", game.pricechartingPrice.map { formatGBP(usdToGBP($0)) })
                    field("Released", game.releaseDate.map { d in
                        d.formatted(date: .abbreviated, time: .omitted)
                    })
                }
                .padding(.horizontal)

                if let desc = game.gameDescription, !desc.isEmpty {
                    section("Description", text: desc)
                }
                if let review = game.review, !review.isEmpty {
                    section("Review", text: review)
                }

                completionsList(for: game)

                Button(role: .destructive) { confirmDelete = true } label: {
                    Label("Delete game", systemImage: "trash")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .padding()

                if game.serverId != nil {
                    Button {
                        coverURLInput = ""
                        coverURLError = nil
                        showCoverURL = true
                    } label: {
                        Label("Set cover from URL…", systemImage: "link")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .padding(.horizontal)

                    PhotosPicker(selection: $photoItem, matching: .images) {
                        Label("Upload cover photo…", systemImage: "photo.on.rectangle")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .padding(.horizontal)
                    .disabled(photoInFlight)

                    if let err = photoError {
                        Text(err).font(.caption).foregroundStyle(.red).padding(.horizontal)
                    }
                }
            }
        }
        .sheet(isPresented: $showCoverURL) {
            coverURLSheet(for: game)
        }
        .onChange(of: photoItem) { _, newItem in
            guard let item = newItem else { return }
            Task { await uploadPhoto(item, for: game) }
        }
    }

    @ViewBuilder
    private func field(_ label: String, _ value: String?) -> some View {
        if let v = value, !v.isEmpty {
            HStack(alignment: .top) {
                Text(label).foregroundStyle(.secondary).frame(width: 110, alignment: .leading)
                Text(v)
                Spacer()
            }
            .font(.callout)
        }
    }

    @ViewBuilder
    private func section(_ title: String, text: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title).font(.headline)
            Text(text).font(.callout)
        }
        .padding(.horizontal)
        .padding(.top, 8)
    }

    private func extras(for game: Game) -> [GameImage] {
        guard let sid = game.serverId else { return [] }
        let p = #Predicate<GameImage> { $0.gameServerId == sid }
        return (try? context.fetch(FetchDescriptor(predicate: p))) ?? []
    }

    /// Read-only list of GameCompletion entries linked to this game's
    /// server_id. v1 doesn't support adding/editing completions inline
    /// (deferred to Plan 3b).
    @ViewBuilder
    private func completionsList(for game: Game) -> some View {
        let entries = completions(for: game)
        if !entries.isEmpty {
            VStack(alignment: .leading, spacing: 6) {
                Text("Completions").font(.headline)
                ForEach(entries) { c in
                    VStack(alignment: .leading, spacing: 2) {
                        HStack {
                            Text(c.dateCompleted.map { $0.formatted(date: .abbreviated, time: .omitted) }
                                  ?? "Unknown date")
                                .font(.callout.weight(.medium))
                            if let t = c.timeTaken, !t.isEmpty {
                                Text("· \(t)").font(.callout).foregroundStyle(.secondary)
                            }
                        }
                        if let notes = c.notes, !notes.isEmpty {
                            Text(notes).font(.caption).foregroundStyle(.secondary)
                        }
                    }
                    .padding(.vertical, 4)
                }
            }
            .padding(.horizontal)
            .padding(.top, 8)
        }
    }

    private func completions(for game: Game) -> [GameCompletion] {
        guard let sid = game.serverId else { return [] }
        let p = #Predicate<GameCompletion> { $0.gameServerId == sid }
        let descriptor = FetchDescriptor<GameCompletion>(
            predicate: p,
            sortBy: [SortDescriptor(\.dateCompleted, order: .reverse)]
        )
        return (try? context.fetch(descriptor)) ?? []
    }

    private func delete(_ game: Game) {
        if game.serverId == nil {
            context.delete(game)
        } else {
            game.syncState = .localDeleted
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }

    // MARK: - Cover from URL

    @ViewBuilder
    private func coverURLSheet(for game: Game) -> some View {
        NavigationStack {
            Form {
                Section {
                    TextField("https://…", text: $coverURLInput)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                        .keyboardType(.URL)
                } footer: {
                    Text("Server downloads the image, generates a thumbnail, then your next sync pulls down the new cover.")
                }
                if let err = coverURLError {
                    Section { Text(err).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Cover URL")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { showCoverURL = false } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Fetch") { Task { await fetchExternal(for: game) } }
                        .disabled(coverURLInput.isEmpty || coverURLInFlight)
                }
            }
            .overlay {
                if coverURLInFlight {
                    ProgressView("Downloading…")
                        .padding().background(.regularMaterial)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }
            .themedBackground()
        }
    }

    private func fetchExternal(for game: Game) async {
        guard let id = game.serverId else { return }
        coverURLInFlight = true
        defer { coverURLInFlight = false }
        do {
            _ = try await proxiesAPI.externalImage(url: coverURLInput, gameId: id, face: .front)
            // Force the next /sync/changes to repull this row (server's
            // updated_at bumped when the games row was updated by the server)
            game.lastSyncedAt = nil
            try? context.save()
            syncTrigger.pingAfterMutation()
            showCoverURL = false
        } catch {
            coverURLError = error.localizedDescription
        }
    }

    // MARK: - Photo-library upload

    private func uploadPhoto(_ item: PhotosPickerItem, for game: Game) async {
        guard let id = game.serverId else { return }
        photoError = nil
        photoInFlight = true
        defer { photoInFlight = false; photoItem = nil }
        do {
            guard let data = try await item.loadTransferable(type: Data.self) else {
                photoError = "Couldn't load image data."
                return
            }
            guard let jpeg = Self.jpegPayload(from: data) else {
                photoError = "Couldn't decode the selected image."
                return
            }
            _ = try await proxiesAPI.uploadCover(gameId: id,
                                                 face: .front,
                                                 imageData: jpeg,
                                                 filename: "cover_\(id).jpg")
            // Force a repull of this row.
            game.lastSyncedAt = nil
            try? context.save()
            syncTrigger.pingAfterMutation()
        } catch {
            photoError = "Upload failed: \(error.localizedDescription)"
        }
    }

    /// Decode → downscale to ≤2048 px on the long edge → JPEG encode.
    /// The server rejects uploads over 5 MB; raw photo-library images
    /// (HEIC or full-res JPEG) routinely blow past that, so we always
    /// re-encode locally first.
    private static func jpegPayload(from data: Data) -> Data? {
        guard let original = UIImage(data: data) else { return nil }
        let maxEdge: CGFloat = 2048
        let longest = max(original.size.width, original.size.height)
        let image: UIImage
        if longest > maxEdge {
            let scale = maxEdge / longest
            let newSize = CGSize(width: original.size.width * scale,
                                 height: original.size.height * scale)
            let renderer = UIGraphicsImageRenderer(size: newSize)
            image = renderer.image { _ in
                original.draw(in: CGRect(origin: .zero, size: newSize))
            }
        } else {
            image = original
        }
        return image.jpegData(compressionQuality: 0.85)
    }
}
