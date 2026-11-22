use std::sync::atomic::{AtomicBool, Ordering};
use tauri::{AppHandle, WebviewWindow};
use tauri_plugin_dialog::DialogExt;
use crate::report_files::{decode_report, write_report};

static SAVING: AtomicBool = AtomicBool::new(false);
struct SaveGuard;
impl Drop for SaveGuard { fn drop(&mut self) { SAVING.store(false, Ordering::Release); } }

pub(crate) fn allowed_origin(url: &tauri::Url, identifier: &str) -> bool {
    let host = match identifier {
        "com.omnigoglobal.client" => "portal.omnigoglobal.com",
        "com.omnigoglobal.admin" => "yonetim.omnigoglobal.com",
        _ => return false,
    };
    url.scheme() == "https" && url.host_str() == Some(host) && url.port_or_known_default() == Some(443)
}

#[tauri::command]
pub async fn save_report(app: AppHandle, window: WebviewWindow, filename: String, contents: String) -> Result<&'static str, String> {
    let url = window.url().map_err(|_| "Rapor kaynağı doğrulanamadı.")?;
    if window.label() != "main" || !allowed_origin(&url, &app.config().identifier) {
        return Err("Bu sayfadan rapor kaydetmeye izin verilmiyor.".into());
    }
    if SAVING.compare_exchange(false, true, Ordering::AcqRel, Ordering::Relaxed).is_err() {
        return Err("Önce açık olan kaydetme işlemini tamamlayın.".into());
    }
    let _guard = SaveGuard;
    let (bytes, extension) = decode_report(&filename, &contents)?;
    tauri::async_runtime::spawn_blocking(move || {
        let selected = app.dialog().file().set_parent(&window).set_title(crate::native_language::t("Raporu farklı kaydet"))
            .set_file_name(&filename).add_filter(crate::native_language::t("Rapor dosyası"), &[extension]).blocking_save_file();
        let Some(selected) = selected else { return Ok("cancelled"); };
        let path = selected.into_path().map_err(|_| "Kayıt konumu geçersiz.".to_string())?;
        write_report(&path, &bytes, extension)?;
        Ok("saved")
    }).await.map_err(|_| "Rapor kaydedilemedi.".to_string())?
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn only_this_channels_https_origin_can_save() {
        assert!(allowed_origin(&"https://portal.omnigoglobal.com/".parse().unwrap(), "com.omnigoglobal.client"));
        for url in ["http://portal.omnigoglobal.com/", "https://portal.omnigoglobal.com.evil.test/", "https://portal.omnigoglobal.com:444/", "https://yonetim.omnigoglobal.com/"] {
            assert!(!allowed_origin(&url.parse().unwrap(), "com.omnigoglobal.client"));
        }
        assert!(allowed_origin(&"https://yonetim.omnigoglobal.com/".parse().unwrap(), "com.omnigoglobal.admin"));
    }
}
