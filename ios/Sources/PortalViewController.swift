import UIKit
import WebKit

@MainActor
final class PortalViewController: UIViewController {
    private(set) var webView: WKWebView!
    lazy var reports = ReportPresenter(controller: self)
    private var bridge: PortalBridge!
    private let message = UILabel()
    private let retryButton = UIButton(type: .system)
    private let spinner = UIActivityIndicatorView(style: .large)
    private let overlay = UIStackView()
    private let refresh = UIRefreshControl()
    private var failed = false

    override func viewDidLoad() {
        super.viewDidLoad()
        title = PortalConfiguration.title
        view.backgroundColor = .systemBackground
        let config = WKWebViewConfiguration()
        config.websiteDataStore = .default()
        #if targetEnvironment(macCatalyst)
        config.applicationNameForUserAgent = "OmnigoMac/1.0.0"
        #else
        config.applicationNameForUserAgent = "OmnigoIOS/1.0.0"
        #endif
        config.preferences.javaScriptCanOpenWindowsAutomatically = false
        bridge = PortalBridge(controller: self)
        config.userContentController.addScriptMessageHandler(bridge, contentWorld: .page, name: "omnigoReport")
        guard let scriptURL = Bundle.main.url(forResource: "portal-bridge", withExtension: "js"),
              let source = try? String(contentsOf: scriptURL, encoding: .utf8) else {
            showSetupError(); return
        }
        let script = source.replacingOccurrences(of: "__OMNIGO_PORTAL_ORIGIN__", with: "https://" + PortalConfiguration.host)
        config.userContentController.addUserScript(WKUserScript(source: script, injectionTime: .atDocumentStart, forMainFrameOnly: true))
        webView = WKWebView(frame: .zero, configuration: config)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true
        webView.customUserAgent = nil
        webView.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(webView)
        NSLayoutConstraint.activate([
            webView.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            webView.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor),
            webView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: view.trailingAnchor)
        ])
        #if !targetEnvironment(macCatalyst)
        refresh.addTarget(self, action: #selector(reloadPage), for: .valueChanged)
        webView.scrollView.refreshControl = refresh
        #endif
        navigationItem.leftBarButtonItem = UIBarButtonItem(image: UIImage(systemName: "chevron.backward"), style: .plain, target: self, action: #selector(goBack))
        navigationItem.leftBarButtonItem?.accessibilityLabel = NativeLanguage.t("Geri")
        navigationItem.rightBarButtonItem = UIBarButtonItem(barButtonSystemItem: .refresh, target: self, action: #selector(reloadPage))
        navigationItem.rightBarButtonItem?.accessibilityLabel = NativeLanguage.t("Yenile")
        configureOverlay()
        NotificationCenter.default.addObserver(self,selector:#selector(openNotification),name:MobilePushController.openNotification,object:nil)
        if MobilePushController.shared.pendingPath != nil { openNotification() }
        else { webView.load(URLRequest(url: PortalConfiguration.home)) }
    }
    @objc private func openNotification() {
        guard let path = MobilePushController.shared.takePath(), let url = URL(string:"https://"+PortalConfiguration.host+path) else { return }
        webView.load(URLRequest(url:url))
    }
    func refreshLanguage() {
        retryButton.setTitle(NativeLanguage.t("Yeniden dene"), for: .normal)
        navigationItem.leftBarButtonItem?.accessibilityLabel = NativeLanguage.t("Geri")
        navigationItem.rightBarButtonItem?.accessibilityLabel = NativeLanguage.t("Yenile")
    }
    private func configureOverlay() {
        overlay.axis = .vertical
        overlay.spacing = 18
        overlay.alignment = .center
        overlay.translatesAutoresizingMaskIntoConstraints = false
        overlay.backgroundColor = .systemBackground
        message.numberOfLines = 0
        message.textAlignment = .center
        message.font = .preferredFont(forTextStyle: .body)
        message.adjustsFontForContentSizeCategory = true
        retryButton.setTitle(NativeLanguage.t("Yeniden dene"), for: .normal)
        retryButton.addTarget(self, action: #selector(reloadPage), for: .touchUpInside)
        [spinner, message, retryButton].forEach { overlay.addArrangedSubview($0) }
        view.addSubview(overlay)
        NSLayoutConstraint.activate([
            overlay.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            overlay.centerYAnchor.constraint(equalTo: view.centerYAnchor),
            overlay.widthAnchor.constraint(equalTo: view.widthAnchor, constant: -48)
        ])
    }
    private func showSetupError() {
        let label = UILabel(frame: view.bounds)
        label.text = NativeLanguage.t("Uygulama dosyaları okunamadı. Uygulamayı yeniden yükleyin.")
        label.numberOfLines = 0
        label.textAlignment = .center
        label.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        view.addSubview(label)
    }
    func showError() {
        failed = true
        spinner.stopAnimating()
        refresh.endRefreshing()
        message.text = NativeLanguage.t("Güvenli bağlantı kurulamadı. İnternet bağlantınızı kontrol edip yeniden deneyin.")
        retryButton.isHidden = false
        overlay.isHidden = false
        webView.isHidden = true
    }
    @objc private func reloadPage() {
        if PortalConfiguration.allows(webView.url) { webView.reload() }
        else { webView.load(URLRequest(url: PortalConfiguration.home)) }
    }
    @objc private func goBack() { if webView.canGoBack { webView.goBack() } }
}

extension PortalViewController: WKNavigationDelegate {
    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = navigationAction.request.url else { decisionHandler(.cancel); return }
        if PortalConfiguration.allows(url) {
            if navigationAction.targetFrame == nil { decisionHandler(.cancel); webView.load(navigationAction.request) }
            else { decisionHandler(.allow) }
            return
        }
        decisionHandler(.cancel)
        if navigationAction.navigationType == .linkActivated,
           (navigationAction.targetFrame == nil || navigationAction.targetFrame?.isMainFrame == true),
           ["https", "mailto", "tel"].contains(url.scheme ?? "") { UIApplication.shared.open(url) }
    }
    func webView(_ webView: WKWebView, decidePolicyFor navigationResponse: WKNavigationResponse, decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void) {
        if navigationResponse.isForMainFrame,
           (!PortalConfiguration.allows(navigationResponse.response.url) || (navigationResponse.response as? HTTPURLResponse).map({ $0.statusCode >= 400 }) == true) {
            decisionHandler(.cancel); showError(); return
        }
        decisionHandler(.allow)
    }
    func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
        failed = false
        message.text = NativeLanguage.t("Çalışma alanınız açılıyor…")
        retryButton.isHidden = true
        spinner.startAnimating()
        overlay.isHidden = false
    }
    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        guard !failed, PortalConfiguration.allows(webView.url) else { return }
        overlay.isHidden = true
        webView.isHidden = false
        spinner.stopAnimating()
        refresh.endRefreshing()
        navigationItem.leftBarButtonItem?.isEnabled = webView.canGoBack
    }
    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { handle(error) }
    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) { handle(error) }
    private func handle(_ error: Error) { if (error as NSError).code != NSURLErrorCancelled { showError() } }
    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) { showError() }
}

extension PortalViewController: WKUIDelegate {
    func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping () -> Void) {
        guard presentedViewController == nil else { completionHandler(); return }
        let alert = UIAlertController(title: "Omnigo", message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: NativeLanguage.t("Tamam"), style: .default) { _ in completionHandler() })
        present(alert, animated: true)
    }
    func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (Bool) -> Void) {
        guard presentedViewController == nil else { completionHandler(false); return }
        let alert = UIAlertController(title: "Omnigo", message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: NativeLanguage.t("Vazgeç"), style: .cancel) { _ in completionHandler(false) })
        alert.addAction(UIAlertAction(title: NativeLanguage.t("Tamam"), style: .default) { _ in completionHandler(true) })
        present(alert, animated: true)
    }
}
