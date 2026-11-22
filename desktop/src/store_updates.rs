// Only compiled for the Microsoft Store package. The NSIS update path is unchanged.
use tauri::{App, AppHandle};
use tauri_plugin_dialog::DialogExt;

pub fn start(_app: &mut App) -> tauri::Result<()> { Ok(()) }

pub(crate) fn run_check(app: &AppHandle, manual: bool) {
    if !manual { return; }
    let title = crate::native_language::t("Omnigo güncelleme");
    let message = match title.as_str() {
        "Omnigo update" => "Open Microsoft Store > Library to check for updates. This installation is updated through Microsoft Store.",
        "Omnigo-Update" => "Öffnen Sie Microsoft Store > Bibliothek, um nach Updates zu suchen. Diese Installation wird über Microsoft Store aktualisiert.",
        _ => "Güncellemeleri Microsoft Store > Kitaplık bölümünden kontrol edebilirsiniz. Bu kurulum Microsoft Store üzerinden güncellenir.",
    };
    app.dialog().message(message).title(title).blocking_show();
}
