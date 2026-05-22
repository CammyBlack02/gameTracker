import Foundation

/// Computes total disk usage across one or more directories. Used by
/// the Settings tab's Storage row.
///
/// Pure helper — no SwiftUI dependency — so it unit-tests against a
/// temp directory without mounting a view.
enum ImageCacheSizeCalculator {

    /// Sum of file sizes of every regular file under each of `roots`,
    /// recursively. Missing directories contribute 0. Symbolic links
    /// are not followed. Enumeration errors are silently treated as 0
    /// — this is for display only.
    static func totalBytes(under roots: [URL]) -> Int64 {
        var total: Int64 = 0
        let keys: [URLResourceKey] = [.isRegularFileKey, .totalFileAllocatedSizeKey, .fileSizeKey]
        let keySet = Set(keys)
        for root in roots {
            guard FileManager.default.fileExists(atPath: root.path) else { continue }
            guard let enumerator = FileManager.default.enumerator(
                at: root,
                includingPropertiesForKeys: keys,
                options: [.skipsHiddenFiles]
            ) else { continue }
            for case let url as URL in enumerator {
                guard let values = try? url.resourceValues(forKeys: keySet) else { continue }
                guard values.isRegularFile == true else { continue }
                if let size = values.fileSize ?? values.totalFileAllocatedSize {
                    total += Int64(size)
                }
            }
        }
        return total
    }

    /// Human-readable, e.g. `"12.4 MB"`, `"0 bytes"`. Locale-dependent.
    static func formatted(_ bytes: Int64) -> String {
        let fmt = ByteCountFormatter()
        fmt.allowedUnits = [.useAll]
        fmt.countStyle = .file
        return fmt.string(fromByteCount: bytes)
    }
}
