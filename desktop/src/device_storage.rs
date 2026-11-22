use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Manager};
#[derive(Clone, Serialize, Deserialize)]
#[serde(rename_all="camelCase")]
pub struct Device {
 pub device_id:String, pub token:Option<String>, pub id:Option<u64>, pub user_id:Option<u64>,
 pub cursor:u64, pub sound:bool, pub opted_out:bool,
 #[serde(default)] pub chat_cursor:Option<u64>,
}
impl Default for Device {
 fn default()->Self{let bytes: [u8;32]=rand::random();Self{device_id:bytes.iter().map(|b|format!("{b:02x}")).collect(),token:None,id:None,user_id:None,cursor:0,sound:true,opted_out:false,chat_cursor:None}}
}
fn path(app:&AppHandle)->Result<std::path::PathBuf,String>{
 let folder=app.path().app_local_data_dir().map_err(|_|"Cihaz klasörü açılamadı.")?;
 std::fs::create_dir_all(&folder).map_err(|_|"Cihaz klasörü hazırlanamadı.")?;
 Ok(folder.join("notification-device.dpapi"))
}
#[cfg(windows)]
pub(crate) fn crypt(bytes:&[u8],protect:bool)->Result<Vec<u8>,String>{
 use windows::{core::PCWSTR,Win32::{Security::Cryptography::{CRYPT_INTEGER_BLOB,CryptProtectData,CryptUnprotectData,CRYPTPROTECT_UI_FORBIDDEN},Foundation::{LocalFree,HLOCAL}}};
 let input=CRYPT_INTEGER_BLOB{cbData:bytes.len() as u32,pbData:bytes.as_ptr() as *mut u8};
 let mut output=CRYPT_INTEGER_BLOB::default();
 unsafe{
  if protect{CryptProtectData(&input,PCWSTR::null(),None,None,None,CRYPTPROTECT_UI_FORBIDDEN,&mut output)}
  else{CryptUnprotectData(&input,None,None,None,None,CRYPTPROTECT_UI_FORBIDDEN,&mut output)}
   .map_err(|_|"Cihaz anahtarı Windows tarafından korunamadı.".to_string())?;
  let result=std::slice::from_raw_parts(output.pbData,output.cbData as usize).to_vec();
  let _=LocalFree(Some(HLOCAL(output.pbData as _)));Ok(result)
 }
}
#[cfg(not(windows))]
pub(crate) fn crypt(_bytes:&[u8],_protect:bool)->Result<Vec<u8>,String>{Err("Windows gereklidir.".into())}
pub fn load(app:&AppHandle)->Result<Device,String>{
 let file=path(app)?;if !file.exists(){let device=Device::default();save(app,&device)?;return Ok(device);}
 let bytes=std::fs::read(file).map_err(|_|"Cihaz kaydı okunamadı.")?;
 if bytes.len()>16384{return Err("Cihaz kaydı geçersiz.".into());}
 serde_json::from_slice(&crypt(&bytes,false)?).map_err(|_|"Cihaz kaydı geçersiz.".into())
}
pub fn save(app:&AppHandle,device:&Device)->Result<(),String>{
 let data=serde_json::to_vec(device).map_err(|_|"Cihaz kaydı hazırlanamadı.")?;
 let encrypted=crypt(&data,true)?;let destination=path(app)?;
 let folder=destination.parent().ok_or("Cihaz klasörü bulunamadı.")?;
 let mut file=tempfile::NamedTempFile::new_in(folder).map_err(|_|"Cihaz kaydı yazılamadı.")?;
 use std::io::Write;file.write_all(&encrypted).map_err(|_|"Cihaz kaydı yazılamadı.")?;
 file.persist(destination).map_err(|_|"Cihaz kaydı kaydedilemedi.")?;Ok(())
}
#[cfg(all(test,windows))]
mod tests{
 #[test]fn dpapi_protects_and_authenticates(){
  let bytes=b"test-device-token";
  let cipher=super::crypt(bytes,true).unwrap();assert_ne!(cipher,bytes);
  assert_eq!(super::crypt(&cipher,false).unwrap(),bytes);
  let mut damaged=cipher;let last=damaged.len()-1;damaged[last]^=1;
  assert!(super::crypt(&damaged,false).is_err());
 }
}

