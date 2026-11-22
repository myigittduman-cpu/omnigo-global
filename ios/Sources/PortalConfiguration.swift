import Foundation

enum PortalConfiguration {
    #if ADMIN_APP
    static let host = "yonetim.omnigoglobal.com"
    static let title = "Omnigo Global Yönetici Portalı"
    #elseif targetEnvironment(macCatalyst)
    static let host = "portal.omnigoglobal.com"
    static let title = "Omnigo Global İş Ortağım"
    #else
    static let host = "portal.omnigoglobal.com"
    static let title = "Omnigo Global Müşteri Portalı"
    #endif
    static let home = URL(string: "https://\(host)/")!
    static func allows(_ url: URL?) -> Bool {
        guard let url else { return false }
        return url.scheme == "https" && url.host == host && (url.port == nil || url.port == 443)
            && url.user == nil && url.password == nil
    }
}
