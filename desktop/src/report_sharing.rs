use tauri::{AppHandle, WebviewWindow};

#[tauri::command]
pub async fn share_report(app: AppHandle, window: WebviewWindow, filename: String, contents: String) -> Result<&'static str, String> {
    let url = window.url().map_err(|_| "Paylaşım kaynağı doğrulanamadı.")?;
    if window.label() != "main" || !crate::reports::allowed_origin(&url, &app.config().identifier) {
        return Err("Bu sayfadan rapor paylaşmaya izin verilmiyor.".into());
    }
    let (bytes, _) = crate::report_files::decode_report(&filename, &contents)?;
    #[cfg(windows)]
    {
        let name = filename.clone();
        let owner = window.hwnd().map_err(|_| "Pencere bulunamadı.")?.0 as usize;
        let prepared = tauri::async_runtime::spawn_blocking(move || {
            let report = native::prepare(&name, &bytes)?;
            native::direct_or_picker(report, &name, owner)
        })
            .await.map_err(|_| "Paylaşılacak dosya hazırlanamadı.".to_string())??;
        let Some(prepared) = prepared else { return Ok("opened"); };
        let (sender, receiver) = std::sync::mpsc::sync_channel(1);
        let target = window.clone();
        window.run_on_main_thread(move || { let _ = sender.send(native::show(&target, filename, prepared)); })
            .map_err(|_| "Windows paylaşım ekranı açılamadı.".to_string())?;
        tauri::async_runtime::spawn_blocking(move || receiver.recv().map_err(|_| "Paylaşım ekranı kapandı.".to_string()))
            .await.map_err(|_| "Paylaşım ekranı açılamadı.".to_string())???;
        Ok("opened")
    }
    #[cfg(not(windows))]
    { let _ = (filename, bytes); Err("Bu platformda sistem paylaşımı henüz desteklenmiyor.".into()) }
}

#[cfg(windows)]
mod native {
    use std::{cell::RefCell, sync::{Arc, atomic::{AtomicBool, Ordering}}};
    use windows::{core::{factory, AgileReference, HSTRING, Interface}, ApplicationModel::DataTransfer::{DataTransferManager, DataPackageOperation, DataRequestedEventArgs}, Foundation::TypedEventHandler, Storage::{StorageFile, IStorageItem}, Win32::{System::WinRT::{RoInitialize, RoUninitialize, RO_INIT_MULTITHREADED}, UI::Shell::IDataTransferManagerInterop}};
    use windows_collections::IIterable;

    pub struct Prepared { folder: tempfile::TempDir, file: AgileReference<StorageFile> }
    pub fn direct_or_picker(prepared: Prepared, name: &str, owner: usize) -> Result<Option<Prepared>, String> {
        use std::{os::windows::ffi::OsStrExt, sync::Mutex, time::{Duration, Instant}};
        static ATTACHMENTS: Mutex<Vec<(Instant, tempfile::TempDir)>> = Mutex::new(Vec::new());
        extern "C" { fn omnigo_share_whatsapp(path: *const u16, owner: usize) -> i32; }
        if !name.ends_with(".pdf") { return Ok(Some(prepared)); }
        let path: Vec<u16> = prepared.folder.path().join(name).as_os_str().encode_wide().chain(Some(0)).collect();
        match unsafe { omnigo_share_whatsapp(path.as_ptr(), owner) } {
            0 => Ok(Some(prepared)),
            1 => {
                let mut files = ATTACHMENTS.lock().unwrap_or_else(|error| error.into_inner());
                files.retain(|(at, _)| at.elapsed() < Duration::from_secs(3600));
                files.push((Instant::now(), prepared.folder));
                Ok(None)
            }
            _ => Err("WhatsApp PDF ekini açamadı. Paylaşımı yeniden deneyin.".into()),
        }
    }
    struct Session { manager: DataTransferManager, token: i64, completed: Arc<AtomicBool>, _folder: tempfile::TempDir }
    impl Drop for Session { fn drop(&mut self) { let _ = self.manager.RemoveDataRequested(self.token); } }
    thread_local! { static SESSIONS: RefCell<Vec<Session>> = const { RefCell::new(Vec::new()) }; }
    struct Runtime;
    impl Drop for Runtime { fn drop(&mut self) { unsafe { RoUninitialize(); } } }

    pub fn prepare(name: &str, bytes: &[u8]) -> Result<Prepared, String> {
        unsafe { RoInitialize(RO_INIT_MULTITHREADED) }.map_err(|_| "Windows paylaşım bağlantısı hazırlanamadı.")?;
        let _runtime = Runtime;
        let folder = tempfile::Builder::new().prefix("Omnigo-paylasim-").tempdir().map_err(|_| "Geçici belge klasörü açılamadı.")?;
        let path = folder.path().join(name);
        std::fs::write(&path, bytes).map_err(|_| "Paylaşılacak belge yazılamadı.")?;
        let file = StorageFile::GetFileFromPathAsync(&HSTRING::from(path.to_string_lossy().as_ref()))
            .and_then(|operation| operation.join()).map_err(|_| "Windows paylaşılacak dosyayı açamadı.")?;
        let file = AgileReference::new(&file).map_err(|_| "Paylaşılacak dosya bağlantısı hazırlanamadı.")?;
        Ok(Prepared { folder, file })
    }

    pub fn show(window: &tauri::WebviewWindow, filename: String, prepared: Prepared) -> Result<(), String> {
        SESSIONS.with(|sessions| {
            let mut sessions = sessions.borrow_mut();
            sessions.retain(|session| !session.completed.load(Ordering::Acquire));
            if sessions.len() >= 8 { return Err("Önce açık paylaşım işlemlerini tamamlayın veya uygulamayı yeniden açın.".into()); }
            let result = (|| -> windows::core::Result<Session> {
                let hwnd = windows::Win32::Foundation::HWND(window.hwnd().map_err(|_| windows::core::Error::from_hresult(windows::Win32::Foundation::E_FAIL))?.0);
                let interop: IDataTransferManagerInterop = factory::<DataTransferManager, IDataTransferManagerInterop>()?;
                let manager: DataTransferManager = unsafe { interop.GetForWindow(hwnd)? };
                let completed = Arc::new(AtomicBool::new(false));
                let done = completed.clone();let requested = AtomicBool::new(false);
                let token = manager.DataRequested(&TypedEventHandler::<DataTransferManager, DataRequestedEventArgs>::new(move |_, args| {
                    if requested.swap(true, Ordering::AcqRel) { return Ok(()); }
                    let args = args.as_ref().ok_or_else(|| windows::core::Error::from_hresult(windows::Win32::Foundation::E_POINTER))?;
                    let data = args.Request()?.Data()?;
                    data.Properties()?.SetTitle(&HSTRING::from(&filename))?;
                    data.SetRequestedOperation(DataPackageOperation::Copy)?;
                    let items = IIterable::<IStorageItem>::from(vec![Some(prepared.file.resolve()?.cast()?)]);
                    data.SetStorageItemsReadOnly(&items)?;
                    let complete = done.clone();
                    let _ = data.ShareCompleted(&TypedEventHandler::new(move |_, _| { complete.store(true, Ordering::Release); Ok(()) }));
                    let cancel = done.clone();
                    let _ = data.ShareCanceled(&TypedEventHandler::new(move |_, _| { cancel.store(true, Ordering::Release); Ok(()) }));
                    Ok(())
                }))?;
                let session = Session { manager, token, completed, _folder: prepared.folder };
                unsafe { interop.ShowShareUIForWindow(hwnd)?; }
                Ok(session)
            })();
            match result { Ok(session) => { sessions.push(session); Ok(()) }, Err(_) => Err("Windows paylaşım ekranı açılamadı. WhatsApp’ın kurulu ve güncel olduğunu kontrol edin.".into()) }
        })
    }
}
