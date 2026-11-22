import UIKit

@main
final class AppDelegate: UIResponder, UIApplicationDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey:Any]? = nil) -> Bool { MobilePushController.shared.start(); return true }
    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) { MobilePushController.shared.registered(deviceToken) }
    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) { MobilePushController.shared.failed() }
    func application(_ application: UIApplication, configurationForConnecting connectingSceneSession: UISceneSession,
                     options: UIScene.ConnectionOptions) -> UISceneConfiguration {
        let config = UISceneConfiguration(name: "Portal", sessionRole: connectingSceneSession.role)
        config.delegateClass = SceneDelegate.self
        return config
    }
}

final class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?
    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options: UIScene.ConnectionOptions) {
        guard let scene = scene as? UIWindowScene else { return }
        if let response = options.notificationResponse { MobilePushController.shared.open(response.notification.request.content.userInfo) }
        let window = UIWindow(windowScene: scene)
        window.rootViewController = UINavigationController(rootViewController: PortalViewController())
        self.window = window
        window.makeKeyAndVisible()
    }
    func sceneWillResignActive(_ scene: UIScene) {
        // Hide customer/order content from the app switcher's screenshot.
        guard let window else { return }
        let cover = UIView(frame: window.bounds)
        cover.backgroundColor = .systemBackground
        cover.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        cover.tag = 7124
        window.addSubview(cover)
    }
    func sceneDidBecomeActive(_ scene: UIScene) { window?.viewWithTag(7124)?.removeFromSuperview() }
}
