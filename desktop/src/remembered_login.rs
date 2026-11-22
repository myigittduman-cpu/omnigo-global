use serde::{Deserialize,Serialize};
use serde_json::{json,Value};
use std::path::Path;
use tauri::{AppHandle,Manager,WebviewWindow};

#[derive(Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
struct Credential { identity:String,password:String }
fn valid_identity(value:&str)->bool { !value.trim().is_empty()&&value.chars().count()<=200&&!value.chars().any(char::is_control) }
fn valid_password(value:&str)->bool { !value.is_empty()&&value.chars().count()<=128 }

fn read(file:&Path,identity:&str)->Result<Value,String>{
 let bytes=match std::fs::read(file){Ok(bytes)=>bytes,Err(e) if e.kind()==std::io::ErrorKind::NotFound=>return Ok(Value::Null),Err(_)=>return Err("Kayıtlı giriş bilgisi okunamadı.".into())};
 if bytes.len()>8192{return Err("Kayıtlı giriş bilgisi geçersiz.".into());}
 let plain=crate::device_storage::crypt(&bytes,false)?;
 let credential:Credential=serde_json::from_slice(&plain).map_err(|_|"Kayıtlı giriş bilgisi geçersiz.")?;
 if !valid_identity(&credential.identity)||!valid_password(&credential.password)||credential.identity.to_lowercase()!=identity.to_lowercase(){return Ok(Value::Null);}
 Ok(json!({"type":"password","id":credential.identity,"password":credential.password}))
}
fn write(file:&Path,identity:String,password:String)->Result<(),String>{
 if !valid_identity(&identity)||!valid_password(&password){return Err("Giriş bilgisi geçersiz.".into());}
 let data=serde_json::to_vec(&Credential{identity,password}).map_err(|_|"Giriş bilgisi hazırlanamadı.")?;
 let encrypted=crate::device_storage::crypt(&data,true)?;
 let folder=file.parent().ok_or("Kasa klasörü bulunamadı.")?;
 std::fs::create_dir_all(folder).map_err(|_|"Kasa klasörü hazırlanamadı.")?;
 let mut temporary=tempfile::NamedTempFile::new_in(folder).map_err(|_|"Giriş bilgisi kaydedilemedi.")?;
 use std::io::Write;
 temporary.write_all(&encrypted).map_err(|_|"Giriş bilgisi kaydedilemedi.")?;
 temporary.persist(file).map_err(|_|"Giriş bilgisi kaydedilemedi.")?;
 Ok(())
}
fn clear(file:&Path)->Result<(),String>{
 match std::fs::remove_file(file){Ok(())=>Ok(()),Err(e) if e.kind()==std::io::ErrorKind::NotFound=>Ok(()),Err(_)=>Err("Kayıtlı giriş bilgisi kaldırılamadı.".into())}
}

#[tauri::command]
pub async fn remembered_login(app:AppHandle,window:WebviewWindow,operation:String,identity:Option<String>,password:Option<String>)->Result<Value,String>{
 let url=window.url().map_err(|_|"Pencere doğrulanamadı.")?;
 if !matches!(app.config().identifier.as_str(),"com.omnigoglobal.admin"|"com.omnigoglobal.client")||window.label()!="main"||!crate::reports::allowed_origin(&url,&app.config().identifier){return Err("Giriş kasasına bu sayfadan erişilemiyor.".into());}
 let file=app.path().app_local_data_dir().map_err(|_|"Kasa klasörü bulunamadı.")?.join("remembered-login.dpapi");
 match operation.as_str(){
  "get"=>{let identity=identity.filter(|value|valid_identity(value)).ok_or("Hesap bilgisi geçersiz.")?;read(&file,&identity)},
  "store"=>{write(&file,identity.ok_or("Hesap bilgisi eksik.")?,password.ok_or("Şifre eksik.")?)?;Ok(Value::Bool(true))},
  "clear"=>{clear(&file)?;Ok(Value::Bool(true))},
  _=>Err("Kasa işlemi desteklenmiyor.".into())
 }
}

#[cfg(all(test,windows))]
mod tests{
 #[test]
 fn password_is_encrypted_bound_to_identity_and_removed_on_opt_out(){
  let folder=tempfile::tempdir().unwrap();let file=folder.path().join("remembered-login.dpapi");
  super::write(&file,"admin@example.test".into(),"Only-Encrypted-On-Disk!".into()).unwrap();
  let bytes=std::fs::read(&file).unwrap();let secret=b"Only-Encrypted-On-Disk!";assert!(!bytes.windows(secret.len()).any(|chunk|chunk==secret));
  assert_eq!(super::read(&file,"ADMIN@example.test").unwrap()["password"],"Only-Encrypted-On-Disk!");
  assert!(super::read(&file,"other@example.test").unwrap().is_null());
  super::clear(&file).unwrap();assert!(super::read(&file,"admin@example.test").unwrap().is_null());super::clear(&file).unwrap();
 }
 #[test]
 fn damaged_and_oversized_records_never_return_credentials(){
  let folder=tempfile::tempdir().unwrap();let file=folder.path().join("remembered-login.dpapi");
  std::fs::write(&file,b"not a protected record").unwrap();assert!(super::read(&file,"admin@example.test").is_err());
  std::fs::write(&file,vec![0u8;8193]).unwrap();assert!(super::read(&file,"admin@example.test").is_err());
  assert!(super::write(&file,"".into(),"Example-Password!".into()).is_err());
 }
}
