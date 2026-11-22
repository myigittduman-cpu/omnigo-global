import WebKit

@MainActor
final class PortalBridge: NSObject, WKScriptMessageHandlerWithReply {
    private weak var controller: PortalViewController?
    init(controller: PortalViewController) { self.controller = controller }
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage,
                               replyHandler: @escaping (Any?, String?) -> Void) {
        guard message.frameInfo.isMainFrame,
              message.frameInfo.securityOrigin.protocol == "https",
              message.frameInfo.securityOrigin.host == PortalConfiguration.host,
              [0,443].contains(message.frameInfo.securityOrigin.port),
              PortalConfiguration.allows(message.webView?.url),
              let body = message.body as? [String: Any],
              let command = body["command"] as? String else { replyHandler(nil,"İstek doğrulanamadı."); return }
        if command == "set_language" {
            let language = (body["data"] as? [String: Any])?["language"] as? String ?? ""
            guard NativeLanguage.set(language) else { replyHandler(nil, "Invalid language"); return }
            controller?.refreshLanguage()
            replyHandler("saved", nil); return
        }
        if command.hasPrefix("push_") {
            MobilePushController.shared.handle(command:command,data:body["data"] as? [String:Any] ?? [:],reply:replyHandler)
            return
        }
        guard ["save_report", "share_report"].contains(command),
              let filename = body["filename"] as? String, let contents = body["contents"] as? String,
              let controller else { replyHandler(nil, "Belge isteği doğrulanamadı."); return }
        controller.reports.present(filename: filename, contents: contents, share: command == "share_report") { status, error in
            replyHandler(status, error)
        }
    }
}
