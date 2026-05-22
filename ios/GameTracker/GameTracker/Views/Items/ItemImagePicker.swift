import SwiftUI
import PhotosUI
import UIKit

// MARK: - UIImagePickerController wrapper (camera capture)

/// SwiftUI wrapper around `UIImagePickerController` for live camera
/// capture. PhotosPicker covers the library; this covers the camera.
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

enum ItemImageProcessor {
    /// Returns a base64 `data:image/jpeg;base64,…` URI suitable for
    /// writing to `item.frontImage`. Downscales the longer edge to
    /// `maxDimension` pt and JPEG-encodes at the supplied quality.
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

// MARK: - Form section

/// The "Image" section for Item Add/Edit forms. Holds local picker
/// presentation state but writes results back through parent-owned
/// bindings.
///
/// `existingFrontImage` is the model's current `frontImage` string
/// (data URI, HTTPS URL, or bare filename) shown via `CoverImage`
/// when no fresh pick has happened. When `pendingNewImage` is
/// non-nil, the freshly-picked UIImage previews instead. The Remove
/// button nils both, signalling the save path to clear the column.
struct ItemImagePickerSection: View {
    @Binding var pendingNewImage: UIImage?
    @Binding var existingFrontImage: String?
    let itemServerId: Int?
    let imagesAPI: ImagesAPI

    @State private var showSourceSheet = false
    @State private var showCamera = false
    @State private var showLibrary = false
    @State private var libraryPickerItem: PhotosPickerItem?

    private var hasAnyImage: Bool {
        pendingNewImage != nil || (itemServerId != nil && existingFrontImage != nil)
    }

    var body: some View {
        Section("Image") {
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
            Button("Take Photo") { showCamera = true }
            Button("Choose from Library") { showLibrary = true }
            Button("Cancel", role: .cancel) {}
        }
        .sheet(isPresented: $showCamera) {
            CameraPickerView { img in
                pendingNewImage = img
            }
            .ignoresSafeArea()
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
                } else if let id = itemServerId, existingFrontImage != nil {
                    CoverImage(itemServerId: id, face: .front, size: .thumb, api: imagesAPI)
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
                    existingFrontImage = nil
                } label: {
                    Label("Remove", systemImage: "trash")
                }
            }
            .buttonStyle(.bordered)
            .controlSize(.small)
        }
    }
}
