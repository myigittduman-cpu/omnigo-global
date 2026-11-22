import Foundation

enum ReportError: String, LocalizedError {
    case name = "Belge adı geçersiz."
    case size = "Belge çok büyük. Daha kısa bir tarih aralığı seçin."
    case contents = "Belge içeriği okunamadı."
    case type = "Yalnızca PDF, Excel, JPEG ve metin belgeleri kullanılabilir."
    var errorDescription: String? { rawValue }
}

struct ReportPayload {
    static let maxBytes = 32 * 1024 * 1024
    let filename: String
    let data: Data
    init(filename: String, contents: String) throws {
        let forbidden = CharacterSet.controlCharacters.union(CharacterSet(charactersIn: "<>:\"/\\|?*"))
        guard !filename.isEmpty, filename.count <= 160, !filename.hasPrefix("."),
              filename == filename.trimmingCharacters(in: .whitespacesAndNewlines),
              filename.rangeOfCharacter(from: forbidden) == nil else { throw ReportError.name }
        guard contents.utf8.count <= ((Self.maxBytes + 2) / 3) * 4 else { throw ReportError.size }
        guard let data = Data(base64Encoded: contents), !data.isEmpty, data.count <= Self.maxBytes else { throw ReportError.contents }
        switch (filename as NSString).pathExtension {
        case "pdf": guard data.starts(with: [37,80,68,70,45]) else { throw ReportError.contents }
        case "xlsx": guard data.starts(with: [80,75,3,4]) else { throw ReportError.contents }
        case "jpeg", "jpg": guard data.starts(with: [255,216,255]) else { throw ReportError.contents }
        case "txt": guard let text = String(data: data, encoding: .utf8), !text.contains("\0") else { throw ReportError.contents }
        default: throw ReportError.type
        }
        self.filename = filename
        self.data = data
    }
}
