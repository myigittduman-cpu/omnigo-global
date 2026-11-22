use std::{sync::atomic::{AtomicBool, Ordering}, time::Duration};
use tauri::{App, AppHandle, Manager, WebviewWindowBuilder};

static SHOWN: AtomicBool = AtomicBool::new(false);
static CHROME_READY: AtomicBool = AtomicBool::new(false);
static CONTENT_READY: AtomicBool = AtomicBool::new(false);
static WINDOW_PREPARED: AtomicBool = AtomicBool::new(false);
static ACTIVATE_REQUESTED: AtomicBool = AtomicBool::new(false);

pub fn pending() -> bool { !SHOWN.load(Ordering::Acquire) }

pub fn start(app: &mut App) -> tauri::Result<()> {
    let configuration = app.config().app.windows.iter().find(|window| window.label == "main")
        .expect("Ana pencere yapılandırması bulunamadı.");
    // Use the webview initialization hook, after Tauri's IPC setup, on every navigation.
    let script = format!("{}\n{}", include_str!("desktop.js"), include_str!("toolbar.js"))
        .replace("__DESKTOP_VERSION__", &app.package_info().version.to_string())
        .replace("__DESKTOP_PLATFORM__", std::env::consts::OS)
        .replace("__PORTAL_ORIGIN__", if app.config().identifier == "com.omnigoglobal.admin" {
            "https://yonetim.omnigoglobal.com"
        } else { "https://portal.omnigoglobal.com" });
    let window = WebviewWindowBuilder::from_config(app, configuration)?
        // Prepare the real maximized frame under DWM cloaking before the first visible frame.
        .fullscreen(false).maximized(false).visible(false)
        .initialization_script(script).build()?;
    crate::window_presentation::prepare_hidden(
        &window, crate::application_preferences::get(app.handle()).window_mode, crate::background::silent(),
    )?;
    crate::application_icon::apply(&window)?;
    #[cfg(windows)]
    crate::chrome::style_caption(&window)?;
    if let Err(error) = crate::native_welcome::start(app) {
        eprintln!("Karşılama penceresi açılamadı: {error}");
        crate::native_welcome::skip();
    }
    WINDOW_PREPARED.store(true, Ordering::Release);
    if ACTIVATE_REQUESTED.load(Ordering::Acquire) {
        activate(app.handle());
    }
    let handle = app.handle().clone();
    std::thread::spawn(move || {
        // A failed/slow connection must never leave an invisible application running forever.
        std::thread::sleep(Duration::from_secs(8));
        reveal(handle);
    });
    Ok(())
}

/// Use the same foreground path for shortcuts, tray clicks and notification activation.
pub fn activate(app: &AppHandle) {
    ACTIVATE_REQUESTED.store(true, Ordering::Release);
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        if let Err(error) = activate_on_main_thread(&handle) {
            eprintln!("Ana pencere öne getirilemedi: {error}");
        }
    });
}

fn activate_on_main_thread(app: &AppHandle) -> tauri::Result<()> {
    if !WINDOW_PREPARED.load(Ordering::Acquire) || !ACTIVATE_REQUESTED.swap(false, Ordering::AcqRel) {
        return Ok(());
    }
    let background_launch = crate::background::end_silent_start();
    if pending() {
        reveal_on_main_thread(app, background_launch || crate::native_welcome::finished())?;
    } else if let Some(window) = app.get_webview_window("main") {
        crate::window_presentation::activate(&window)?;
    }
    Ok(())
}

pub fn reveal(app: AppHandle) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        let _ = reveal_on_main_thread(&handle, true);
    });
}

pub fn reveal_after_welcome(app: AppHandle) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || { let _ = reveal_on_main_thread(&handle, false); });
}

pub async fn reveal_ready(app: AppHandle) -> Result<bool, String> {
    let (sender,mut receiver)=tauri::async_runtime::channel(1);
    let handle=app.clone();
    app.run_on_main_thread(move||{
        let operation=||->tauri::Result<bool>{
            if pending(){
                if let Some(window)=handle.get_webview_window("main"){crate::window_presentation::settle_chrome(&window)?;}
            }
            CHROME_READY.store(true,Ordering::Release);
            reveal_on_main_thread(&handle,false)
        };
        let _=sender.try_send(operation().map_err(|_|"Pencere düzeni hazırlanamadı.".to_string()));
    }).map_err(|_|"Pencere başlatılamadı.".to_string())?;
    receiver.recv().await.ok_or_else(||"Pencere başlatılamadı.".to_string())?
}

pub async fn content_ready(app: AppHandle, viewport:Option<crate::startup_gate::Viewport>) -> Result<bool, String> {
    if pending(){
        let window=app.get_webview_window("main").ok_or("Ana pencere bulunamadı.")?;
        let size=window.inner_size().map_err(|_|"Pencere ölçüsü okunamadı.")?;
        let Some(viewport)=viewport else{return Ok(false);};
        if !crate::startup_gate::viewport_matches(&viewport,size.width,size.height){return Ok(false);}
    }
    CONTENT_READY.store(true, Ordering::Release);
    reveal_when_ready(app).await?;
    Ok(true)
}

async fn reveal_when_ready(app: AppHandle) -> Result<bool, String> {
    let (sender, mut receiver) = tauri::async_runtime::channel(1);
    let handle = app.clone();
    app.run_on_main_thread(move || {
        let result = reveal_on_main_thread(&handle, false).map_err(|_| "Pencere gösterilemedi.".to_string());
        let _ = sender.try_send(result);
    }).map_err(|_| "Pencere başlatılamadı.".to_string())?;
    receiver.recv().await.ok_or_else(|| "Pencere başlatılamadı.".to_string())?
}

fn reveal_on_main_thread(app: &AppHandle, fallback: bool) -> tauri::Result<bool> {
    let window = app.get_webview_window("main").ok_or_else(|| tauri::Error::Io(
        std::io::Error::other("Ana pencere bulunamadı.")))?;
    // Page reloads and the network timeout must not override a user's later window size.
    if crate::startup_gate::should_reveal(CHROME_READY.load(Ordering::Acquire),
        CONTENT_READY.load(Ordering::Acquire), SHOWN.load(Ordering::Acquire), crate::background::silent(), fallback)
        && (fallback || crate::native_welcome::finished()) {
        crate::window_presentation::reveal_prepared(&window)?;
        crate::native_welcome::dismiss(app);
        SHOWN.store(true, Ordering::Release);
        #[cfg(all(windows, feature = "microsoft-store"))]
        crate::store_shortcut::offer(app, &window);
    }
    window.is_maximized()
}

#[cfg(all(test, windows))]
#[path = "startup_activation_test.rs"]
mod activation_test;
