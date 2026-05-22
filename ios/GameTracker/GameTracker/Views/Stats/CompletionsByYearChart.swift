import SwiftUI
import Charts

struct CompletionsByYearChart: View {

    /// Pre-aggregated `(year, count)` tuples, ascending by year.
    let data: [(year: Int, count: Int)]
    let currentYear: Int

    var body: some View {
        if data.isEmpty {
            ContentUnavailableView("No completions yet",
                                   systemImage: "chart.bar",
                                   description: Text("Log a completion to see your year-by-year progress."))
                .frame(height: 200)
        } else {
            Chart(data, id: \.year) { row in
                BarMark(
                    x: .value("Year", String(row.year)),
                    y: .value("Completions", row.count)
                )
                .foregroundStyle(row.year == currentYear ? Color.accentColor : Color.gray)
            }
            .chartYAxis {
                AxisMarks(position: .leading, values: .automatic(desiredCount: 4))
            }
            .frame(height: 200)
        }
    }
}
