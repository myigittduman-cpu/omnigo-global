use std::path::{Path, PathBuf};
use tauri::{AppHandle, Manager};

pub fn icon_path(app: &AppHandle) -> Result<PathBuf, String> {
    let path = app.path().resolve("notification-icon.png", tauri::path::BaseDirectory::Resource)
        .map_err(|_| "Bildirim simgesinin konumu bulunamadı.")?;
    if !path.is_file() { return Err("Bildirim simgesi bulunamadı.".into()); }
    shell_icon_path(&path)
}

fn shell_icon_path(path: &Path) -> Result<PathBuf, String> {
    // Tauri resolves Windows resources with the \\?\ prefix. File APIs accept it,
    // but Shell IconUri and toast image URIs need a regular absolute file path.
    tauri::Url::from_file_path(path).and_then(|url| url.to_file_path())
        .map_err(|_| "Bildirim simgesinin dosya adresi geçersiz.".into())
}

#[cfg(windows)]
pub fn toast_icon_source(path: &Path) -> Result<PathBuf, String> {
    use std::os::windows::ffi::OsStrExt;
    use windows::{core::{PCWSTR, PWSTR}, Win32::UI::Shell::UrlCreateFromPathW};
    let input: Vec<u16> = path.as_os_str().encode_wide().chain(Some(0)).collect();
    let mut output = vec![0u16; 32768];
    let mut length = output.len() as u32;
    unsafe { UrlCreateFromPathW(PCWSTR(input.as_ptr()), PWSTR(output.as_mut_ptr()), &mut length, 0) }
        .map_err(|_| "Bildirim simgesinin adresi oluşturulamadı.")?;
    let end = output.iter().position(|value| *value == 0).ok_or("Bildirim simgesinin adresi çok uzun.")?;
    let url = String::from_utf16(&output[..end]).map_err(|_| "Bildirim simgesinin adresi geçersiz.")?;
    // tauri-winrt-notification 0.8.1 adds file:/// itself. Escape the rest once
    // with the Shell API so Unicode and reserved characters round-trip in Windows.
    url.strip_prefix("file:///").map(PathBuf::from)
        .ok_or_else(|| "Bildirim simgesi yerel diskte bulunmalı.".into())
}

#[cfg(all(windows, not(feature = "microsoft-store")))]
pub fn register(app: &AppHandle) -> Result<(), String> {
    use winreg::{enums::HKEY_CURRENT_USER, RegKey};
    let name = crate::application_name::for_notification_identifier(&app.config().identifier)
        .ok_or("Bildirim uygulaması tanınmadı.")?;
    let icon = icon_path(app)?;
    let (key, _) = RegKey::predef(HKEY_CURRENT_USER)
        .create_subkey(format!(r"Software\Classes\AppUserModelId\{}", app.config().identifier))
        .map_err(|_| "Bildirim uygulaması kaydedilemedi.")?;
    write_assets(&key, name, &icon).map_err(|_| "Bildirim simgesi kaydedilemedi.".into())
}

#[cfg(all(windows, feature = "microsoft-store"))]
pub fn register(_app: &AppHandle) -> Result<(), String> { Ok(()) }

pub fn notification_id(app: &AppHandle) -> Result<String, String> {
    #[cfg(all(windows, feature = "microsoft-store"))]
    {
        let _ = app;
        windows::ApplicationModel::AppInfo::Current()
            .and_then(|info| info.AppUserModelId()).map(|id| id.to_string())
            .map_err(|_| "Microsoft Store uygulama kimliği okunamadı.".into())
    }
    #[cfg(not(all(windows, feature = "microsoft-store")))]
    { Ok(app.config().identifier.clone()) }
}

#[cfg(windows)]
fn write_assets(key: &winreg::RegKey, name: &str, icon: &Path) -> std::io::Result<()> {
    // Keep the existing AppUserModelId, activation and the user's notification preferences.
    key.set_value("DisplayName", &name)?;
    key.set_value("IconUri", &icon.to_string_lossy().as_ref())
}

#[cfg(not(windows))]
pub fn register(_app: &AppHandle) -> Result<(), String> { Ok(()) }

#[cfg(all(test, windows))]
mod tests {
    #[test]
    fn canonical_resource_path_is_readable_by_the_windows_image_loader() {
        use std::os::windows::ffi::OsStringExt;
        use windows::{core::{PCWSTR, PWSTR}, Win32::UI::Shell::PathCreateFromUrlW};
        fn decode(uri: &str) -> Result<std::path::PathBuf, windows::core::Error> {
            let input: Vec<u16> = uri.encode_utf16().chain(Some(0)).collect();
            let mut output = vec![0u16; 32768];
            let mut length = output.len() as u32;
            unsafe { PathCreateFromUrlW(PCWSTR(input.as_ptr()), PWSTR(output.as_mut_ptr()), &mut length, 0)?; }
            let end = output.iter().position(|value| *value == 0).unwrap();
            Ok(std::ffi::OsString::from_wide(&output[..end]).into())
        }
        let folder = tempfile::tempdir().unwrap();
        let path = folder.path().join("Örnek #50 % Yönetim.png");
        let bytes = include_bytes!("../icons/icon.png");
        std::fs::write(&path, bytes).unwrap();
        let canonical = path.canonicalize().unwrap();
        assert!(canonical.to_string_lossy().starts_with(r"\\?\"));
        let normalized = super::shell_icon_path(&canonical).unwrap();
        assert!(!normalized.to_string_lossy().starts_with(r"\\?\"));
        assert_eq!(std::fs::read(&normalized).unwrap(), bytes);
        let source = super::toast_icon_source(&normalized).unwrap();
        let uri = format!("file:///{}", source.display());
        assert!(uri.contains("%23") && uri.contains("%25") && uri.contains("%20"));
        assert_eq!(std::fs::read(decode(&uri).unwrap()).unwrap(), bytes);
        let old = decode(&format!("file:///{}", canonical.display()));
        assert!(old.is_err() || !old.unwrap().is_file());
    }

    #[test]
    fn branding_preserves_activation_and_notification_preferences() {
        use winreg::{enums::HKEY_CURRENT_USER, RegKey};
        let root = RegKey::predef(HKEY_CURRENT_USER);
        let path = format!(r"Software\OmnigoGlobal\Tests\NotificationBranding-{}", std::process::id());
        let (key, _) = root.create_subkey(&path).unwrap();
        key.set_value("CustomActivator", &"existing-activation").unwrap();
        key.set_value("Enabled", &0u32).unwrap();
        super::write_assets(&key, "Omnigo Global İş Ortağım", std::path::Path::new(r"C:\Örnek Dizin\notification-icon.png")).unwrap();
        assert_eq!(key.get_value::<String, _>("DisplayName").unwrap(), "Omnigo Global İş Ortağım");
        assert_eq!(key.get_value::<String, _>("IconUri").unwrap(), r"C:\Örnek Dizin\notification-icon.png");
        assert_eq!(key.get_value::<String, _>("CustomActivator").unwrap(), "existing-activation");
        assert_eq!(key.get_value::<u32, _>("Enabled").unwrap(), 0);
        drop(key);
        root.delete_subkey(&path).unwrap();
    }
}
