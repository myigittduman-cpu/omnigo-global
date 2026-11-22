use windows::{
    core::{w, PCWSTR},
    Win32::UI::{
        Shell::ShellExecuteW,
        WindowsAndMessaging::{MessageBoxW, IDOK, MB_ICONWARNING, MB_OKCANCEL, SW_SHOWNORMAL},
    },
};

pub fn ready(background_launch: bool) -> bool {
    if tauri::webview_version().is_ok() {
        return true;
    }

    if !background_launch {
        unsafe {
            if MessageBoxW(
                None,
                w!("Microsoft Edge WebView2 Runtime bulunamadı. Microsoft’un indirme sayfasını açmak için Tamam’ı seçin. Kurulumdan sonra OG İş Ortağım’ı yeniden açın."),
                w!("OG İş Ortağım"),
                MB_OKCANCEL | MB_ICONWARNING,
            ) == IDOK
            {
                ShellExecuteW(
                    None,
                    w!("open"),
                    w!("https://developer.microsoft.com/microsoft-edge/webview2/"),
                    PCWSTR::null(),
                    PCWSTR::null(),
                    SW_SHOWNORMAL,
                );
            }
        }
    }
    false
}
