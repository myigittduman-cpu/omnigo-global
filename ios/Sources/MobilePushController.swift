import UIKit
import UserNotifications

@MainActor
final class MobilePushController: NSObject, UNUserNotificationCenterDelegate {
    static let shared = MobilePushController()
    static let openNotification = Notification.Name("OmnigoOpenNotification")
    private let defaults = UserDefaults.standard
    private var token: String?
    private var waiters: [(Any?, String?) -> Void] = []
    private(set) var pendingPath: String?
    private var binding: [String: Any] { defaults.dictionary(forKey: "mobilePushBinding") ?? [:] }
    private var enabled: Bool { binding["enabled"] as? Bool == true }

    func start() {
        UNUserNotificationCenter.current().delegate = self
        if enabled { UIApplication.shared.registerForRemoteNotifications() }
    }
    private func state(permission: Bool) -> [String: Any] {
        var installation = defaults.string(forKey: "mobilePushInstallation")
        if installation == nil {
            installation = (UUID().uuidString + UUID().uuidString).replacingOccurrences(of: "-", with: "").lowercased()
            defaults.set(installation, forKey: "mobilePushInstallation")
        }
        var value: [String: Any] = ["kind":"ios", "appId":Bundle.main.bundleIdentifier ?? "",
            "installationId":installation!, "environment":Bundle.main.object(forInfoDictionaryKey: "PushEnvironment") as? String ?? "production",
            "permission":permission, "configured":true, "enabled":enabled,
            "id":binding["id"] ?? 0, "userId":binding["userId"] ?? 0, "sound":binding["sound"] ?? true]
        if let token { value["token"] = token }
        return value
    }
    func handle(command: String, data: [String: Any], reply: @escaping (Any?, String?) -> Void) {
        switch command {
        case "push_binding":
            guard let id = data["id"] as? Int, id > 0, let user = data["userId"] as? Int, user > 0,
                  let sound = data["sound"] as? Bool else { reply(nil,"Bildirim kaydı geçersiz."); return }
            defaults.set(["id":id,"userId":user,"sound":sound,"enabled":true], forKey:"mobilePushBinding")
            reply(["enabled":true],nil)
        case "push_disable":
            defaults.removeObject(forKey:"mobilePushBinding")
            token = nil
            UIApplication.shared.unregisterForRemoteNotifications()
            UNUserNotificationCenter.current().removeAllDeliveredNotifications()
            finish(error:"Bildirimler kapatıldı.")
            reply(["enabled":false],nil)
        case "push_status":
            UNUserNotificationCenter.current().getNotificationSettings { settings in
                Task { @MainActor in
                    let allowed = [.authorized,.provisional,.ephemeral].contains(settings.authorizationStatus)
                    if allowed && self.enabled { self.registration(reply:reply) }
                    else { reply(self.state(permission:allowed),nil) }
                }
            }
        case "push_enable":
            UNUserNotificationCenter.current().requestAuthorization(options:[.alert,.badge,.sound]) { allowed, error in
                Task { @MainActor in
                    guard allowed, error == nil else {
                        #if targetEnvironment(macCatalyst)
                        reply(nil,"Bildirim izni kapalı. Sistem Ayarları > Bildirimler bölümünden uygulamaya izin verin.")
                        #else
                        reply(nil,"Bildirim izni kapalı. iPhone Ayarlar bölümünden uygulamaya izin verin.")
                        #endif
                        return
                    }
                    self.registration(reply:reply)
                }
            }
        default: reply(nil,"Bildirim işlemi desteklenmiyor.")
        }
    }
    private func registration(reply: @escaping (Any?, String?) -> Void) {
        if token != nil { reply(state(permission:true),nil); return }
        waiters.append(reply)
        UIApplication.shared.registerForRemoteNotifications()
        DispatchQueue.main.asyncAfter(deadline:.now()+20) { [weak self] in
            guard let self, self.token == nil else { return }
            self.finish(error:"Apple bildirim kaydı alınamadı. İnternet bağlantınızı kontrol edip tekrar deneyin.")
        }
    }
    func registered(_ data: Data) { token = data.map { String(format:"%02x",$0) }.joined(); finish(error:nil) }
    func failed() { finish(error:"Apple bildirim hizmetine kayıt yapılamadı.") }
    private func finish(error: String?) {
        let callbacks = waiters; waiters.removeAll()
        for callback in callbacks { callback(error == nil ? state(permission:true) : nil,error) }
    }
    func open(_ info: [AnyHashable:Any]) {
        pendingPath = PushDestination.path(info["url"] as? String)
        NotificationCenter.default.post(name:Self.openNotification,object:nil)
    }
    func takePath() -> String? { defer { pendingPath = nil }; return pendingPath }
    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification, withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
        Task { @MainActor in completionHandler(self.enabled ? [.banner,.list] : []) }
    }
    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse, withCompletionHandler completionHandler: @escaping () -> Void) {
        Task { @MainActor in self.open(response.notification.request.content.userInfo); completionHandler() }
    }
}
