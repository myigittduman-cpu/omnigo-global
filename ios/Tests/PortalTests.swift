import XCTest
#if ADMIN_APP
@testable import OmnigoAdmin
#else
@testable import OmnigoClient
#endif

final class PortalTests: XCTestCase {
    func testNotificationDestinationCannotLeavePortal() {
        XCTAssertEqual(PushDestination.path("/?chatThread=42"), "/?chatThread=42")
        XCTAssertEqual(PushDestination.path("/?chatThread=42&redirect=https://evil.test"), "/")
        XCTAssertEqual(PushDestination.path("/?view=shipments&orderCompany=12"), "/?view=shipments&orderCompany=12")
        XCTAssertEqual(PushDestination.path("/?view=orders&orderCompany=12&noticeType=order&noticeId=5"), "/?view=orders&orderCompany=12&noticeType=order&noticeId=5")
        #if targetEnvironment(macCatalyst)
        for view in ["inventory", "payments"] {
            let destination = "/?view=\(view)&orderCompany=12&noticeType=operation&noticeId=5"
            XCTAssertEqual(PushDestination.path(destination), destination)
            XCTAssertEqual(PushDestination.path(destination + "&next=https://evil.test"), "/")
        }
        #endif
        for value in ["https://evil.test/", "//evil.test/", "/?view=shipments&orderCompany=0", "/?view=connections&next=https://evil.test", "/?view=shipments\n"] {
            XCTAssertEqual(PushDestination.path(value), "/")
        }
    }
    func testOnlyOwnHTTPSOriginIsAllowed() {
        XCTAssertTrue(PortalConfiguration.allows(PortalConfiguration.home))
        XCTAssertTrue(PortalConfiguration.allows(URL(string: "https://\(PortalConfiguration.host):443/?page=orders")))
        for value in ["http://\(PortalConfiguration.host)", "https://\(PortalConfiguration.host).evil.test", "https://evil@\(PortalConfiguration.host)", "https://\(PortalConfiguration.host):444", "file:///tmp/report.pdf", "javascript:alert(1)"] {
            XCTAssertFalse(PortalConfiguration.allows(URL(string: value)))
        }
        XCTAssertFalse(PortalConfiguration.allows(nil))
    }
    func testTurkishDeliveryFilenameAndContentAreAccepted() throws {
        let data = Data("%PDF-1.7\n".utf8)
        let report = try ReportPayload(filename: "Eskişehir Teslimat Evrakları.pdf", contents: data.base64EncodedString())
        XCTAssertEqual(report.data, data)
    }
    func testUnsafeAndInvalidFilesAreRejected() {
        let contents = Data("%PDF-1.7\n".utf8).base64EncodedString()
        for name in ["../report.pdf", ".hidden.pdf", "a/b.pdf", "report.exe", "report.pdf ", "a\0b.pdf"] {
            XCTAssertThrowsError(try ReportPayload(filename: name, contents: contents))
        }
        XCTAssertThrowsError(try ReportPayload(filename: "test.pdf", contents: "not-base64"))
        XCTAssertThrowsError(try ReportPayload(filename: "test.pdf", contents: Data("MZapp".utf8).base64EncodedString()))
        XCTAssertThrowsError(try ReportPayload(filename: "test.pdf", contents: String(repeating: "A", count: ((ReportPayload.maxBytes + 2) / 3) * 4 + 1)))
    }
    func testInvoiceJPEGDownload() throws {
        let data = Data([255,216,255,0])
        XCTAssertEqual(try ReportPayload(filename: "Omnigo-Fatura-1.jpeg", contents: data.base64EncodedString()).data, data)
        XCTAssertThrowsError(try ReportPayload(filename: "Omnigo-Fatura-1.jpeg", contents: Data("MZapp".utf8).base64EncodedString()))
    }
}
