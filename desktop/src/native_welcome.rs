use std::{sync::atomic::{AtomicBool, Ordering}, time::Duration};
use tauri::{App, AppHandle, Manager, WebviewUrl, WebviewWindowBuilder};

static FINISHED: AtomicBool = AtomicBool::new(false);
static STARTED: AtomicBool = AtomicBool::new(false);
const DURATION_MS: u64 = 5000;

pub fn finished() -> bool { FINISHED.load(Ordering::Acquire) }

/// The main portal stays hidden at its final size while only this local square is visible.
pub fn start(app: &mut App) -> tauri::Result<()> {
    if crate::background::silent() { FINISHED.store(true, Ordering::Release); return Ok(()); }
    let role = if app.config().identifier == "com.omnigoglobal.admin" { "Yönetici Portalı" } else { "İş Ortağım" };
    let script = format!("window.OMNIGO_WELCOME_ROLE={};window.OMNIGO_WELCOME_DURATION_MS={DURATION_MS};", serde_json::to_string(role).unwrap());
    WebviewWindowBuilder::new(app, "welcome", WebviewUrl::App("welcome.html".into()))
        .title("Omnigo Global")
        .inner_size(404.0, 404.0).center().resizable(false)
        .decorations(false).transparent(true).shadow(false).skip_taskbar(true)
        .visible(false).initialization_script(script).build()?;
    Ok(())
}

pub fn loaded(app: AppHandle) {
    if STARTED.swap(true, Ordering::AcqRel) { return; }
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        if let Some(window) = handle.get_webview_window("welcome") {
            let _ = window.show();
            let _ = window.eval("document.documentElement.classList.add('visible')");
        }
        std::thread::spawn(move || {
            std::thread::sleep(Duration::from_millis(DURATION_MS));
            FINISHED.store(true, Ordering::Release);
            crate::startup::reveal_after_welcome(handle);
        });
    });
}

pub fn dismiss(app: &AppHandle) {
    FINISHED.store(true, Ordering::Release);
    if let Some(window) = app.get_webview_window("welcome") { let _ = window.close(); }
}

pub fn skip() { FINISHED.store(true, Ordering::Release); }
