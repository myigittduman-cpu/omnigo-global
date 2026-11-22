use std::{
    io::{ErrorKind, Write},
    os::windows::ffi::OsStrExt,
    path::{Path, PathBuf},
    sync::atomic::{AtomicBool, Ordering},
};

use tauri::{AppHandle, Manager, WebviewWindow};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use windows::{
    core::{Interface, PCWSTR},
    Win32::{
        Storage::EnhancedStorage::PKEY_AppUserModel_ID,
        System::{
            Com::{CoCreateInstance, CoTaskMemFree, IPersistFile, CLSCTX_INPROC_SERVER,
                StructuredStorage::{PropVariantClear, PROPVARIANT}},
            Variant::VT_LPWSTR,
        },
        UI::Shell::{IShellLinkW, ShellLink, SHChangeNotify, SHParseDisplayName, SHStrDupW, SHCNE_CREATE,
            SHCNF_FLUSHNOWAIT, SHCNF_PATHW, PropertiesSystem::IPropertyStore},
    },
};

static OFFERED: AtomicBool = AtomicBool::new(false);
const SKIP: &str = "skipped";

fn save_choice(path: &Path, value: &str) -> std::io::Result<()> {
    let folder = path.parent().ok_or_else(|| std::io::Error::other("Ayar klasörü bulunamadı."))?;
    std::fs::create_dir_all(folder)?;
    let mut file = tempfile::NamedTempFile::new_in(folder)?;
    file.write_all(value.as_bytes())?;
    file.persist(path)?;
    Ok(())
}

fn wide(value: &std::ffi::OsStr) -> Vec<u16> {
    value.encode_wide().chain(Some(0)).collect()
}

// Target the registered Shell application, so an updated package can live at a new physical path.
fn create_link(path: &Path, name: &str, app_id: &str) -> Result<(), Box<dyn std::error::Error>> {
    let _apartment = crate::shortcut_icons::windows_icons::Apartment::open()?;
    let desktop = path.parent().ok_or("Masaüstü klasörü bulunamadı.")?;
    let temporary = tempfile::Builder::new().suffix(".lnk").tempfile_in(desktop)?.into_temp_path();
    let target: Vec<u16> = format!(r"shell:AppsFolder\{app_id}").encode_utf16().chain(Some(0)).collect();
    let label: Vec<u16> = name.encode_utf16().chain(Some(0)).collect();
    let app_id: Vec<u16> = app_id.encode_utf16().chain(Some(0)).collect();

    unsafe {
        let link: IShellLinkW = CoCreateInstance(&ShellLink, None, CLSCTX_INPROC_SERVER)?;
        let mut item = std::ptr::null_mut();
        SHParseDisplayName(PCWSTR(target.as_ptr()), None, &mut item, 0, None)?;
        let set_target = link.SetIDList(item);
        CoTaskMemFree(Some(item.cast()));
        set_target?;
        link.SetDescription(PCWSTR(label.as_ptr()))?;

        let properties: IPropertyStore = link.cast()?;
        let mut value = PROPVARIANT::default();
        // Windows property values use the COM allocator and are cleared with PropVariantClear.
        let fields = &mut *value.Anonymous.Anonymous;
        fields.Anonymous.pwszVal = SHStrDupW(PCWSTR(app_id.as_ptr()))?;
        fields.vt = VT_LPWSTR;
        let set_id = properties.SetValue(&PKEY_AppUserModel_ID, &value);
        let release = PropVariantClear(&mut value);
        set_id?;
        release?;
        properties.Commit()?;

        let file: IPersistFile = link.cast()?;
        file.Save(PCWSTR(wide(temporary.as_os_str()).as_ptr()), false)?;
    }

    // A second installer or OneDrive sync can place a link while the consent dialog is open.
    // Keep that file and let the user continue opening the portal.
    temporary.persist_noclobber(path)?;
    unsafe {
        let name = wide(path.as_os_str());
        SHChangeNotify(SHCNE_CREATE, SHCNF_PATHW | SHCNF_FLUSHNOWAIT, Some(name.as_ptr().cast()), None);
    }
    Ok(())
}

fn apply_choice(approved: bool, path: PathBuf, preference: PathBuf, name: &'static str, app_id: String, version: String) {
    std::thread::spawn(move || {
        if !approved {
            let _ = save_choice(&preference, SKIP);
            return;
        }
        if create_link(&path, name, &app_id).is_ok() {
            let _ = save_choice(&preference, &version);
        } else {
            eprintln!("Masaüstü kısayolu oluşturulamadı.");
        }
    });
}

pub fn offer(app: &AppHandle, window: &WebviewWindow) {
    if OFFERED.swap(true, Ordering::AcqRel) { return; }
    let Some(name) = crate::application_name::for_identifier(&app.config().identifier) else { return; };
    let Ok(desktop) = app.path().desktop_dir() else { return; };
    let path = desktop.join(format!("{name}.lnk"));
    if !matches!(std::fs::symlink_metadata(&path), Err(error) if error.kind() == ErrorKind::NotFound) {
        return;
    }
    let Ok(folder) = app.path().app_local_data_dir() else { return; };
    let preference = folder.join("desktop-shortcut.choice");
    let version = format!("created:{}", app.package_info().version);
    let previous = std::fs::read_to_string(&preference).ok();
    if matches!(previous.as_deref(), Some(value) if value == SKIP || value == version) { return; }
    let Ok(app_id) = crate::notification_branding::notification_id(app) else { return; };

    if previous.is_some_and(|value| value.starts_with("created:")) {
        apply_choice(true, path, preference, name, app_id, version);
        return;
    }

    use crate::native_language::t;
    app.dialog().message(t("Bu uygulama için masaüstü kısayolu eklemek ister misiniz?"))
        .title(name)
        .parent(window)
        .buttons(MessageDialogButtons::OkCancelCustom(t("Kısayol ekle"), t("Atla")))
        .show(move |approved| apply_choice(approved, path, preference, name, app_id, version));
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    #[ignore = "Kurulu İş Ortağım Store kimliğiyle çalıştırılır"]
    fn registered_package_link_preserves_shell_target_and_existing_file() {
        use windows::Win32::UI::Shell::{SHGetNameFromIDList, SIGDN_DESKTOPABSOLUTEPARSING};
        let app_id = std::env::var("OMNIGO_TEST_STORE_APP_ID").unwrap();
        let folder = tempfile::tempdir().unwrap();
        let path = folder.path().join("OG İş Ortağım.lnk");
        create_link(&path, "OG İş Ortağım", &app_id).unwrap();
        let _apartment = crate::shortcut_icons::windows_icons::Apartment::open().unwrap();
        unsafe {
            let link: IShellLinkW = CoCreateInstance(&ShellLink, None, CLSCTX_INPROC_SERVER).unwrap();
            let file: IPersistFile = link.cast().unwrap();
            file.Load(PCWSTR(wide(path.as_os_str()).as_ptr()), windows::Win32::System::Com::STGM_READ).unwrap();
            let target = link.GetIDList().unwrap();
            let name = SHGetNameFromIDList(target, SIGDN_DESKTOPABSOLUTEPARSING).unwrap();
            let resolved = name.to_string().unwrap();
            CoTaskMemFree(Some(name.0.cast()));
            CoTaskMemFree(Some(target.cast()));
            assert!(resolved.contains(&app_id), "{resolved}");
        }
        let before = std::fs::read(&path).unwrap();
        assert!(create_link(&path, "OG İş Ortağım", &app_id).is_err());
        assert_eq!(std::fs::read(&path).unwrap(), before);
    }
}
