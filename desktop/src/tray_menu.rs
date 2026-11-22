use std::sync::atomic::{AtomicBool, Ordering};
use tauri::{
    menu::{CheckMenuItem, Menu, MenuItem, Submenu},
    tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent},
    App, AppHandle, Manager, Wry,
};
use tauri_plugin_dialog::DialogExt;
use crate::{application_preferences::{self, WindowMode}, background, native_language::t};

static NOTIFICATION_SETTINGS: AtomicBool = AtomicBool::new(false);

struct TrayMenu {
    show: MenuItem<Wry>,
    notifications: MenuItem<Wry>,
    version: MenuItem<Wry>,
    settings: Submenu<Wry>,
    windowed: CheckMenuItem<Wry>,
    maximized: CheckMenuItem<Wry>,
    fullscreen: CheckMenuItem<Wry>,
    startup: CheckMenuItem<Wry>,
    quit: MenuItem<Wry>,
}

fn show_label(app: &AppHandle) -> &'static str {
    if app.config().identifier == "com.omnigoglobal.admin" { "Yönetici portalını aç" } else { "İş Ortağım’ı aç" }
}

pub fn take_notification_settings() -> bool {
    NOTIFICATION_SETTINGS.swap(false, Ordering::AcqRel)
}

fn sync_checks(app: &AppHandle) {
    if let Some(menu) = app.try_state::<TrayMenu>() {
        let prefs = application_preferences::get(app);
        let _ = menu.windowed.set_checked(prefs.window_mode == WindowMode::Windowed);
        let _ = menu.maximized.set_checked(prefs.window_mode == WindowMode::Maximized);
        let _ = menu.fullscreen.set_checked(prefs.window_mode == WindowMode::Fullscreen);
        let _ = menu.startup.set_checked(prefs.launch_at_login.unwrap_or_else(|| background::enabled(app)));
    }
}

pub fn refresh_language(app: &AppHandle) {
    if let Some(menu) = app.try_state::<TrayMenu>() {
        let _ = menu.show.set_text(t(show_label(app)));
        let _ = menu.notifications.set_text(t("Bildirim ayarları"));
        let _ = menu.version.set_text(t("Sürüm bilgisi"));
        let _ = menu.settings.set_text(t("Uygulama ayarları"));
        let _ = menu.windowed.set_text(t("Pencere modunda aç"));
        let _ = menu.maximized.set_text(t("Büyütülmüş aç"));
        let _ = menu.fullscreen.set_text(t("Tam ekranda aç"));
        let _ = menu.startup.set_text(t("Windows ile başlat"));
        let _ = menu.quit.set_text(t("Tamamen çık (bildirimler durur)"));
    }
    if let Some(tray) = app.tray_by_id("omnigo-notifications") {
        let _ = tray.set_tooltip(Some(t("Omnigo Global · Bildirimler")));
    }
}

pub fn refresh_preferences(app: &AppHandle) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || sync_checks(&handle));
}

pub fn start(app: &mut App) -> tauri::Result<()> {
    let show = MenuItem::with_id(app, "show", t(show_label(app.handle())), true, None::<&str>)?;
    let notifications = MenuItem::with_id(app, "notifications", t("Bildirim ayarları"), true, None::<&str>)?;
    let version = MenuItem::with_id(app, "version", t("Sürüm bilgisi"), true, None::<&str>)?;
    let windowed = CheckMenuItem::with_id(app, "windowed", t("Pencere modunda aç"), true, false, None::<&str>)?;
    let maximized = CheckMenuItem::with_id(app, "maximized", t("Büyütülmüş aç"), true, false, None::<&str>)?;
    let fullscreen = CheckMenuItem::with_id(app, "fullscreen", t("Tam ekranda aç"), true, false, None::<&str>)?;
    let startup = CheckMenuItem::with_id(app, "startup", t("Windows ile başlat"), true, false, None::<&str>)?;
    let settings = Submenu::with_items(app, t("Uygulama ayarları"), true, &[&windowed, &maximized, &fullscreen, &startup])?;
    let quit = MenuItem::with_id(app, "quit", t("Tamamen çık (bildirimler durur)"), true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&show, &notifications, &version, &settings, &quit])?;
    let mut tray = TrayIconBuilder::with_id("omnigo-notifications").menu(&menu)
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| dispatch(app, event.id.as_ref()))
        .on_tray_icon_event(|tray, event| {
            if matches!(event, TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. }) {
                background::open(tray.app_handle());
            }
        });
    if let Some(icon) = app.default_window_icon() { tray = tray.icon(icon.clone()); }
    tray.build(app)?;
    app.manage(TrayMenu { show, notifications, version, settings, windowed, maximized, fullscreen, startup, quit });
    refresh_language(app.handle());
    sync_checks(app.handle());
    Ok(())
}

fn show_error(app: &AppHandle, result: Result<(), String>) {
    if let Err(error) = result {
        app.dialog().message(t(&error)).title(t("Uygulama ayarları")).show(|_| {});
    }
}

fn dispatch(app: &AppHandle, action: &str) {
    let mode = match action {
        "windowed" => Some(WindowMode::Windowed),
        "maximized" => Some(WindowMode::Maximized),
        "fullscreen" => Some(WindowMode::Fullscreen),
        _ => None,
    };
    if let Some(mode) = mode {
        let result = application_preferences::change_window_mode(app, mode);
        sync_checks(app);
        show_error(app, result);
        return;
    }
    match action {
        "show" => background::open(app),
        "notifications" => {
            NOTIFICATION_SETTINGS.store(true, Ordering::Release);
            background::open(app);
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.eval("window.dispatchEvent(new Event('omnigo-desktop-notification-settings'))");
            }
        }
        "version" => {
            let info = app.package_info();
            app.dialog().message(format!("{}\n{} {}", app.config().product_name.as_deref().unwrap_or("Omnigo"), t("Sürüm"), info.version))
                .title(t("Sürüm bilgisi")).show(|_| {});
        }
        "startup" => {
            let enabled = !application_preferences::get(app).launch_at_login.unwrap_or_else(|| background::enabled(app));
            let handle = app.clone();
            tauri::async_runtime::spawn_blocking(move || {
                let result = application_preferences::change_startup(&handle, enabled);
                let next = handle.clone();
                let _ = handle.run_on_main_thread(move || {
                    sync_checks(&next);
                    show_error(&next, result);
                });
            });
        }
        "quit" => app.exit(0),
        _ => {}
    }
}
