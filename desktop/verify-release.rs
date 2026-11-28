use std::{fs, path::Path};
use base64::{Engine, engine::general_purpose::STANDARD};
use minisign_verify::{PublicKey, Signature};

fn main() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR"));
    let config: serde_json::Value = serde_json::from_slice(&fs::read(root.join("tauri.conf.json")).unwrap()).unwrap();
    let version = config["version"].as_str().unwrap();
    let release = root.join(format!("../deploy/desktop-{version}/public/updates"));
    let mut keys = Vec::new();
    let mut releases = Vec::new();
    for (channel, config_name) in [("client", "tauri.conf.json"), ("admin", "admin.conf.json")] {
        if std::env::args().any(|arg|arg=="--admin-only") && channel=="client" { continue; }
        if std::env::args().any(|arg|arg=="--client-only") && channel=="admin" { continue; }
        let settings: serde_json::Value = serde_json::from_slice(&fs::read(root.join(config_name)).unwrap()).unwrap();
        let key = String::from_utf8(STANDARD.decode(settings["plugins"]["updater"]["pubkey"].as_str().unwrap()).unwrap()).unwrap();
        let key = PublicKey::decode(&key).unwrap();
        let manifest: serde_json::Value = serde_json::from_slice(&fs::read(release.join(channel).join("latest.json")).unwrap()).unwrap();
        assert_eq!(manifest["version"], version);
        let entry = &manifest["platforms"]["windows-x86_64"];
        let url = entry["url"].as_str().unwrap();
        let host = if channel == "admin" { "yonetim" } else { "portal" };
        assert!(url.starts_with(&format!("https://{host}.omnigoglobal.com/updates/{channel}/")));
        let signature = String::from_utf8(STANDARD.decode(entry["signature"].as_str().unwrap()).unwrap()).unwrap();
        let signature = Signature::decode(&signature).unwrap();
        let data = fs::read(release.join(channel).join(url.rsplit('/').next().unwrap())).unwrap();
        key.verify(&data, &signature, true).expect("Paket imzası geçersiz");
        let mut damaged = data.clone();
        damaged[100] ^= 1;
        assert!(key.verify(&damaged, &signature, true).is_err(), "Bozuk paket reddedilmeli");
        assert_eq!(settings["app"]["windows"][0]["fullscreen"], false);
        assert_eq!(settings["app"]["windows"][0]["maximized"], true);
        assert_eq!(settings["app"]["windows"][0]["visible"], false);
        assert_eq!(settings["app"]["windows"][0]["create"], false);
        assert_eq!(settings["app"]["windows"][0]["backgroundColor"], "#111b2a");
        let capability = &settings["app"]["security"]["capabilities"][0];
        let permissions = if channel == "admin" { serde_json::json!(["allow-save-report", "allow-share-report", "allow-desktop-action", "allow-notify-order", "allow-preview-order-sound", "allow-background-action", "allow-remembered-login", "allow-persistent-session"]) } else { serde_json::json!(["allow-save-report", "allow-share-report", "allow-desktop-action", "allow-background-action", "allow-remembered-login", "allow-persistent-session"]) };
        assert_eq!(capability["permissions"], permissions);
        assert_eq!(capability["remote"]["urls"], serde_json::json!([format!("https://{host}.omnigoglobal.com/*")]));
        let title = if channel == "admin" { "OG Yönetici Portalı" } else { "OG İş Ortağım" };
        assert_eq!(settings["app"]["windows"][0]["title"], title);
        keys.push(key);
        releases.push((data, signature));
        println!("{channel}: imza, bozuk paket reddi, HTTPS adresi ve büyütülmüş pencere ayarları doğrulandı.");
    }
    if keys.len()==2 {
        assert!(keys[0].verify(&releases[1].0, &releases[1].1, true).is_err());
        assert!(keys[1].verify(&releases[0].0, &releases[0].1, true).is_err());
    }else{
        let opposite:serde_json::Value=serde_json::from_slice(&fs::read(root.join(if std::env::args().any(|arg|arg=="--client-only"){ "admin.conf.json" }else{ "tauri.conf.json" })).unwrap()).unwrap();
        let key=String::from_utf8(STANDARD.decode(opposite["plugins"]["updater"]["pubkey"].as_str().unwrap()).unwrap()).unwrap();
        assert!(PublicKey::decode(&key).unwrap().verify(&releases[0].0,&releases[0].1,true).is_err());
    }
    println!("Yönetici ve müşteri güncelleme kanalları birbirinin paketini reddediyor.");
}
