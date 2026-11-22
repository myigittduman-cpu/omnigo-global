#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]
mod native_language;
mod application_name;
mod application_preferences;
mod shortcut_icons;
#[cfg(all(windows, feature = "microsoft-store"))]
mod store_shortcut;
mod website;
#[cfg(not(feature = "microsoft-store"))]
mod updates;
#[cfg(feature = "microsoft-store")]
#[path = "store_updates.rs"]
mod updates;
mod startup_control;
#[cfg(all(windows, feature = "microsoft-store"))]
mod webview_runtime;
mod startup;
mod startup_gate;
mod native_welcome;
mod startup_registration;
mod window_presentation;
mod application_icon;
mod report_files;
mod reports;
mod report_sharing;
mod chrome;
mod order_notifications;
mod notification_cursor;
mod notification_branding;
mod notification_sound;
mod background;
mod tray_menu;
mod close_policy;
mod device_storage;
mod remembered_login;
fn main() {
 let context=tauri::generate_context!();
 let admin=context.config().identifier=="com.omnigoglobal.admin";
 let startup_name=if admin {application_name::ADMIN} else {application_name::CLIENT};
 tauri::Builder::default()
  .plugin(tauri_plugin_single_instance::init(|app,args,_| {
    if !args.iter().skip(1).any(|argument| argument=="--background") { background::open(app); }
  }))
  .plugin(tauri_plugin_autostart::Builder::new().app_name(startup_name).args(["--background"]).build())
  .plugin(tauri_plugin_updater::Builder::new().build())
  .plugin(tauri_plugin_dialog::init())
  .invoke_handler(tauri::generate_handler![reports::save_report, report_sharing::share_report, chrome::desktop_action, remembered_login::remembered_login, order_notifications::notify_order, order_notifications::preview_order_sound,background::background_action])
  .on_window_event(|window,event|{
   if window.label()=="welcome"&&!native_welcome::finished(){
    if let tauri::WindowEvent::CloseRequested{..}=event{window.app_handle().exit(0);}
   }
   if close_policy::hide_on_close(window.label(),window.app_handle().tray_by_id("omnigo-notifications").is_some()){
    if let tauri::WindowEvent::CloseRequested{api,..}=event{api.prevent_close();let _=window.hide();}
   }
  })
  .on_page_load(|window, payload| {
    if window.label() == "welcome" {
      if payload.event() == tauri::webview::PageLoadEvent::Finished {
        native_welcome::loaded(window.app_handle().clone());
      }
      return;
    }
    if payload.event() == tauri::webview::PageLoadEvent::Started {
      if let Some(main) = window.app_handle().get_webview_window("main") {
        chrome::restore_caption(&main);
      }
    }
  })
  .setup(move |app| {
    let identifier = &app.config().identifier;
    #[cfg(not(feature = "microsoft-store"))]
    {
      startup_registration::migrate(if admin {"Omnigo Yonetim"} else {"Omnigo Musteri"},startup_name);
      startup_registration::migrate(if admin {"Omnigo Global Yönetici Paneli"} else {"Omnigo Global Müşteri Portalı"},startup_name);
    }
    startup_registration::migrate(if admin {"OG Yönetici Paneli"} else {"OG Müşteri Portalı"},startup_name);
    application_preferences::initialize(app.handle());
    let background_launch = startup_control::is_background_launch(identifier)
        && application_preferences::get(app.handle()).launch_at_login != Some(true);
    #[cfg(all(windows, feature = "microsoft-store"))]
    if identifier == "com.omnigoglobal.client" && !webview_runtime::ready(background_launch) {
      app.handle().exit(1);
      return Ok(());
    }
    // An OS registration restriction must not prevent opening the portal.
    if let Err(error) = notification_branding::register(app.handle()) { eprintln!("{error}"); }
    shortcut_icons::start(app.handle());
    native_language::initialize(app.handle());
    background::start(app, background_launch)?;
    startup::start(app)?;
    updates::start(app)?;
    Ok(())
  })
  .run(context)
  .expect("Omnigo uygulaması başlatılamadı");
}
use tauri::Manager;

#[cfg(test)]
mod capabilities_test {
    #[test]
    fn compiled_report_permission_is_scoped_to_own_origin_and_main_window() {
        let mut context: tauri::Context<tauri::Wry> = tauri::generate_context!();
        let admin = context.config().identifier == "com.omnigoglobal.admin";
        let (own, other) = if admin { ("yonetim", "portal") } else { ("portal", "yonetim") };
        let origin = |host: &str| tauri::ipc::Origin::Remote { url: format!("https://{host}.omnigoglobal.com/").parse().unwrap() };
        let authority = context.runtime_authority_mut();
        assert!(authority.resolve_access("save_report", "main", "main", &origin(own)).is_some());
        assert!(authority.resolve_access("save_report", "main", "main", &origin(other)).is_none());
        assert!(authority.resolve_access("save_report", "popup", "popup", &origin(own)).is_none());
        assert!(authority.resolve_access("save_report", "main", "main", &tauri::ipc::Origin::Local).is_none());
        assert!(authority.resolve_access("share_report", "main", "main", &origin(own)).is_some());
        assert!(authority.resolve_access("share_report", "main", "main", &origin(other)).is_none());
        assert!(authority.resolve_access("share_report", "popup", "popup", &origin(own)).is_none());
        assert!(authority.resolve_access("share_report", "main", "main", &tauri::ipc::Origin::Local).is_none());
        assert!(authority.resolve_access("desktop_action", "main", "main", &origin(own)).is_some());
        assert!(authority.resolve_access("desktop_action", "main", "main", &origin(other)).is_none());
        assert!(authority.resolve_access("desktop_action", "popup", "popup", &origin(own)).is_none());
        assert!(authority.resolve_access("desktop_action", "main", "main", &tauri::ipc::Origin::Local).is_none());
        assert!(authority.resolve_access("remembered_login", "main", "main", &origin(own)).is_some());
        assert!(authority.resolve_access("remembered_login", "main", "main", &origin(other)).is_none());
        assert!(authority.resolve_access("remembered_login", "popup", "popup", &origin(own)).is_none());
        assert_eq!(authority.resolve_access("notify_order", "main", "main", &origin(own)).is_some(), admin);
        assert!(authority.resolve_access("notify_order", "main", "main", &origin(other)).is_none());
        assert!(authority.resolve_access("notify_order", "popup", "popup", &origin(own)).is_none());
        assert_eq!(authority.resolve_access("preview_order_sound", "main", "main", &origin(own)).is_some(), admin);
        assert!(authority.resolve_access("preview_order_sound", "main", "main", &origin(other)).is_none());
        assert_eq!(authority.resolve_access("background_action","main","main",&origin(own)).is_some(),true);
        assert!(authority.resolve_access("background_action","main","main",&origin(other)).is_none());
        assert!(authority.resolve_access("background_action","popup","popup",&origin(own)).is_none());
    }
}
