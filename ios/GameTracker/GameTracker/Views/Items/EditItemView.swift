import SwiftUI
import SwiftData
import UIKit

struct EditItemView: View {
    let itemID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var title: String = ""
    @State private var category: ItemCategory = .systems
    @State private var platform: String = ""
    @State private var condition: String = ""
    @State private var pricePaid: String = ""
    @State private var pricechartingPrice: String = ""
    @State private var quantity: Int = 1
    @State private var description: String = ""
    @State private var notes: String = ""
    @State private var pendingNewFrontImage: UIImage? = nil
    @State private var pendingNewBackImage: UIImage? = nil
    @State private var existingFrontImage: String? = nil
    @State private var existingBackImage: String? = nil
    @State private var loaded = false

    private var canSave: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty
    }

    private var currentItemServerId: Int? {
        (context.model(for: itemID) as? Item)?.serverId
    }

    var body: some View {
        NavigationStack {
            Form {
                ItemFormBody(title: $title,
                             category: $category,
                             platform: $platform,
                             condition: $condition,
                             pricePaid: $pricePaid,
                             pricechartingPrice: $pricechartingPrice,
                             quantity: $quantity,
                             description: $description,
                             notes: $notes,
                             pendingNewFrontImage: $pendingNewFrontImage,
                             existingFrontImage: $existingFrontImage,
                             pendingNewBackImage: $pendingNewBackImage,
                             existingBackImage: $existingBackImage,
                             itemServerId: currentItemServerId,
                             imagesAPI: imagesAPI)
            }
            .navigationTitle("Edit item")
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
            .themedBackground()
        }
    }

    private func loadOnce() {
        guard !loaded, let i: Item = context.model(for: itemID) as? Item else { return }
        title              = i.title
        category           = ItemCategory(rawString: i.category)
        platform           = i.platform ?? ""
        condition          = i.conditionValue ?? ""
        pricePaid          = i.pricePaid.map { String($0) } ?? ""
        pricechartingPrice = i.pricechartingPrice.map { String($0) } ?? ""
        quantity           = max(1, i.quantity)
        description        = i.itemDescription ?? ""
        notes              = i.notes ?? ""
        existingFrontImage = i.frontImage
        existingBackImage  = i.backImage
        loaded = true
    }

    private func save() {
        guard let i: Item = context.model(for: itemID) as? Item else { return }
        i.title              = title.trimmingCharacters(in: .whitespaces)
        i.category           = category.rawValue
        i.platform           = platform.isEmpty ? nil : platform
        i.conditionValue     = condition.isEmpty ? nil : condition
        i.pricePaid          = Double(pricePaid)
        i.pricechartingPrice = Double(pricechartingPrice)
        i.quantity           = quantity
        i.itemDescription    = description.isEmpty ? nil : description
        i.notes              = notes.isEmpty ? nil : notes

        // Image upload (front): if a new photo was picked this session,
        // encode + overwrite. If both bindings nilled, user tapped
        // Remove → clear it. Otherwise leave the original untouched.
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            i.frontImage = dataURI
        } else if pendingNewFrontImage == nil && existingFrontImage == nil && i.frontImage != nil {
            i.frontImage = nil
        }

        // Image upload (back): same rules.
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            i.backImage = dataURI
        } else if pendingNewBackImage == nil && existingBackImage == nil && i.backImage != nil {
            i.backImage = nil
        }

        if i.syncState == .synced { i.syncState = .localModified }
        try? context.save()

        // Purge cached cover files so the next render re-fetches the new bytes.
        if let serverId = i.serverId {
            imagesAPI.invalidateItemCover(itemServerId: serverId)
        }

        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
