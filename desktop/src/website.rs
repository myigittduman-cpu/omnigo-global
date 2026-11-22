const WEBSITE: &str = "https://www.omnigoglobal.com/";

/// The caller cannot provide a command or URL; only the public Omnigo site opens.
pub fn open() -> Result<(), String> {
    #[cfg(windows)]
    {
        use windows::core::PCWSTR;
        use windows::Win32::Foundation::HWND;
        use windows::Win32::UI::{Shell::ShellExecuteW, WindowsAndMessaging::SW_SHOWNORMAL};
        let verb: Vec<u16> = "open\0".encode_utf16().collect();
        let url: Vec<u16> = WEBSITE.encode_utf16().chain(Some(0)).collect();
        let result = unsafe { ShellExecuteW(Some(HWND::default()), PCWSTR(verb.as_ptr()), PCWSTR(url.as_ptr()), PCWSTR::null(), PCWSTR::null(), SW_SHOWNORMAL) };
        if (result.0 as isize) <= 32 { return Err("Web sitesi tarayıcıda açılamadı.".into()); }
    }
    #[cfg(not(windows))]
    {
        let command = if cfg!(target_os = "macos") { "open" } else { "xdg-open" };
        std::process::Command::new(command).arg(WEBSITE).spawn().map_err(|_| "Web sitesi tarayıcıda açılamadı.".to_string())?;
    }
    Ok(())
}
