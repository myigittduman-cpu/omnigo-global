use tauri::{AppHandle, WebviewWindow};

#[tauri::command]
pub async fn desktop_action(app: AppHandle, window: WebviewWindow, action: String, viewport: Option<crate::startup_gate::Viewport>) -> Result<bool, String> {
    let url = window.url().map_err(|_| "Pencere doğrulanamadı.")?;
    if window.label() != "main" || !crate::reports::allowed_origin(&url, &app.config().identifier) {
        return Err("Bu sayfadan uygulama denetimlerine erişilemiyor.".into());
    }
    match action.as_str() {
        "open_website" => { crate::website::open()?; },
        "check_updates" => {
            tauri::async_runtime::spawn_blocking(move || crate::updates::run_check(&app, true))
                .await.map_err(|_| "Güncelleme kontrolü tamamlanamadı.")?;
        },
        "toolbar_ready" => {
            // Keep the system caption until the styled, working replacement is ready.
            window.set_decorations(false).map_err(window_error)?;
            crate::application_icon::apply(&window).map_err(window_error)?;
            return crate::startup::reveal_ready(app).await;
        },
        "content_ready" => { return crate::startup::content_ready(app,viewport).await; },
        "language:tr" | "language:en" | "language:de" => { crate::native_language::set(&app, &action[9..])?; },
        "window_state" => {},
        "take_notification_settings" => { return Ok(crate::tray_menu::take_notification_settings()); },
        "minimize" => window.minimize().map_err(window_error)?,
        "toggle_maximize" => {
            if window.is_maximized().map_err(window_error)? {
                window.unmaximize().map_err(window_error)?;
            } else {
                window.maximize().map_err(window_error)?;
            }
        },
        "start_dragging" => window.start_dragging().map_err(window_error)?,
        "close" => {
            window.close().map_err(window_error)?;
            return Ok(false);
        },
        _ => return Err("Uygulama işlemi desteklenmiyor.".into()),
    }
    window.is_maximized().map_err(window_error)
}

fn window_error(_: tauri::Error) -> String { "Pencere işlemi tamamlanamadı.".into() }

pub fn restore_caption(window: &WebviewWindow) {
    // Reloads, external navigation and network errors must retain usable window controls.
    if window.is_fullscreen().ok()!=Some(true){let _ = window.set_decorations(true);}
    let _ = crate::application_icon::apply(window);
}

#[cfg(windows)]
pub fn style_caption(window: &WebviewWindow) -> tauri::Result<()> {
    use windows::Win32::Graphics::Dwm::{DwmSetWindowAttribute, DWMWA_CAPTION_COLOR, DWMWA_TEXT_COLOR, DWMWA_BORDER_COLOR};
    let hwnd = window.hwnd()?;
    // Match the temporary fallback caption to the application while its page loads.
    for (attribute, color) in [(DWMWA_CAPTION_COLOR, 0x00211710u32),
        (DWMWA_TEXT_COLOR, 0x00F0EBE5u32), (DWMWA_BORDER_COLOR, 0x003E3329u32)] {
        unsafe { let _ = DwmSetWindowAttribute(hwnd, attribute, &color as *const u32 as _, std::mem::size_of::<u32>() as u32); }
    }
    Ok(())
}
