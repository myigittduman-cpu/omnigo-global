use tauri::AppHandle;

#[cfg(all(windows, feature = "microsoft-store"))]
const STARTUP_TASK_ID: &str = "OmnigoBackground";

pub fn is_background_launch(_identifier: &str) -> bool {
    if std::env::args_os().skip(1).any(|arg| arg == "--background") {
        return true;
    }

    #[cfg(all(windows, feature = "microsoft-store"))]
    if _identifier == "com.omnigoglobal.client" {
        return is_store_startup_task();
    }

    false
}

#[cfg(all(windows, feature = "microsoft-store"))]
fn is_store_startup_task() -> bool {
    use windows::{
        core::Interface,
        ApplicationModel::{Activation::IStartupTaskActivatedEventArgs, AppInstance},
        Win32::{
            Foundation::RPC_E_CHANGED_MODE,
            System::WinRT::{RoInitialize, RoUninitialize, RO_INIT_SINGLETHREADED},
        },
    };

    // Read the activation once. This runs before the first window initializes COM.
    let initialized = unsafe { RoInitialize(RO_INIT_SINGLETHREADED) };
    if initialized.as_ref().is_err_and(|error| error.code() != RPC_E_CHANGED_MODE) {
        return false;
    }
    let launched = AppInstance::GetActivatedEventArgs()
        .and_then(|args| args.cast::<IStartupTaskActivatedEventArgs>())
        .and_then(|args| args.TaskId())
        .is_ok_and(|id| id == STARTUP_TASK_ID);
    if initialized.is_ok() {
        unsafe { RoUninitialize() };
    }
    launched
}

#[cfg(not(all(windows, feature = "microsoft-store")))]
pub fn is_enabled(app: &AppHandle) -> Result<bool, String> {
    use tauri_plugin_autostart::ManagerExt;
    app.autolaunch().is_enabled().map_err(|_| "Windows başlangıç ayarı okunamadı.".into())
}

#[cfg(all(windows, feature = "microsoft-store"))]
pub fn is_enabled(_app: &AppHandle) -> Result<bool, String> {
    use windows::{ApplicationModel::{StartupTask, StartupTaskState}, core::HSTRING};
    let state = StartupTask::GetAsync(&HSTRING::from(STARTUP_TASK_ID))
        .and_then(|operation| operation.join()).and_then(|task| task.State())
        .map_err(|_| "Windows başlangıç ayarı okunamadı.")?;
    Ok(matches!(state, StartupTaskState::Enabled | StartupTaskState::EnabledByPolicy))
}

#[cfg(not(all(windows, feature = "microsoft-store")))]
pub fn set_enabled(app: &AppHandle, enabled: bool) -> Result<(), String> {
    use tauri_plugin_autostart::ManagerExt;
    if enabled {
        app.autolaunch().enable().map_err(|_| "Windows başlangıç ayarı kaydedilemedi.".into())
    } else {
        app.autolaunch().disable().map_err(|_| "Başlangıç ayarı kapatılamadı.".into())
    }
}

#[cfg(all(windows, feature = "microsoft-store"))]
pub fn set_enabled(_app: &AppHandle, enabled: bool) -> Result<(), String> {
    use windows::{ApplicationModel::{StartupTask, StartupTaskState}, core::HSTRING};
    let task = StartupTask::GetAsync(&HSTRING::from(STARTUP_TASK_ID))
        .and_then(|operation| operation.join())
        .map_err(|_| "Windows başlangıç ayarı okunamadı.")?;
    if !enabled { return task.Disable().map_err(|_| "Başlangıç ayarı kapatılamadı.".into()); }
    let state = task.RequestEnableAsync().and_then(|operation| operation.join())
        .map_err(|_| "Windows başlangıç ayarı kaydedilemedi.")?;
    if state == StartupTaskState::Enabled || state == StartupTaskState::EnabledByPolicy {
        Ok(())
    } else {
        Err("Windows başlangıç izni kapalı. Ayarlar > Uygulamalar > Başlangıç bölümünden bu uygulamayı etkinleştirin.".into())
    }
}
