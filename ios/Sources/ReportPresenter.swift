import UIKit

@MainActor
final class ReportPresenter: NSObject, UIDocumentPickerDelegate {
    private weak var controller: UIViewController?
    private var completion: ((String?, String?) -> Void)?
    private var directory: URL?
    init(controller: UIViewController) { self.controller = controller }

    func present(filename: String, contents: String, share: Bool, completion: @escaping (String?, String?) -> Void) {
        guard self.completion == nil, controller?.presentedViewController == nil else {
            completion(nil, "Önce açık belge işlemini tamamlayın."); return
        }
        self.completion = completion
        // Decode and write away from the UI thread; only expose the generated file through the system picker.
        Task {
            do {
                let result = try await Task.detached(priority: .userInitiated) { () -> (URL, URL) in
                    let report = try ReportPayload(filename: filename, contents: contents)
                    let folder = FileManager.default.temporaryDirectory.appendingPathComponent("Omnigo-" + UUID().uuidString, isDirectory: true)
                    try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
                    let file = folder.appendingPathComponent(report.filename)
                    #if targetEnvironment(macCatalyst)
                    let writeOptions: Data.WritingOptions = .atomic
                    #else
                    let writeOptions: Data.WritingOptions = [.atomic, .completeFileProtection]
                    #endif
                    do { try report.data.write(to: file, options: writeOptions) }
                    catch { try? FileManager.default.removeItem(at: folder); throw error }
                    return (folder, file)
                }.value
                directory = result.0
                guard let controller, controller.viewIfLoaded?.window != nil else {
                    finish(status: "cancelled"); return
                }
                if share {
                    let sheet = UIActivityViewController(activityItems: [result.1], applicationActivities: nil)
                    sheet.popoverPresentationController?.sourceView = controller.view
                    sheet.popoverPresentationController?.sourceRect = CGRect(x: controller.view.bounds.midX, y: controller.view.bounds.midY, width: 1, height: 1)
                    sheet.completionWithItemsHandler = { [weak self] _, completed, _, error in
                        self?.finish(status: completed ? "shared" : "cancelled", error: error == nil ? nil : "Belge paylaşılamadı.")
                    }
                    controller.present(sheet, animated: true)
                } else {
                    let picker = UIDocumentPickerViewController(forExporting: [result.1], asCopy: true)
                    picker.delegate = self
                    controller.present(picker, animated: true)
                }
            } catch { finish(error: (error as? ReportError)?.localizedDescription ?? "Belge hazırlanamadı.") }
        }
    }
    func documentPicker(_ controller: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) { finish(status: urls.isEmpty ? "cancelled" : "saved") }
    func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) { finish(status: "cancelled") }
    private func finish(status: String? = nil, error: String? = nil) {
        let callback = completion
        completion = nil
        if let directory { try? FileManager.default.removeItem(at: directory) }
        directory = nil
        callback?(status, error)
    }
}
