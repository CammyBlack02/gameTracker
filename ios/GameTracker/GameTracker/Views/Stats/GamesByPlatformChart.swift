import SwiftUI
import Charts

struct GamesByPlatformChart: View {

    /// Pre-aggregated `(platform, count)` tuples, descending by count,
    /// already capped at top 8 + `"Other"`.
    let data: [(platform: String, count: Int)]

    var body: some View {
        if data.isEmpty {
            ContentUnavailableView("No games yet",
                                   systemImage: "books.vertical",
                                   description: Text("Add a game to the library to see platform breakdown."))
                .frame(height: 200)
        } else {
            Chart(data, id: \.platform) { row in
                BarMark(
                    x: .value("Count", row.count),
                    y: .value("Platform", row.platform)
                )
            }
            .chartXAxis {
                AxisMarks(position: .bottom, values: .automatic(desiredCount: 4))
            }
            .frame(height: CGFloat(data.count) * 28 + 40)
        }
    }
}
