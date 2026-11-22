use std::{fs, path::Path};
use base64::{engine::general_purpose::STANDARD, Engine};
use minisign_verify::{PublicKey, Signature};

fn main() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR"));
    let config: serde_json::Value = serde_json::from_slice(&fs::read(root.join("tauri.conf.json")).unwrap()).unwrap();
    let version = config["version"].as_str().unwrap();
    let mut releases = Vec::new();
    for (role, filename, host) in [("client", "tauri.conf.json", "portal"), ("admin", "admin.conf.json", "yonetim")] {
        let settings: serde_json::Value = serde_json::from_slice(&fs::read(root.join(filename)).unwrap()).unwrap();
        let folder = root.join(format!("../deploy/desktop-{version}/public/updates/{role}"));
        let manifest: serde_json::Value = serde_json::from_slice(&fs::read(folder.join("latest.json")).unwrap()).unwrap();
        assert_eq!(manifest["version"], version);
        let entry = &manifest["platforms"]["windows-x86_64"];
        assert_eq!(entry["url"], format!("https://{host}.omnigoglobal.com/updates/{role}/{role}-{version}-x64-setup.exe"));
        let key = PublicKey::decode(&String::from_utf8(STANDARD.decode(settings["plugins"]["updater"]["pubkey"].as_str().unwrap()).unwrap()).unwrap()).unwrap();
        let signature = Signature::decode(&String::from_utf8(STANDARD.decode(entry["signature"].as_str().unwrap()).unwrap()).unwrap()).unwrap();
        let bytes = fs::read(folder.join(format!("{role}-{version}-x64-setup.exe"))).unwrap();
        assert_eq!(&bytes[..2], b"MZ");
        key.verify(&bytes, &signature, true).expect("Paket imzası doğrulanamadı");
        let display_name=if role=="admin"{"OG Yönetici Portalı"}else{"OG Müşteri Portalı"};
        let setup_path=root.join(format!("../../outputs/Omnigo-PC-Kurulum/{display_name}.exe"));
        let setup=fs::read(&setup_path).unwrap();
        let setup_signature=Signature::decode(&String::from_utf8(STANDARD.decode(fs::read_to_string(setup_path.with_extension("exe.sig")).unwrap().trim()).unwrap()).unwrap()).unwrap();
        key.verify(&setup,&setup_signature,true).expect("Modern kurulum bütünlük imzası doğrulanamadı");
        let mut changed_setup=setup.clone();changed_setup[100]^=1;
        assert!(key.verify(&changed_setup,&setup_signature,true).is_err());
        let mut altered = bytes.clone();
        altered[100] ^= 1;
        assert!(key.verify(&altered, &signature, true).is_err());
        releases.push((key, bytes, signature));
        println!("{role}: sürüm, indirme adresi, NSIS ve modern setup imzaları, değiştirilmiş paket reddi doğrulandı.");
    }
    assert!(releases[0].0.verify(&releases[1].1, &releases[1].2, true).is_err());
    assert!(releases[1].0.verify(&releases[0].1, &releases[0].2, true).is_err());
    println!("İki güncelleme kanalı birbirinden ayrı ve doğrulandı.");
}
