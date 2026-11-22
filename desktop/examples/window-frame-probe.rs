#![windows_subsystem = "windows"]
#[path = "../src/window_presentation.rs"]
mod window_presentation;
use std::{sync::atomic::{AtomicBool, Ordering}, time::Duration};
use tauri::{Manager, WebviewUrl, WebviewWindow, WebviewWindowBuilder};
use windows::Win32::{Foundation::{LPARAM, POINT, RECT, WPARAM}, Graphics::Gdi::{ClientToScreen, MonitorFromWindow, GetMonitorInfoW, MONITORINFO, MONITOR_DEFAULTTONEAREST}, UI::WindowsAndMessaging::{GetClientRect, GetWindowRect, GetWindowPlacement, IsZoomed, SendMessageW, WINDOWPLACEMENT, WM_NCHITTEST}};
static READY: AtomicBool = AtomicBool::new(false);

fn record(window: &WebviewWindow, label: &str) {
    let hwnd = window.hwnd().unwrap();
    let mut outer = RECT::default(); let mut client = RECT::default(); let mut origin = POINT::default();
    let mut placement = WINDOWPLACEMENT { length: std::mem::size_of::<WINDOWPLACEMENT>() as u32, ..Default::default() };
    let mut monitor = MONITORINFO { cbSize: std::mem::size_of::<MONITORINFO>() as u32, ..Default::default() };
    unsafe {
        GetWindowRect(hwnd, &mut outer).unwrap(); GetClientRect(hwnd, &mut client).unwrap();
        let _ = ClientToScreen(hwnd, &mut origin); GetWindowPlacement(hwnd, &mut placement).unwrap();
        let _ = GetMonitorInfoW(MonitorFromWindow(hwnd, MONITOR_DEFAULTTONEAREST), &mut monitor);
    }
    let work = monitor.rcWork;
    let points = [(work.right-1,work.top),(work.right-1,work.top+1),(work.right-2,work.top+2),(work.right-10,work.top+10),(work.right-25,work.top+25)];
    let hits: Vec<_> = points.iter().map(|&(x,y)| {
        let packed = (((y as u16 as u32)<<16)|(x as u16 as u32)) as isize;
        serde_json::json!({"point":[x,y],"hit":unsafe{SendMessageW(hwnd,WM_NCHITTEST,Some(WPARAM(0)),Some(LPARAM(packed))).0}})
    }).collect();
    let report=serde_json::json!({"label":label,"maximized":unsafe{IsZoomed(hwnd).as_bool()},"runtimeMaximized":window.is_maximized().unwrap(),"placement":placement.showCmd,"work":[work.left,work.top,work.right,work.bottom],"outer":[outer.left,outer.top,outer.right,outer.bottom],"client":[origin.x,origin.y,origin.x+client.right,origin.y+client.bottom],"restore":[placement.rcNormalPosition.left,placement.rcNormalPosition.top,placement.rcNormalPosition.right,placement.rcNormalPosition.bottom],"hits":hits});
    let folder=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../verification/desktop-0217");
    std::fs::create_dir_all(&folder).unwrap();
    std::fs::write(folder.join(format!("{label}.json")),serde_json::to_vec_pretty(&report).unwrap()).unwrap();
}

fn main() {
    tauri::Builder::default().on_page_load(|window,payload| {
        if payload.event()!=tauri::webview::PageLoadEvent::Finished || READY.swap(true,Ordering::SeqCst) {return;}
        let app=window.app_handle().clone(); let handle=app.clone();
        app.run_on_main_thread(move||{
            let window=handle.get_webview_window("main").unwrap();
            window.set_decorations(false).unwrap();
            window_presentation::reveal_prepared(&window).unwrap();
            std::thread::spawn(move||{
                std::thread::sleep(Duration::from_millis(400));
                let copy=window.clone();
                window.run_on_main_thread(move||record(&copy,"first-open")).unwrap();
            });
        }).unwrap();
    }).setup(|app| {
        WebviewWindowBuilder::new(app,"main",WebviewUrl::App("index.html".into()))
            .title("Omnigo Pencere Testi — Örnek ekran")
            .inner_size(1280.0,860.0).visible(false).maximized(false)
            .initialization_script(r#"document.addEventListener('DOMContentLoaded',()=>{document.body.innerHTML='<div style="position:fixed;inset:0 0 auto;height:56px;background:#192537;color:white;display:flex;align-items:center;justify-content:space-between;padding-left:24px;font:16px Segoe UI">Omnigo · İlk açılış pencere testi <button onclick="location.href=\'https://omnigo-window-test.invalid/close\'" style="height:56px;width:56px;border:0;background:#b52d3e;color:white;font-size:24px" aria-label="Kapat">×</button></div><p style="padding:80px 24px;color:white;font:20px Segoe UI">Yalnız pencere çerçevesi kontrol ediliyor. Gerçek hesap ve sipariş kullanılmıyor.</p>';document.body.style.background='#111b2a';document.body.style.margin='0';});"#)
            .on_navigation({let app=app.handle().clone();move|url|{
                if url.host_str()==Some("omnigo-window-test.invalid") {
                    record(&app.get_webview_window("main").unwrap(),"corner-click");app.exit(0);return false;
                } true
            }}).build()?;
        Ok(())
    }).run(tauri::generate_context!()).expect("Pencere testi başlatılamadı");
}
