use tauri::{AppHandle, Manager};

#[cfg(windows)]
pub fn allowed(app: &AppHandle) -> bool {
    use windows::{core::HSTRING, UI::Notifications::{ToastNotificationManager, NotificationSetting}, Win32::UI::Shell::{SHQueryUserNotificationState, QUNS_ACCEPTS_NOTIFICATIONS}};
    let Ok(identifier)=crate::notification_branding::notification_id(app) else {return false};
    let enabled=ToastNotificationManager::CreateToastNotifierWithId(&HSTRING::from(&identifier))
        .and_then(|notifier|notifier.Setting()).is_ok_and(|setting|setting==NotificationSetting::Enabled);
    enabled && unsafe { SHQueryUserNotificationState() }.is_ok_and(|state|state==QUNS_ACCEPTS_NOTIFICATIONS)
}

#[cfg(windows)]
pub fn play(app: &AppHandle) -> Result<(),String> {
    use std::os::windows::ffi::OsStrExt;
    use windows::{core::PCWSTR, Win32::Media::Audio::{PlaySoundW,SND_ASYNC,SND_FILENAME,SND_NODEFAULT,SND_SYSTEM}};
    let path=app.path().resolve("notification-chime.wav",tauri::path::BaseDirectory::Resource).map_err(|_|"Bildirim sesi bulunamadı.")?;
    if !path.is_file(){return Err("Bildirim sesi bulunamadı.".into());}
    let wide:Vec<u16>=path.as_os_str().encode_wide().chain(Some(0)).collect();
    if unsafe {PlaySoundW(PCWSTR(wide.as_ptr()),None,SND_FILENAME|SND_ASYNC|SND_NODEFAULT|SND_SYSTEM)}.as_bool(){Ok(())}
    else {Err("Bildirim sesi çalınamadı. Cihazınızın ses ayarlarını kontrol edin.".into())}
}
