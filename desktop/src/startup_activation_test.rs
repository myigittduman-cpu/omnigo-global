use super::*;
use std::{process::Command, time::Instant};
use tauri::{RunEvent, WebviewUrl};
use windows::Win32::Graphics::Dwm::{DwmGetWindowAttribute, DWMWA_CLOAKED};

fn uncloaked(window: &tauri::WebviewWindow) -> bool {
    let mut state = 0u32;
    unsafe {
        DwmGetWindowAttribute(window.hwnd().unwrap(), DWMWA_CLOAKED, (&mut state as *mut u32).cast(), 4).is_ok()
            && state == 0
    }
}

fn wait_for(mut condition: impl FnMut() -> bool) -> bool {
    let deadline = Instant::now() + Duration::from_secs(12);
    while Instant::now() < deadline {
        if condition() { return true; }
        std::thread::sleep(Duration::from_millis(30));
    }
    false
}

#[test]
#[ignore = "Windows masaüstünde WebView2 ile çalıştırılır"]
fn existing_background_window_reopens() {
    let secondary = std::env::var("OMNIGO_ACTIVATION_TEST_ID").ok();
    let namespace = secondary.clone().unwrap_or_else(|| format!("com.omnigoglobal.activation-test-{}", std::process::id()));
    let data = tempfile::tempdir().unwrap();
    let data_path = data.path().to_path_buf();
    let mut context = tauri::generate_context!();
    context.config_mut().identifier = namespace.clone();
    let is_secondary = secondary.is_some();
    let app = tauri::Builder::default().any_thread()
        .plugin(tauri_plugin_single_instance::init(|app, _, _| crate::background::open(app)))
        .setup(move |app| {
            if is_secondary { return Err(std::io::Error::other("İkinci pencere oluşturuldu.").into()); }
            crate::background::begin_test_silent_start();
            let window = WebviewWindowBuilder::new(app, "main", WebviewUrl::App("welcome.html".into()))
                .title("Omnigo pencere doğrulaması")
                .visible(false).inner_size(420.0, 320.0).data_directory(data_path).build()?;
            crate::window_presentation::prepare_hidden(&window, crate::window_presentation::WindowMode::Windowed, true)?;
            WINDOW_PREPARED.store(true, Ordering::Release);
            Ok(())
        })
        .build(context).unwrap();
    let outcome = app.run_return(move |app, event| {
        if !matches!(event, RunEvent::Ready) { return; }
        let handle = app.clone();
        let namespace = namespace.clone();
        std::thread::spawn(move || {
            let run = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
                let window = handle.get_webview_window("main").unwrap();
                let size = window.inner_size().unwrap();
                assert!(wait_for(|| !window.is_visible().unwrap() && !uncloaked(&window)));
                let status = Command::new(std::env::current_exe().unwrap())
                    .args(["--exact", "startup::activation_test::existing_background_window_reopens", "--ignored"])
                    .env("OMNIGO_ACTIVATION_TEST_ID", namespace)
                    .status().unwrap();
                assert!(status.success(), "Kısayol isteği mevcut sürece ulaşmadı.");
                assert!(wait_for(|| window.is_visible().unwrap() && uncloaked(&window) && !pending()));
                window.hide().unwrap();
                assert!(wait_for(|| !window.is_visible().unwrap()));
                crate::background::open(&handle);
                assert!(wait_for(|| window.is_visible().unwrap() && !window.is_minimized().unwrap()));
                assert_eq!(window.inner_size().unwrap(), size);
            }));
            handle.exit(if run.is_ok() { 0 } else { 1 });
        });
    });
    assert_eq!(outcome, 0);
}
