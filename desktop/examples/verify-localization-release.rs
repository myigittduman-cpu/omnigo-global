use std::{fs,path::Path};
use base64::{Engine,engine::general_purpose::STANDARD};
use minisign_verify::{PublicKey,Signature};
fn main(){
 let root=Path::new(env!("CARGO_MANIFEST_DIR"));let mut keys=Vec::new();let mut releases=Vec::new();
 for (role,name) in [("client","tauri.conf.json"),("admin","admin.conf.json")] {
  let config:serde_json::Value=serde_json::from_slice(&fs::read(root.join(name)).unwrap()).unwrap();
  let manifest:serde_json::Value=serde_json::from_slice(&fs::read(root.join(format!("../deploy/desktop-0.2.31/public/updates/{role}/latest.json"))).unwrap()).unwrap();
  assert_eq!(manifest["version"],"0.2.31");
  let entry=&manifest["platforms"]["windows-x86_64"];
  let host=if role=="admin"{"yonetim"}else{"portal"};
  let url=entry["url"].as_str().unwrap();assert_eq!(url,format!("https://{host}.omnigoglobal.com/updates/{role}/{role}-0.2.31-x64-setup.exe"));
  let key=PublicKey::decode(&String::from_utf8(STANDARD.decode(config["plugins"]["updater"]["pubkey"].as_str().unwrap()).unwrap()).unwrap()).unwrap();
  let signature=Signature::decode(&String::from_utf8(STANDARD.decode(entry["signature"].as_str().unwrap()).unwrap()).unwrap()).unwrap();
  let bytes=fs::read(root.join(format!("../deploy/desktop-0.2.31/public/updates/{role}/{role}-0.2.31-x64-setup.exe"))).unwrap();
  key.verify(&bytes,&signature,true).unwrap();
  let mut altered=bytes.clone();altered[100]^=1;assert!(key.verify(&altered,&signature,true).is_err());
  keys.push(key);releases.push((bytes,signature));println!("{role}: imza, kanal ve değiştirilmiş paket reddi doğrulandı.");
 }
 assert!(keys[0].verify(&releases[1].0,&releases[1].1,true).is_err());
 assert!(keys[1].verify(&releases[0].0,&releases[0].1,true).is_err());
}
