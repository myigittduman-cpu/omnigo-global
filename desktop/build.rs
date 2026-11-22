fn main() {
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("windows") {
        cc::Build::new().cpp(true).flag_if_supported("/std:c++17").flag_if_supported("/EHsc")
            .file("src/whatsapp_target.cpp").compile("omnigo_whatsapp_target");
        println!("cargo:rustc-link-lib=windowsapp");
        println!("cargo:rerun-if-changed=src/whatsapp_target.cpp");
    }
    println!("cargo:rerun-if-changed=../public/assets/omnigo-logo.png");
    let logo=include_bytes!("../public/assets/omnigo-logo.png");
    if std::fs::read("shell/welcome-logo.png").ok().as_deref()!=Some(logo.as_slice()) {
        std::fs::write("shell/welcome-logo.png",logo).expect("Karşılama logosu hazırlanamadı");
    }
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&["save_report", "share_report", "desktop_action", "remembered_login", "notify_order", "preview_order_sound", "background_action"]),
    )).expect("Masaüstü izinleri hazırlanamadı");
}
