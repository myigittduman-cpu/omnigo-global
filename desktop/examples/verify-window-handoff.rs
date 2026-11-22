#![windows_subsystem="windows"]
use std::{sync::{atomic::{AtomicBool,Ordering},Mutex},time::Instant};
use tauri::{AppHandle,Manager,WebviewUrl,WebviewWindowBuilder};
use serde_json::{json,Value};
#[path="../src/window_presentation.rs"]mod window_presentation;
#[path="../src/startup_gate.rs"]mod startup_gate;
#[path="../src/native_welcome.rs"]mod native_welcome;
mod background {pub fn silent()->bool{false}}
static READY:AtomicBool=AtomicBool::new(false);
static DUE:AtomicBool=AtomicBool::new(false);
static SHOWN:AtomicBool=AtomicBool::new(false);
static FIXTURE_LOADED:AtomicBool=AtomicBool::new(false);
struct Evidence{began:Instant,samples:Mutex<Vec<Value>>,role:String}
mod startup {
 use super::*;
 pub fn reveal_after_welcome(app:AppHandle){
  DUE.store(true,Ordering::Release);
  if !READY.load(Ordering::Acquire)||SHOWN.swap(true,Ordering::AcqRel){return;}
  let handle=app.clone();let _=app.run_on_main_thread(move||{
   let window=handle.get_webview_window("main").unwrap();
   window_presentation::reveal_prepared(&window).unwrap();native_welcome::dismiss(&handle);
   window.eval("layoutSample('visible0');setTimeout(()=>layoutSample('visible1'),40);setTimeout(()=>layoutSample('visible2'),120);setTimeout(()=>layoutSample('visible3'),350);setTimeout(()=>layoutSample('visible4'),1200);").unwrap();
  });
 }
}
fn sample(app:&AppHandle,url:&tauri::Url){
 use windows::Win32::Graphics::Dwm::{DwmGetWindowAttribute,DWMWA_CLOAKED};
 let params:std::collections::HashMap<_,_>=url.query_pairs().into_owned().collect();
 let number=|name:&str|params.get(name).and_then(|v|v.parse::<f64>().ok()).unwrap_or(0.0);
 let viewport=startup_gate::Viewport{width:number("width"),height:number("height"),scale:number("scale")};
 let window=app.get_webview_window("main").unwrap();let inner=window.inner_size().unwrap();let outer=window.outer_size().unwrap();
 let phase=params.get("phase").cloned().unwrap_or_default();let evidence=app.state::<Evidence>();let mut cloaked=0u32;
 unsafe{DwmGetWindowAttribute(window.hwnd().unwrap(),DWMWA_CLOAKED,&mut cloaked as *mut u32 as _,std::mem::size_of::<u32>() as u32).unwrap();}
 let matches=startup_gate::viewport_matches(&viewport,inner.width,inner.height);
 evidence.samples.lock().unwrap().push(json!({"phase":phase,"elapsedMs":evidence.began.elapsed().as_millis(),"cssWidth":viewport.width,"cssHeight":viewport.height,"scale":viewport.scale,"innerWidth":inner.width,"innerHeight":inner.height,"outerWidth":outer.width,"outerHeight":outer.height,"cloaked":cloaked!=0,"maximized":window.is_maximized().unwrap(),"viewportMatches":matches,"resizeEvents":number("resizes")}));
 if phase=="settled" {
  if !matches{app.exit(3);return;}
  READY.store(true,Ordering::Release);if DUE.load(Ordering::Acquire){startup::reveal_after_welcome(app.clone());}
 }
 if phase=="visible4"{
  let samples=evidence.samples.lock().unwrap();let before=&samples[0];
  let stable=samples.len()==6&&before["cloaked"]==true&&samples.iter().all(|row|row["viewportMatches"]==true&&row["maximized"]==true&&row["innerWidth"]==before["innerWidth"]&&row["innerHeight"]==before["innerHeight"]&&row["outerWidth"]==before["outerWidth"]&&row["outerHeight"]==before["outerHeight"])&&samples.iter().skip(1).all(|row|row["cloaked"]==false);
  let output=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join(format!("../verification/window-handoff-20261101/geometry-{}.json",evidence.role));
  std::fs::write(output,serde_json::to_vec_pretty(&json!({"role":evidence.role,"stableGeometry":stable,"samples":*samples,"productionDataAccessed":false})).unwrap()).unwrap();
  app.exit(if stable{0}else{2});
 }
}
fn main(){
 let role=std::env::args().nth(1).unwrap_or_else(||"client".into());assert!(matches!(role.as_str(),"admin"|"client"));
 let mut context=tauri::generate_context!();context.config_mut().identifier=format!("com.omnigoglobal.presentation-probe-{role}");
 context.config_mut().product_name=Some("Omnigo Açılış Geçiş Kontrolü".into());
 tauri::Builder::default().setup(move|app|{
  app.manage(Evidence{began:Instant::now(),samples:Mutex::new(Vec::new()),role:role.clone()});
  let handle=app.handle().clone();let probe=handle.clone();
  let window=WebviewWindowBuilder::new(app,"main",WebviewUrl::App("index.html".into())).title("Omnigo Açılış Geçiş Kontrolü").inner_size(1280.0,860.0).visible(false)
   .on_navigation(move|url|{if url.scheme()=="layout-probe"{let url=url.clone();let target=probe.clone();let _=probe.run_on_main_thread(move||sample(&target,&url));false}else{url.scheme()=="tauri"||url.host_str()==Some("tauri.localhost")}})
   .on_page_load(|window,payload|{if payload.event()==tauri::webview::PageLoadEvent::Finished&&(payload.url().scheme()=="tauri"||payload.url().host_str()==Some("tauri.localhost"))&&!FIXTURE_LOADED.swap(true,Ordering::AcqRel){window.set_decorations(false).unwrap();window_presentation::settle_chrome(&window).unwrap();window.eval(include_str!("../../verification/window-handoff-20261101/fixture.js")).unwrap();}}).build()?;
  window_presentation::prepare_hidden(&window,window_presentation::WindowMode::Maximized,false)?;native_welcome::start(app)?;
  let timeout=handle.clone();std::thread::spawn(move||{std::thread::sleep(std::time::Duration::from_secs(15));timeout.exit(4);});
  Ok(())
 }).on_page_load(|window,payload|{if window.label()=="welcome"&&payload.event()==tauri::webview::PageLoadEvent::Finished{native_welcome::loaded(window.app_handle().clone());}})
 .run(context).expect("Pencere geçiş testi başlatılamadı");
}
