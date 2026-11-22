use std::{sync::atomic::{AtomicBool, Ordering}, time::Duration};
use tauri::{App, AppHandle, Manager};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tauri_plugin_updater::UpdaterExt;
use crate::native_language::{t,format};

static CHECKING: AtomicBool = AtomicBool::new(false);

pub fn start(app: &mut App) -> tauri::Result<()> {
    let handle = app.handle().clone();
    std::thread::spawn(move || {
        std::thread::sleep(Duration::from_secs(10));
        loop {
            run_check(&handle, false);
            std::thread::sleep(Duration::from_secs(6 * 60 * 60));
        }
    });
    Ok(())
}

pub(crate) fn run_check(app: &AppHandle, manual: bool) {
    if !manual && app.get_webview_window("main").is_some_and(|w| !w.is_visible().unwrap_or(false)) {return;}
    if CHECKING.compare_exchange(false, true, Ordering::AcqRel, Ordering::Relaxed).is_err() {
        if manual { app.dialog().message(t("Güncelleme kontrolü zaten devam ediyor.")).title(t("Omnigo güncelleme")).blocking_show(); }
        return;
    }
    let result = tauri::async_runtime::block_on(check(app, manual));
    CHECKING.store(false, Ordering::Release);
    if result.is_err() && manual {
        app.dialog().message(t("Güncelleme kontrolü tamamlanamadı. İnternet bağlantınızı kontrol edip yeniden deneyin. Mevcut sürümü kullanmaya devam edebilirsiniz.")).title(t("Omnigo güncelleme")).blocking_show();
    }
}

async fn check(app: &AppHandle, manual: bool) -> tauri_plugin_updater::Result<()> {
    let Some(update) = app.updater_builder().timeout(Duration::from_secs(30)).build()?.check().await? else {
        if manual {
            app.dialog().message(t("Uygulamanız güncel.")).title(t("Omnigo güncelleme")).blocking_show();
        }
        return Ok(());
    };
    let accepted = app.dialog()
        .message(format("Yeni sürüm ({0}) hazır. Kaydedilmemiş işlemlerinizi tamamlayın. Güncelleme internetten indirilip kurulacak ve uygulama yeniden açılacak.", &[&update.version]))
        .title(t("Omnigo güncelleme hazır"))
        .buttons(MessageDialogButtons::OkCancelCustom(t("Güncelle ve yeniden başlat"), t("Daha sonra")))
        .blocking_show();
    if !accepted { return Ok(()); }
    let window = app.get_webview_window("main");
    let original_title = app.config().app.windows.iter().find(|w| w.label == "main")
        .map(|w| w.title.clone()).unwrap_or_else(|| "Omnigo Global".into());
    if let Some(ref w) = window { let _ = w.set_title(&t("Omnigo — Güncelleme indiriliyor…")); }
    let result = update.download_and_install(|_, _| {}, || {}).await;
    if let Some(ref w) = window { let _ = w.set_title(&original_title); }
    if result.is_err() {
        app.dialog().message(t("Güncelleme indirilemedi veya güvenlik doğrulamasından geçemedi. Mevcut sürümünüz korunuyor. Daha sonra tekrar deneyebilirsiniz.")).title(t("Güncelleme tamamlanamadı")).blocking_show();
        return Ok(());
    }
    app.restart();
}
