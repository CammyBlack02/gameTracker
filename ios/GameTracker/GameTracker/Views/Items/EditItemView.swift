import SwiftUI
import SwiftData

struct EditItemView: View {
    let itemID: PersistentIdentifier
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var title: String = ""
    @State private var category: ItemCategory = .console
    @State private var platform: String = ""
    @State private var condition: String = ""
    @State private var pricePaid: String = ""
    @State private var pricechartingPrice: String = ""
    @State private var quantity: Int = 1
    @State private var description: String = ""
    @State private var notes: String = ""
    @State private var loaded = false

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
                             notes: $notes)
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
        if i.syncState == .synced { i.syncState = .localModified }
        try? context.save()
        syncTrigger.pingAfterMutation()
        dismiss()
    }
}
