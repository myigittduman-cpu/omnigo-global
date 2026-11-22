use tauri::WebviewWindow;

#[derive(Clone, Copy, Debug, Default, serde::Deserialize, PartialEq, serde::Serialize)]
#[serde(rename_all = "snake_case")]
pub enum WindowMode {
    Windowed,
    #[default]
    Maximized,
    Fullscreen,
}

pub fn apply_mode(window: &WebviewWindow, mode: WindowMode) -> tauri::Result<()> {
    match mode {
        WindowMode::Fullscreen => window.set_fullscreen(true),
        WindowMode::Windowed | WindowMode::Maximized => {
            if window.is_fullscreen()? { window.set_fullscreen(false)?; }
            if mode == WindowMode::Maximized { window.maximize() } else { window.unmaximize() }
        }
    }
}

#[cfg(windows)]
static RESTORE_PLACEMENT: std::sync::Mutex<Option<windows::Win32::UI::WindowsAndMessaging::WINDOWPLACEMENT>> = std::sync::Mutex::new(None);

#[cfg(windows)]
fn native_error(error: windows::core::Error) -> tauri::Error { tauri::Error::Io(std::io::Error::other(error.to_string())) }

#[cfg(windows)]
fn cloak(window: &WebviewWindow, hidden: bool) -> tauri::Result<()> {
    use windows::{core::BOOL, Win32::Graphics::Dwm::{DwmSetWindowAttribute, DWMWA_CLOAK}};
    let value=BOOL::from(hidden);
    unsafe { DwmSetWindowAttribute(window.hwnd()?,DWMWA_CLOAK,&value as *const BOOL as _,std::mem::size_of::<BOOL>() as u32) }.map_err(native_error)
}

#[cfg(windows)]
fn transitions(window: &WebviewWindow, disabled: bool) {
    use windows::{core::BOOL, Win32::Graphics::Dwm::{DwmSetWindowAttribute, DWMWA_TRANSITIONS_FORCEDISABLED}};
    if let Ok(hwnd)=window.hwnd(){
        let value=BOOL::from(disabled);
        unsafe { let _=DwmSetWindowAttribute(hwnd,DWMWA_TRANSITIONS_FORCEDISABLED,&value as *const BOOL as _,std::mem::size_of::<BOOL>() as u32); }
    }
}

#[cfg(windows)]
fn maximize_cloaked(window: &WebviewWindow) -> tauri::Result<()> {
    use windows::Win32::UI::WindowsAndMessaging::{GetWindowPlacement, SetWindowPlacement, WINDOWPLACEMENT, SW_SHOWMAXIMIZED};
    let hwnd=window.hwnd()?;
    let mut placement=WINDOWPLACEMENT {length:std::mem::size_of::<WINDOWPLACEMENT>() as u32,..Default::default()};
    unsafe {
        GetWindowPlacement(hwnd,&mut placement).map_err(native_error)?;
        if let Ok(mut saved)=RESTORE_PLACEMENT.lock(){
            if saved.is_none(){*saved=Some(placement);}
            if let Some(original)=*saved{placement=original;}
        }
        placement.showCmd=SW_SHOWMAXIMIZED.0 as u32;
        SetWindowPlacement(hwnd,&placement).map_err(native_error)?;
    }
    Ok(())
}

/// DWM composes the real maximized window while only the welcome square is visible.
#[cfg(windows)]
pub fn prepare_hidden(window: &WebviewWindow, mode: WindowMode, background_launch: bool) -> tauri::Result<()> {
    cloak(window,true)?;
    transitions(window,true);
    match mode {
        WindowMode::Maximized => maximize_cloaked(window)?,
        WindowMode::Fullscreen => window.set_fullscreen(true)?,
        WindowMode::Windowed => (),
    }
    // A cloaked but visible window can leave a taskbar button which never requests activation.
    if background_launch { window.hide() } else { window.show() }
}

#[cfg(not(windows))]
pub fn prepare_hidden(window: &WebviewWindow, mode: WindowMode, _background_launch: bool) -> tauri::Result<()> {
    apply_mode(window, mode)
}

#[cfg(windows)]
pub fn settle_chrome(window: &WebviewWindow) -> tauri::Result<()> {
    use windows::Win32::UI::WindowsAndMessaging::{SetWindowPos, SWP_FRAMECHANGED, SWP_NOMOVE, SWP_NOSIZE, SWP_NOACTIVATE, SWP_NOZORDER};
    unsafe { SetWindowPos(window.hwnd()?,None,0,0,0,0,SWP_FRAMECHANGED|SWP_NOMOVE|SWP_NOSIZE|SWP_NOACTIVATE|SWP_NOZORDER) }.map_err(native_error)
}

#[cfg(not(windows))]
pub fn settle_chrome(_window: &WebviewWindow) -> tauri::Result<()> { Ok(()) }

fn restore_visibility(window: &WebviewWindow) -> tauri::Result<()> {
    if !window.is_visible()? { window.show()?; }
    if window.is_minimized()? { window.unminimize()?; }
    Ok(())
}

pub fn activate(window: &WebviewWindow) -> tauri::Result<()> {
    restore_visibility(window)?;
    window.set_focus()
}

/// Reveal the composed frame at the prepared size, including a Windows login launch.
#[cfg(windows)]
pub fn reveal_prepared(window: &WebviewWindow) -> tauri::Result<()> {
    use windows::Win32::Graphics::Dwm::DwmFlush;
    restore_visibility(window)?;
    unsafe { let _=DwmFlush(); }
    cloak(window,false)?;
    unsafe { let _=DwmFlush(); }
    transitions(window,false);
    window.set_focus()
}

#[cfg(not(windows))]
pub fn reveal_prepared(window: &WebviewWindow) -> tauri::Result<()> { activate(window) }
