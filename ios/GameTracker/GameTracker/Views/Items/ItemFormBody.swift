import SwiftUI
import UIKit

/// Shared form fields for Add/Edit item. The owning view supplies
/// every binding; this struct contains no `@State` of its own (apart
/// from picker presentation state inside `ItemImagePickerSection`)
/// — that keeps the Form's sheet-anchor stable (Plan 3b learning).
struct ItemFormBody: View {
    @Binding var title: String
    @Binding var category: ItemCategory
    @Binding var platform: String
    @Binding var condition: String
    @Binding var pricePaid: String
    @Binding var pricechartingPrice: String
    @Binding var quantity: Int
    @Binding var description: String
    @Binding var notes: String
    @Binding var pendingNewImage: UIImage?
    @Binding var existingFrontImage: String?
    let itemServerId: Int?
    let imagesAPI: ImagesAPI

    var body: some View {
        Group {
            ItemImagePickerSection(pendingNewImage: $pendingNewImage,
                                   existingFrontImage: $existingFrontImage,
                                   itemServerId: itemServerId,
                                   imagesAPI: imagesAPI)

            Section("Title & category") {
                TextField("Title", text: $title)
                Picker("Category", selection: $category) {
                    ForEach(ItemCategory.allCases) { c in
                        Label(c.displayName, systemImage: c.systemImage).tag(c)
                    }
                }
                .pickerStyle(.menu)
            }

            Section("Platform & condition") {
                TextField("Platform (e.g. PlayStation 5)", text: $platform)
                TextField("Condition (e.g. Good, Boxed, CIB)", text: $condition)
            }

            Section("Price") {
                TextField("Price paid (£)", text: $pricePaid)
                    .keyboardType(.decimalPad)
                TextField("Pricecharting value (£)", text: $pricechartingPrice)
                    .keyboardType(.decimalPad)
            }

            Section("Quantity") {
                Stepper(value: $quantity, in: 1...99) {
                    Text("Quantity: \(quantity)")
                }
            }

            Section("Description") {
                TextField("Description", text: $description, axis: .vertical)
                    .lineLimit(3...10)
            }

            Section("Notes") {
                TextField("Notes", text: $notes, axis: .vertical)
                    .lineLimit(3...10)
            }
        }
    }
}
