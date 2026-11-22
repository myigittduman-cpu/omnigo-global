use std::{sync::{Mutex,atomic::{AtomicBool,Ordering}},time::Duration,io::Read};
use serde_json::{json,Value};
use tauri::{App,AppHandle,Manager,WebviewWindow};
use crate::device_storage::{self,Device};
pub struct Background(pub Mutex<Device>);
static SILENT:AtomicBool=AtomicBool::new(false);
pub fn silent()->bool{SILENT.load(Ordering::Acquire)}
pub fn end_silent_start()->bool{SILENT.swap(false,Ordering::AcqRel)}
#[cfg(all(test,windows))]
pub fn begin_test_silent_start(){SILENT.store(true,Ordering::Release);}
pub fn enabled(app:&AppHandle)->bool{app.try_state::<Background>().map(|s|s.0.lock().map(|d|d.token.is_some()).unwrap_or(false)).unwrap_or(false)}
pub fn open(app:&AppHandle){
 crate::startup::activate(app);
}
pub fn start(app:&mut App, background_launch:bool)->Result<(),Box<dyn std::error::Error>>{
 if !matches!(app.config().identifier.as_str(),"com.omnigoglobal.admin"|"com.omnigoglobal.client"){return Ok(());}
 let device=device_storage::load(app.handle()).map_err(std::io::Error::other)?;
 SILENT.store(background_launch,Ordering::Release);
 app.manage(Background(Mutex::new(device)));
 crate::tray_menu::start(app)?;
 let handle=app.handle().clone();
 std::thread::spawn(move||poll_loop(handle));Ok(())
}
#[tauri::command]
pub async fn background_action(app:AppHandle,window:WebviewWindow,action:String,data:Option<Value>)->Result<Value,String>{
 let url=window.url().map_err(|_|"Pencere doğrulanamadı.")?;
 if !matches!(app.config().identifier.as_str(),"com.omnigoglobal.admin"|"com.omnigoglobal.client")||window.label()!="main"||!crate::reports::allowed_origin(&url,&app.config().identifier){return Err("Bildirim ayarı bu sayfada kullanılamaz.".into());}
 let state=app.state::<Background>();let mut device=state.0.lock().map_err(|_|"Cihaz ayarı meşgul.")?;
 let data=data.unwrap_or(Value::Null);
 match action.as_str(){
  "status"=>return Ok(json!({"deviceId":device.device_id,"id":device.id,"userId":device.user_id,"enabled":device.token.is_some(),"sound":device.sound,"optedOut":device.opted_out})),
  "configure"=>{
   let token=data["token"].as_str().filter(|s|s.len()==64&&s.bytes().all(|b|b.is_ascii_hexdigit())).ok_or("Cihaz anahtarı geçersiz.")?;
   if data["deviceId"].as_str()!=Some(device.device_id.as_str()){return Err("Cihaz eşleşmedi.".into());}
   let mut next=device.clone();next.token=Some(token.into());next.id=Some(data["id"].as_u64().filter(|id|*id>0).ok_or("Cihaz numarası geçersiz.")?);
   next.user_id=Some(data["userId"].as_u64().filter(|id|*id>0).ok_or("Hesap geçersiz.")?);
   if next.user_id!=device.user_id{next.chat_cursor=None;}
   next.cursor=data["cursor"].as_u64().ok_or("Bildirim konumu geçersiz.")?;next.sound=data["sound"].as_bool().unwrap_or(true);next.opted_out=false;
   crate::application_preferences::update_notification_startup(&app,true)?;
   device_storage::save(&app,&next)?;*device=next;
  },
  "disable"=>{device.token=None;device.opted_out=true;device_storage::save(&app,&device)?;crate::application_preferences::update_notification_startup(&app,false)?;},
  "sound"=>{device.sound=data["sound"].as_bool().ok_or("Ses tercihi geçersiz.")?;device_storage::save(&app,&device)?;},
  _=>return Err("Bildirim işlemi geçersiz.".into()),
 }
 drop(device);
 if matches!(action.as_str(),"configure"|"disable"){crate::tray_menu::refresh_preferences(&app);}
 Ok(json!({"ok":true}))
}
fn matches_device(app:&AppHandle,expected:&Device)->bool{
 app.state::<Background>().0.lock().map(|current|current.token==expected.token&&current.id==expected.id&&current.user_id==expected.user_id).unwrap_or(false)
}
fn checkpoint_cursor(app:&AppHandle,expected:&Device,cursor:u64,sound:bool)->bool{
 let state=app.state::<Background>();let Ok(mut current)=state.0.lock() else{return false;};
 if current.token!=expected.token||current.id!=expected.id||current.user_id!=expected.user_id||cursor<current.cursor{return false;}
 let mut next=current.clone();next.cursor=cursor;next.sound=sound;
 if device_storage::save(app,&next).is_err(){return false;}*current=next;true
}
fn checkpoint_chat(app:&AppHandle,expected:&Device,cursor:u64)->bool{
 let state=app.state::<Background>();let Ok(mut current)=state.0.lock() else{return false;};
 if current.token!=expected.token||current.id!=expected.id||current.user_id!=expected.user_id||cursor<current.chat_cursor.unwrap_or(0){return false;}
 let mut next=current.clone();next.chat_cursor=Some(cursor);
 if device_storage::save(app,&next).is_err(){return false;}*current=next;true
}

fn show_chat(app:&AppHandle,device:&Device,data:&Value,sound:bool)->bool{
 let Some(feed)=data.as_object() else{return true;};
 let after=device.chat_cursor.unwrap_or(0);
 let Some(cursor)=feed.get("cursor").and_then(Value::as_u64).filter(|cursor|*cursor>=after) else{return false;};
 let Some(items)=feed.get("items").and_then(Value::as_array).filter(|items|items.len()<=100) else{return false;};
 if device.chat_cursor.is_none()&&items.is_empty(){return checkpoint_chat(app,device,cursor);}
 let Some(ids)=items.iter().map(|item|item["id"].as_u64()).collect::<Option<Vec<_>>>() else{return false;};
 crate::notification_cursor::deliver_batch(after,cursor,&ids,|index|{
  if !matches_device(app,device){return false;}
  let item=&items[index];
  crate::order_notifications::show_event(app,"portal","",1,sound,"chat","",Some(item),item["url"].as_str()).is_ok()
 },|next|checkpoint_chat(app,device,next))
}
fn poll_loop(app:AppHandle){
 let Ok(client)=reqwest::blocking::Client::builder().timeout(Duration::from_secs(20)).connect_timeout(Duration::from_secs(6)).redirect(reqwest::redirect::Policy::none()).no_proxy().build() else{return;};
 let mut delay=15;
 loop{
  std::thread::sleep(Duration::from_secs(delay));
  let device=match app.state::<Background>().0.lock(){Ok(d)=>d.clone(),Err(_)=>continue};
  let Some(token)=device.token.as_ref() else{continue};
  let host=if app.config().identifier=="com.omnigoglobal.admin"{"yonetim"}else{"portal"};
  let chat_after=device.chat_cursor.map(|value|format!("&chatAfter={value}")).unwrap_or_default();
  let response=client.get(format!("https://{host}.omnigoglobal.com/api/push/desktop/poll?events=all&recovery=1&operations=1&noticeSchema=2&chat=1&after={}{chat_after}",device.cursor)).header("X-Omnigo-Device",token).send();
  let Ok(response)=response else{delay=(delay*2).min(120);continue;};
  if response.status().as_u16()==401{
   if let Ok(mut current)=app.state::<Background>().0.lock(){if current.token==device.token{current.token=None;let _=device_storage::save(&app,&current);}}
   delay=60;continue;
  }
  if !response.status().is_success(){delay=(delay*2).min(120);continue;}
  let mut body=Vec::new();if response.take(1048577).read_to_end(&mut body).is_err()||body.len()>1048576{delay=120;continue;}
  let Ok(data)=serde_json::from_slice::<Value>(&body) else{delay=120;continue;};
  let Some(cursor)=data["cursor"].as_u64().filter(|c|*c>=device.cursor) else{delay=120;continue;};
  let Some(items)=data["items"].as_array().filter(|v|v.len()<=100) else{delay=120;continue;};
  let sound=data["sound"].as_bool().unwrap_or(device.sound);
  let Some(ids)=items.iter().map(|item|item["id"].as_u64()).collect::<Option<Vec<_>>>() else{delay=120;continue;};
  let delivered=crate::notification_cursor::deliver_batch(device.cursor,cursor,&ids,|index|{
   if !matches_device(&app,&device){return false;}
   let item=&items[index];let(Some(provider),Some(company))=(item["provider"].as_str(),item["company"].as_str()) else{return false;};
   let event_type=item["type"].as_str().unwrap_or("order");
   let status=match event_type{"operation"=>crate::order_notifications::operation_status(item),"shipment"|"support"|"user"=>item["operation"].as_str().unwrap_or("edit"),_=>item["status"].as_str().unwrap_or("")};
   crate::order_notifications::show_event(&app,provider,company,1,sound,event_type,status,item["presentation"].is_object().then_some(&item["presentation"]),item["url"].as_str()).is_ok()
  },|next|checkpoint_cursor(&app,&device,next,sound));
  // Failed toast submission or local persistence leaves the first failed item for retry.
  let chat_delivered=show_chat(&app,&device,&data["chat"],sound);
  delay=if delivered&&chat_delivered{15}else{(delay*2).min(120)};
 }
}

