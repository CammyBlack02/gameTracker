import SwiftUI
import SwiftData
import UIKit

struct AddItemView: View {
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
    @State private var existingFrontImage: String? = nil   // always nil for Add
    @State private var existingBackImage: String? = nil    // always nil for Add

    private var canSave: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty
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
                             itemServerId: nil,
                             imagesAPI: imagesAPI)
            }
            .navigationTitle("Add an item")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(!canSave)
                }
            }
        }
        .themedBackground()
    }

    private func save() {
        let item = Item(title: title.trimmingCharacters(in: .whitespaces),
                        category: category.rawValue,
                        syncState: .localNew)
        item.platform           = platform.isEmpty ? nil : platform
        item.conditionValue     = condition.isEmpty ? nil : condition
        item.pricePaid          = Double(pricePaid)
        item.pricechartingPrice = Double(pricechartingPrice)
        item.quantity           = quantity
        item.itemDescription    = description.isEmpty ? nil : description
        item.notes              = notes.isEmpty ? nil : notes
        if let img = pendingNewFrontImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            item.frontImage = dataURI
        }
        if let img = pendingNewBackImage,
           let dataURI = CoverImageProcessor.dataURI(from: img) {
            item.backImage = dataURI
        }
        context.insert(item)
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
