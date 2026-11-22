import Foundation

enum PushDestination {
    #if targetEnvironment(macCatalyst)
    private static let portalViews = "orders|shipments|deliveries|connections|support|inventory|payments"
    #else
    private static let portalViews = "orders|shipments|deliveries|connections|support"
    #endif

    static func path(_ value: String?) -> String {
        if let value, value.range(of: "^/\\?chatThread=[1-9][0-9]{0,14}\\z", options: .regularExpression) != nil { return value }
        guard let value, value.utf8.count <= 180,
              value.range(of: "^/\\?view=(\(portalViews))(&orderCompany=[1-9][0-9]{0,9})?(&noticeType=(order|shipment|operation|support|user)&noticeId=[1-9][0-9]{0,9})?\\z", options: .regularExpression) != nil else { return "/" }
        return value
    }
}
