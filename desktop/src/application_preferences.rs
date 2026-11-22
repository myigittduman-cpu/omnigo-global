use serde::{Deserialize, Serialize};
use std::{io::Write, path::Path, sync::Mutex};
use tauri::{AppHandle, Manager};
pub use crate::window_presentation::WindowMode;

#[derive(Clone, Copy, Debug, Default, Deserialize, PartialEq, Serialize)]
#[serde(default, rename_all = "camelCase")]
pub struct Preferences {
    pub window_mode: WindowMode,
    pub launch_at_login: Option<bool>,
}

pub struct ApplicationPreferences(Mutex<Preferences>);

fn file_path(app: &AppHandle) -> Result<std::path::PathBuf, String> {
    app.path().app_local_data_dir()
        .map(|folder| folder.join("application-settings.json"))
        .map_err(|_| "Uygulama ayarları klasörü açılamadı.".into())
}

fn read(path: &Path) -> Preferences {
    std::fs::read(path).ok()
        .filter(|data| data.len() <= 4096)
        .and_then(|data| serde_json::from_slice(&data).ok())
        .unwrap_or_default()
}

fn write(path: &Path, value: Preferences) -> std::io::Result<()> {
    let folder = path.parent().ok_or_else(|| std::io::Error::other("Ayar klasörü yok."))?;
    std::fs::create_dir_all(folder)?;
    let mut file = tempfile::NamedTempFile::new_in(folder)?;
    serde_json::to_writer(&mut file, &value)?;
    file.flush()?;
    file.persist(path)?;
    Ok(())
}

pub fn initialize(app: &AppHandle) {
    let value = file_path(app).map(|path| read(&path)).unwrap_or_default();
    app.manage(ApplicationPreferences(Mutex::new(value)));
}

pub fn get(app: &AppHandle) -> Preferences {
    app.state::<ApplicationPreferences>().0.lock().map(|value| *value).unwrap_or_default()
}

pub fn change_window_mode(app: &AppHandle, mode: WindowMode) -> Result<(), String> {
    let window = app.get_webview_window("main").ok_or("Ana pencere hazır değil.")?;
    let state = app.state::<ApplicationPreferences>();
    let mut prefs = state.0.lock().map_err(|_| "Uygulama ayarları meşgul.")?;
    let path = file_path(app)?;
    crate::window_presentation::apply_mode(&window, mode).map_err(|_| "Pencere modu değiştirilemedi.")?;
    let next = Preferences { window_mode: mode, ..*prefs };
    if write(&path, next).is_err() {
        let _ = crate::window_presentation::apply_mode(&window, prefs.window_mode);
        return Err("Pencere tercihi kaydedilemedi.".into());
    }
    *prefs = next;
    Ok(())
}

pub fn change_startup(app: &AppHandle, enabled: bool) -> Result<(), String> {
    let state = app.state::<ApplicationPreferences>();
    let mut prefs = state.0.lock().map_err(|_| "Uygulama ayarları meşgul.")?;
    let path = file_path(app)?;
    let previous = crate::startup_control::is_enabled(app)?;
    crate::startup_control::set_enabled(app, enabled)?;
    let next = Preferences { launch_at_login: Some(enabled), ..*prefs };
    if write(&path, next).is_err() {
        let _ = crate::startup_control::set_enabled(app, previous);
        return Err("Başlangıç tercihi kaydedilemedi.".into());
    }
    *prefs = next;
    Ok(())
}

pub fn update_notification_startup(app: &AppHandle, enabled: bool) -> Result<(), String> {
    let state = app.state::<ApplicationPreferences>();
    let prefs = state.0.lock().map_err(|_| "Uygulama ayarları meşgul.")?;
    if prefs.launch_at_login.is_none() {
        crate::startup_control::set_enabled(app, enabled)?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn preferences_persist_independently_and_update_atomically() {
        let folder = tempfile::tempdir().unwrap();
        let path = folder.path().join("application-settings.json");
        assert_eq!(read(&path), Preferences::default());
        let selected = Preferences { window_mode: WindowMode::Fullscreen, launch_at_login: Some(false) };
        write(&path, selected).unwrap();
        assert_eq!(read(&path), selected);
        let next = Preferences { window_mode: WindowMode::Windowed, ..read(&path) };
        write(&path, next).unwrap();
        assert_eq!(read(&path), next);
        assert_eq!(next.launch_at_login, Some(false));
    }
}
