import SwiftUI
import PhotosUI
import UIKit

// MARK: - CoverKind

/// Discriminator passed to `CoverImagePickerSection` so the preview
/// renders via the right `CoverImage` init.
enum CoverKind {
    case game
    case item
}

// MARK: - UIImagePickerController wrapper (camera capture)

/// SwiftUI wrapper around `UIImagePickerController` for live camera
/// capture. PhotosPicker handles the library; this handles the camera.
struct CameraPickerView: UIViewControllerRepresentable {
    let onPick: (UIImage) -> Void

    @Environment(\.dismiss) private var dismiss

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let vc = UIImagePickerController()
        vc.sourceType = .camera
        vc.allowsEditing = false
        vc.delegate = context.coordinator
        return vc
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let parent: CameraPickerView
        init(_ parent: CameraPickerView) { self.parent = parent }

        func imagePickerController(_ picker: UIImagePickerController,
                                   didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if let img = info[.originalImage] as? UIImage {
                parent.onPick(img)
            }
            parent.dismiss()
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            parent.dismiss()
        }
    }
}

// MARK: - Image processing helpers

enum CoverImageProcessor {
    /// Returns a base64 `data:image/jpeg;base64,…` URI suitable for
    /// writing to the model's `frontImage` / `backImage` /
    /// `frontCoverImage` / `backCoverImage` column.
    static func dataURI(from image: UIImage,
                        maxDimension: CGFloat = 1024,
                        jpegQuality: CGFloat = 0.7) -> String? {
        let resized = downscale(image, maxDimension: maxDimension)
        guard let jpeg = resized.jpegData(compressionQuality: jpegQuality) else { return nil }
        let b64 = jpeg.base64EncodedString()
        return "data:image/jpeg;base64,\(b64)"
    }

    private static func downscale(_ image: UIImage, maxDimension: CGFloat) -> UIImage {
        let longest = max(image.size.width, image.size.height)
        guard longest > maxDimension else { return image }
        let scale = maxDimension / longest
        let newSize = CGSize(width: image.size.width * scale,
                             height: image.size.height * scale)
        let renderer = UIGraphicsImageRenderer(size: newSize)
        return renderer.image { _ in
            image.draw(in: CGRect(origin: .zero, size: newSize))
        }
    }
}

// MARK: - URL fetch helper

enum CoverImageURLFetcher {
    enum FetchError: LocalizedError {
        case badURL
        case nonHTTPS
        case badResponse(Int)
        case wrongType(String)
        case tooLarge(Int)
        case decodeFailed

        var errorDescription: String? {
            switch self {
            case .badURL:               return "That doesn't look like a valid URL."
            case .nonHTTPS:             return "URL must start with https://"
            case .badResponse(let c):   return "Server returned HTTP \(c)."
            case .wrongType(let t):     return "Expected an image, got \(t)."
            case .tooLarge(let bytes):  return "Image is too large (\(bytes / 1_000_000) MB). Max 10 MB."
            case .decodeFailed:         return "Couldn't decode the downloaded image."
            }
        }
    }

    /// Fetches `urlString` via URLSession and returns a `UIImage`.
    /// Validation: must be https://, must respond 200 with an
    /// `image/*` content-type, must be ≤ 10 MB, must decode.
    static func fetchImage(from urlString: String) async throws -> UIImage {
        let trimmed = urlString.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let url = URL(string: trimmed), url.scheme != nil else {
            throw FetchError.badURL
        }
        guard url.scheme?.lowercased() == "https" else {
            throw FetchError.nonHTTPS
        }

        var request = URLRequest(url: url)
        request.timeoutInterval = 30

        let (data, response) = try await URLSession.shared.data(for: request)

        if let http = response as? HTTPURLResponse, http.statusCode != 200 {
            throw FetchError.badResponse(http.statusCode)
        }
        let contentType = (response as? HTTPURLResponse)?.value(forHTTPHeaderField: "Content-Type") ?? ""
        if !contentType.lowercased().hasPrefix("image/") {
            throw FetchError.wrongType(contentType)
        }
        if data.count > 10 * 1_000_000 {
            throw FetchError.tooLarge(data.count)
        }
        guard let img = UIImage(data: data) else {
            throw FetchError.decodeFailed
        }
        return img
    }
}

// MARK: - Form section

/// The "Image" section for Item / Game Add and Edit forms. Holds
/// local picker presentation state but writes results back through
/// parent-owned bindings.
struct CoverImagePickerSection: View {
    @Binding var pendingNewImage: UIImage?
    @Binding var existingImageString: String?
    let kind: CoverKind
    let serverId: Int?
    let face: ImagesAPI.Face
    let imagesAPI: ImagesAPI
    let sectionTitle: String

    private enum ActiveSheet: Identifiable {
        case camera, urlDialog
        var id: Self { self }
    }

    @State private var showSourceSheet = false
    @State private var activeSheet: ActiveSheet?
    @State private var showLibrary = false
    @State private var libraryPickerItem: PhotosPickerItem?
    @State private var urlInput: String = ""
    @State private var urlFetchInFlight: Bool = false
    @State private var urlFetchError: String?

    private var hasAnyImage: Bool {
        pendingNewImage != nil || (serverId != nil && existingImageString != nil)
    }

    var body: some View {
        Section(sectionTitle) {
            if hasAnyImage {
                preview
            } else {
                Button {
                    showSourceSheet = true
                } label: {
                    Label("Add a photo", systemImage: "camera")
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
        .confirmationDialog("Add an image", isPresented: $showSourceSheet, titleVisibility: .visible) {
            Button("Take Photo") { activeSheet = .camera }
            Button("Choose from Library") { showLibrary = true }
            Button("Paste URL") {
                urlInput = ""
                urlFetchError = nil
                activeSheet = .urlDialog
            }
            Button("Cancel", role: .cancel) {}
        }
        .sheet(item: $activeSheet) { which in
            switch which {
            case .camera:
                CameraPickerView { img in
                    pendingNewImage = img
                }
                .ignoresSafeArea()
            case .urlDialog:
                urlDialog
            }
        }
        .photosPicker(isPresented: $showLibrary,
                      selection: $libraryPickerItem,
                      matching: .images)
        .onChange(of: libraryPickerItem) { _, newItem in
            guard let newItem else { return }
            Task {
                if let data = try? await newItem.loadTransferable(type: Data.self),
                   let img = UIImage(data: data) {
                    await MainActor.run { pendingNewImage = img }
                }
                await MainActor.run { libraryPickerItem = nil }
            }
        }
    }

    @ViewBuilder
    private var preview: some View {
        VStack(alignment: .leading, spacing: 8) {
            Group {
                if let img = pendingNewImage {
                    Image(uiImage: img)
                        .resizable()
                        .aspectRatio(contentMode: .fit)
                } else if let id = serverId, existingImageString != nil {
                    switch kind {
                    case .game:
                        CoverImage(gameServerId: id, face: face, size: .thumb, api: imagesAPI)
                    case .item:
                        CoverImage(itemServerId: id, face: face, size: .thumb, api: imagesAPI)
                    }
                } else {
                    Color.gray.opacity(0.2)
                }
            }
            .frame(maxWidth: .infinity)
            .frame(height: 160)
            .clipShape(RoundedRectangle(cornerRadius: 8))

            HStack {
                Button {
                    showSourceSheet = true
                } label: {
                    Label("Change", systemImage: "arrow.triangle.2.circlepath")
                }
                Spacer()
                Button(role: .destructive) {
                    pendingNewImage = nil
                    existingImageString = nil
                } label: {
                    Label("Remove", systemImage: "trash")
                }
            }
            .buttonStyle(.bordered)
            .controlSize(.small)
        }
    }

    @ViewBuilder
    private var urlDialog: some View {
        NavigationStack {
            Form {
                Section("Image URL") {
                    TextField("https://…", text: $urlInput)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                }
                Section {
                    Button {
                        Task { await fetchURL() }
                    } label: {
                        if urlFetchInFlight {
                            HStack { ProgressView(); Text("Fetching…") }
                        } else {
                            Label("Fetch image", systemImage: "arrow.down.circle")
                        }
                    }
                    .disabled(urlInput.isEmpty || urlFetchInFlight)
                }
                if let err = urlFetchError {
                    Section { Text(err).font(.callout).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Add image from URL")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { activeSheet = nil }
                }
            }
        }
    }

    private func fetchURL() async {
        urlFetchError = nil
        urlFetchInFlight = true
        defer { urlFetchInFlight = false }
        do {
            let img = try await CoverImageURLFetcher.fetchImage(from: urlInput)
            await MainActor.run {
                pendingNewImage = img
                activeSheet = nil
            }
        } catch {
            await MainActor.run {
                urlFetchError = (error as? CoverImageURLFetcher.FetchError)?.errorDescription
                    ?? error.localizedDescription
            }
        }
    }
}
