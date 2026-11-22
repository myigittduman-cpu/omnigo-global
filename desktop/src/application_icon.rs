// The runtime sets the small window icon; Windows needs a separate large taskbar icon.
#[cfg(windows)]
pub fn apply(window:&tauri::WebviewWindow)->tauri::Result<()> {
    use std::{os::windows::ffi::OsStrExt,sync::OnceLock};
    use windows::{core::PCWSTR,Win32::{Foundation::{LPARAM,WPARAM},UI::{Shell::ExtractIconExW,WindowsAndMessaging::{HICON,SendMessageW,WM_SETICON,ICON_BIG}}}};
    // Icons stay valid for the process lifetime. Do not destroy a handle while Windows uses it.
    static LARGE_ICON:OnceLock<usize>=OnceLock::new();
    let icon=*LARGE_ICON.get_or_init(||{
        let Ok(executable)=std::env::current_exe() else{return 0};
        let path:Vec<u16>=executable.as_os_str().encode_wide().chain(Some(0)).collect();
        let mut large=HICON::default();
        unsafe{ExtractIconExW(PCWSTR(path.as_ptr()),0,Some(&mut large),None,1);}
        large.0 as usize
    });
    if icon!=0 {unsafe{SendMessageW(window.hwnd()?,WM_SETICON,Some(WPARAM(ICON_BIG as usize)),Some(LPARAM(icon as isize)));}}
    Ok(())
}
#[cfg(not(windows))]
pub fn apply(_window:&tauri::WebviewWindow)->tauri::Result<()>{Ok(())}
