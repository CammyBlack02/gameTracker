import SwiftUI
import SwiftData

struct ItemDetailView: View {
    let itemID: PersistentIdentifier
    let imagesAPI: ImagesAPI
    let syncTrigger: SyncTrigger

    @Environment(\.modelContext) private var context

    @State private var showEdit = false
    @State private var showingBack = false

    private var item: Item? {
        context.model(for: itemID) as? Item
    }

    private var category: ItemCategory {
        ItemCategory(rawString: item?.category)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                cover
                header
                if hasPricing { pricing }
                if hasConditionOrQuantity { conditionAndQuantity }
                if let d = item?.itemDescription, !d.isEmpty { descriptionSection(d) }
                if let n = item?.notes, !n.isEmpty { notesSection(n) }
            }
            .padding(.horizontal)
            .padding(.bottom, 24)
        }
        .navigationTitle(item?.title ?? "Item")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button("Edit") { showEdit = true }
            }
        }
        .sheet(isPresented: $showEdit) {
            EditItemView(itemID: itemID, syncTrigger: syncTrigger)
        }
    }

    // MARK: - Sections

    @ViewBuilder
    private var cover: some View {
        let face: ImagesAPI.Face = showingBack ? .back : .front
        CoverImage(itemServerId: item?.serverId, face: face, size: .full, api: imagesAPI)
            .frame(maxWidth: .infinity)
            .frame(height: 280)
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .onTapGesture {
                // Tap-to-flip is best-effort: if the row has no back
                // image, CoverImage renders its placeholder. Flipping
                // back is a single tap away.
                showingBack.toggle()
            }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(item?.title ?? "")
                .font(.title2.weight(.semibold))
            HStack(spacing: 6) {
                Image(systemName: category.systemImage).foregroundStyle(.secondary)
                Text(category.displayName).font(.subheadline).foregroundStyle(.secondary)
                if let p = item?.platform, !p.isEmpty {
                    Text("·").foregroundStyle(.secondary)
                    Text(p).font(.subheadline).foregroundStyle(.secondary)
                }
            }
        }
    }

    private var hasPricing: Bool {
        item?.pricePaid != nil || item?.pricechartingPrice != nil
    }

    private var pricing: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Pricing").font(.headline)
            if let p = item?.pricePaid {
                row(label: "Price paid", value: "£\(format(p))")
            }
            if let p = item?.pricechartingPrice {
                row(label: "Pricecharting value", value: "£\(format(p))")
            }
        }
    }

    private var hasConditionOrQuantity: Bool {
        (item?.conditionValue?.isEmpty == false) || (item?.quantity ?? 0) > 0
    }

    private var conditionAndQuantity: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Condition & quantity").font(.headline)
            if let c = item?.conditionValue, !c.isEmpty {
                row(label: "Condition", value: c)
            }
            row(label: "Quantity", value: "\(item?.quantity ?? 1)")
        }
    }

    private func descriptionSection(_ d: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Description").font(.headline)
            Text(d).font(.body).foregroundStyle(.primary)
        }
    }

    private func notesSection(_ n: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Notes").font(.headline)
            Text(n).font(.body).foregroundStyle(.primary)
        }
    }

    // MARK: - Helpers

    private func row(label: String, value: String) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            Text(value).foregroundStyle(.primary)
        }
    }

    private func format(_ value: Double) -> String {
        String(format: "%.2f", value)
    }
}
