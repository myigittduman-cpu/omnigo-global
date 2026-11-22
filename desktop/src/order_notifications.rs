use tauri::{AppHandle, WebviewWindow, Manager};

const CUSTOMER_ORDER_EVENTS: [&str; 4] = [
    "client_delivery_added", "client_delivery_cancelled", "client_shipment_added", "client_shipment_cancelled",
];

fn client_operation_allowed(status: &str) -> bool {
    matches!(status, "payment_updated" | "store_setup_completed") || CUSTOMER_ORDER_EVENTS.contains(&status)
}

fn provider_name(provider: &str) -> Option<&'static str> {
    match provider {
        "hepsiburada" => Some("Hepsiburada"), "n11" => Some("n11"),
        "idefix" => Some("İdefix"), "pttavm" => Some("PttAvm"),
        "pazarama" => Some("Pazarama"), "koctas" => Some("Koçtaş"),
        "teknosa" => Some("Teknosa"), "trendyol" => Some("Trendyol"),
        "portal" => Some("Omnigo Global"), _ => None,
    }
}

#[tauri::command]
pub async fn preview_order_sound(app: AppHandle, window: WebviewWindow) -> Result<(), String> {
    let url = window.url().map_err(|_| "Pencere doğrulanamadı.")?;
    if app.config().identifier != "com.omnigoglobal.admin" || window.label() != "main"
        || !crate::reports::allowed_origin(&url, &app.config().identifier) {
        return Err("Ses yalnızca yönetici uygulamasında çalınabilir.".into());
    }
    #[cfg(windows)] {
        crate::notification_sound::play(&app)
    }
    #[cfg(not(windows))] { Err("Bu ses Windows uygulamasında kullanılabilir.".into()) }
}

#[tauri::command]
pub async fn notify_order(app: AppHandle, window: WebviewWindow, provider: String, company: String, count: u32, sound: bool, event_type: Option<String>, status: Option<String>, presentation: Option<serde_json::Value>, destination: Option<String>) -> Result<(), String> {
    let url = window.url().map_err(|_| "Pencere doğrulanamadı.")?;
    if app.config().identifier != "com.omnigoglobal.admin" || window.label() != "main"
        || !crate::reports::allowed_origin(&url, &app.config().identifier) {
        return Err("Bildirim yalnızca yönetici uygulamasından gönderilebilir.".into());
    }
    show_event(&app,&provider,&company,count,sound,event_type.as_deref().unwrap_or("order"),status.as_deref().unwrap_or(""),presentation.as_ref(),destination.as_deref())
}

pub fn show_event(app:&AppHandle,provider:&str,company:&str,count:u32,sound:bool,event_type:&str,status:&str,presentation:Option<&serde_json::Value>,destination:Option<&str>)->Result<(),String>{
    if !event_allowed(&app.config().identifier,event_type){return Err("Bu bildirim bu uygulamaya ait değil.".into());}
    if app.config().identifier=="com.omnigoglobal.client"&&event_type=="operation"&&!client_operation_allowed(status){return Err("Bu işlem bildirimi müşteri uygulamasına ait değil.".into());}
    provider_name(provider).ok_or("Pazaryeri geçersiz.")?;
    if count == 0 || count > 100_000 || company.chars().count() > 150 || company.chars().any(char::is_control) {
        return Err("Bildirim bilgisi geçersiz.".into());
    }
    let (title,mut body)=notification_content(&app.config().identifier,provider,company,count,event_type,status)?;
    if let Some(value)=presentation {
        if !value["title"].as_str().is_some_and(|value|crate::application_name::accepts_notification_title(&app.config().identifier,value)){return Err("Bildirim başlığı geçersiz.".into());}
        let text=value["body"].as_str().filter(|v|!v.is_empty()&&v.chars().count()<=500&&!v.chars().any(char::is_control)).ok_or("Bildirim metni geçersiz.")?;
        body=text.to_string();
    }
    let destination=destination.and_then(|value|destination_url(&app.config().identifier,value));
    #[cfg(windows)] {
        use tauri_winrt_notification::{Toast, Duration, IconCrop};
        let identifier = crate::notification_branding::notification_id(app)?;
        let handle=app.clone();
        let mut toast = Toast::new(&identifier);
        if let Ok(icon) = crate::notification_branding::icon_path(app)
            .and_then(|path| crate::notification_branding::toast_icon_source(&path)) {
            toast = toast.icon(&icon, IconCrop::Square, "Omnigo Global");
        }
            toast
                .title(&title)
                .text1(&body)
                .duration(Duration::Short)
                .sound(None)
                .on_activated(move |_| {
                    crate::background::open(&handle);
                    if let (Some(window),Some(url))=(handle.get_webview_window("main"),destination.as_ref()){
                        if let Some(thread)=url.query_pairs().find(|(key,_)|key=="chatThread").and_then(|(_,id)|id.parse::<u64>().ok()){
                            let _=window.eval(format!("window.__OMNIGO_CHAT_LAUNCH__={thread};window.dispatchEvent(new Event('omnigo-chat-launch'))"));
                        }else{let _=window.navigate(url.clone());}
                    }
                    Ok(())
                })
                .show().map_err(|_| "Windows bildirimi gösterilemedi.".to_string())?;
        if sound && crate::notification_sound::allowed(app){let _=crate::notification_sound::play(app);}
        Ok(())
    }
    #[cfg(not(windows))] { let _ = (title, body, sound); Err("Bu bildirim Windows uygulamasında kullanılabilir.".into()) }
}

fn destination_url(identifier:&str,path:&str)->Option<tauri::Url>{
    if !path.starts_with("/?")||path.len()>220{return None;}
    let host=match identifier {"com.omnigoglobal.admin"=>"yonetim","com.omnigoglobal.client"=>"portal",_=>return None};
    let url=tauri::Url::parse(&format!("https://{host}.omnigoglobal.com{path}")).ok()?;
    if url.fragment().is_some(){return None;}
    if let Some((key,value))=url.query_pairs().next(){
        if key=="chatThread"{
            return (url.query_pairs().count()==1&&value.len()<=15&&value.bytes().all(|c|c.is_ascii_digit())
                &&value.parse::<u64>().ok().is_some_and(|id|id>0)).then_some(url.clone());
        }
    }
    let mut seen=std::collections::HashSet::new();
    for (key,value) in url.query_pairs(){
        if !seen.insert(key.to_string()){return None;}
        let valid=match key.as_ref(){
            "view"=>matches!(value.as_ref(),"orders"|"shipments"|"deliveries"|"support"|"connections"|"payments"|"returns"|"inventory"|"overview"),
            "noticeType"=>matches!(value.as_ref(),"order"|"operation"|"shipment"|"support"|"user"),
            "orderCompany"|"noticeId"=>!value.is_empty()&&value.len()<=10&&value.bytes().all(|c|c.is_ascii_digit())&&value.parse::<u64>().ok().is_some_and(|n|n>0),
            _=>false,
        };if !valid{return None;}
    }
    let view=url.query_pairs().find(|(key,_)|key=="view").map(|(_,value)|value);
    let scoped_operation=seen.contains("orderCompany")&&seen.contains("noticeId")
        &&url.query_pairs().any(|(key,value)|key=="noticeType"&&value=="operation");
    if view.as_deref()==Some("overview") && !scoped_operation {return None;}
    if identifier=="com.omnigoglobal.client" {
        match view.as_deref() {
            Some("deliveries"|"shipments"|"overview") if scoped_operation=>{},
            None|Some("support"|"payments")=>{},
            _=>return None,
        }
    }
    Some(url)
}

pub fn operation_status(item:&serde_json::Value)->&'static str{
    CUSTOMER_ORDER_EVENTS.into_iter()
        .chain(["cancelled","cancellation_requested","payment_updated","return_updated","dispatched","document_uploaded","document_deleted","inventory_low","inventory_restocked","inventory_adjusted","purchase_price_updated","marketplace_import_completed","marketplace_import_failed","upcoming_payout_amount_changed","store_setup_completed"])
        .find(|status|item["events"].as_array().is_some_and(|events|events.iter().any(|event|event.as_str()==Some(*status)))).unwrap_or("transferred")
}

fn event_allowed(identifier:&str,event_type:&str)->bool{
    match identifier {"com.omnigoglobal.client"=>matches!(event_type,"resolved"|"operation"|"chat"),"com.omnigoglobal.admin"=>matches!(event_type,"order"|"operation"|"shipment"|"support"|"user"|"chat"),_=>false}
}

fn notification_content(identifier:&str,provider:&str,company:&str,count:u32,event_type:&str,status:&str)->Result<(&'static str,String),String>{
    let app_name=crate::application_name::for_notification_identifier(identifier).ok_or("Bildirim uygulaması geçersiz.")?;
    let (event_title,event_body)=event_text(event_type,status,count)?;
    let detail=if event_type=="order" {
        let source=match provider {"hepsiburada"=>"Hepsiburada’dan","n11"=>"n11’den","idefix"=>"idefix’ten","pttavm"=>"PTT AVM’den","pazarama"=>"Pazarama’dan","koctas"=>"Koçtaş’tan","teknosa"=>"Teknosa’dan","trendyol"=>"Trendyol’dan",_=>"Pazaryerinden"};
        format!("{source} yeni {} siparişiniz var.",if count==1 {"bir".into()}else{count.to_string()})
    }else if event_type=="operation"&&status=="cancelled" {"Sipariş iptal edildi.".into()}else {if event_body.is_empty(){format!("{event_title}.")}else{format!("{event_title}. {event_body}")}};
    let company=if company.trim().to_lowercase()=="vizyona beyaz eşya"{"Vizyona"}else{company.trim()};
    Ok((app_name,if company.is_empty()||identifier=="com.omnigoglobal.client"{detail}else{format!("{company}; {detail}")}))
}

fn event_text(event_type:&str,status:&str,count:u32)->Result<(&'static str,String),String>{
    Ok(match (event_type,status){
        ("chat",_) => ("Yeni sohbet mesajınız var",String::new()),
        ("resolved","reply") => ("Omnigo, destek talebine cevap verdi",String::new()),
        ("resolved",_) => ("Omnigo, destek talebinizi çözüldü olarak kapattı",String::new()),
        ("operation","payment_updated") => ("Omnigo, hakediş takvimini güncelledi",String::new()),
        ("operation","client_delivery_added") => ("Şehir Dışı Teslimatlar listesine yeni sipariş eklendi",String::new()),
        ("operation","client_delivery_cancelled") => ("İptal edilen sipariş Şehir Dışı Teslimatlar listesinden çıkarıldı",String::new()),
        ("operation","client_shipment_added") => ("Şehir Dışı Sevkiyat tablosuna yeni sipariş eklendi",String::new()),
        ("operation","client_shipment_cancelled") => ("İptal edilen sipariş satırı aktif sevkiyattan çıkarıldı",String::new()),
        ("operation","return_updated") => ("İade durumu güncellendi",String::new()),
        ("operation","dispatched") => ("Siparişler sevk edildi",String::new()),
        ("operation","document_uploaded") => ("Belge yüklendi",String::new()),
        ("operation","document_deleted") => ("Belge kaldırıldı",String::new()),
        ("operation","inventory_low") => ("Ürün stoğu tükendi",String::new()),
        ("operation","inventory_restocked") => ("Ürün yeniden stoğa girdi",String::new()),
        ("operation","marketplace_import_completed") => ("Pazaryeri aktarımı tamamlandı",String::new()),
        ("operation","marketplace_import_failed") => ("Pazaryeri aktarımı tamamlanamadı",String::new()),
        ("operation","upcoming_payout_amount_changed") => ("Yaklaşan hakediş tutarı değişti",String::new()),
        ("operation","store_setup_completed") => ("Mağaza kurulumu tamamlandı",String::new()),
        ("operation","inventory_adjusted") => ("Ürün stoğu güncellendi",String::new()),
        ("operation","purchase_price_updated") => ("Ürünün alış fiyatı güncellendi",String::new()),
        ("order",_) => ("Yeni sipariş",format!("{count} yeni sipariş geldi.")),
        ("operation","cancelled") => ("Sipariş iptal edildi","İptal bilgisi işlendi; iptal edilen ürünler teslimat listesinden çıkarıldı.".into()),
        ("operation","cancellation_requested") => ("Sipariş için iptal talebi geldi","Pazaryerindeki talebi inceleyin. Sipariş henüz iptal edilmiş sayılmaz.".into()),
        ("shipment","document") => ("Müşteri belge yükledi","Ayrıntıları yönetici bildirimlerinden inceleyin.".into()),
        ("shipment",_) => ("Müşteri sevkiyatı güncelledi","Kimin hangi satırı değiştirdiğini yönetici bildirimlerinden inceleyin.".into()),
        ("support",_) => ("Müşteri destek talebi oluşturdu","Ayrıntıları Kullanıcı Bildirimleri bölümünden inceleyin.".into()),
        ("user","reply") => ("Müşteri destek mesajı gönderdi","Ayrıntıları Kullanıcı Bildirimleri bölümünden inceleyin.".into()),
        ("user","closed") => ("Müşteri destek talebini kapattı","Ayrıntıları Kullanıcı Bildirimleri bölümünden inceleyin.".into()),
        ("user","reopened") => ("Müşteri destek talebini yeniden açtı","Ayrıntıları Kullanıcı Bildirimleri bölümünden inceleyin.".into()),
        ("user",_) => ("Müşteri destek talebini güncelledi","Ayrıntıları Kullanıcı Bildirimleri bölümünden inceleyin.".into()),
        _ => return Err("Bildirim türü geçersiz.".into())
    })
}

#[cfg(test)]
mod tests {
    #[test]
    fn application_name_is_above_company_and_event_detail(){
        let (title,body)=super::notification_content("com.omnigoglobal.admin","hepsiburada","Vizyona Beyaz Eşya",1,"order","").unwrap();
        assert_eq!(title,"Omnigo Global Yönetici Portalı");
        assert_eq!(body,"Vizyona; Hepsiburada’dan yeni bir siparişiniz var.");
        let (title,body)=super::notification_content("com.omnigoglobal.client","portal","Firma",1,"resolved","").unwrap();
        assert_eq!(title,"Omnigo Global İş Ortağım");assert_eq!(body,"Omnigo, destek talebinizi çözüldü olarak kapattı.");
        assert!(super::notification_content("com.omnigoglobal.admin","n11","Firma",3,"order","").unwrap().1.contains("3 siparişiniz var"));
    }
    #[test]
    fn operational_events_never_look_like_new_orders(){
        assert_eq!(super::event_text("operation","cancelled",1).unwrap().0,"Sipariş iptal edildi");
        assert_eq!(super::event_text("operation","cancellation_requested",1).unwrap().0,"Sipariş için iptal talebi geldi");
        assert_eq!(super::event_text("shipment","document",1).unwrap().0,"Müşteri belge yükledi");
        assert_eq!(super::event_text("shipment","edit",1).unwrap().0,"Müşteri sevkiyatı güncelledi");
        assert!(super::event_text("invalid","",1).is_err());
        assert!(super::event_text("integration","error",1).is_err());
        assert!(super::event_text("integration","recovered",1).is_err());
        assert!(super::event_text("operation","transferred",1).is_err());
        assert!(super::event_text("support","created",1).is_ok());
        assert!(super::event_text("user","reply",1).is_ok());
    }
    #[test]
    fn provider_labels_are_fixed() {
        assert_eq!(super::provider_name("pazarama"), Some("Pazarama"));
        assert_eq!(super::provider_name("<script>"), None);
    }
    #[test]
    fn customer_order_events_have_native_text_and_scoped_destinations() {
        let id="com.omnigoglobal.client";
        for status in super::CUSTOMER_ORDER_EVENTS {
            let item=serde_json::json!({"events":[status]});
            assert_eq!(super::operation_status(&item),status);
            assert!(super::client_operation_allowed(status));
            let (title,body)=super::notification_content(id,"n11","Örnek Firma",1,"operation",status).unwrap();
            assert_eq!(title,"Omnigo Global İş Ortağım");
            assert!(body.contains("sipariş"));assert!(!body.contains("Örnek Firma"));
        }
        assert!(!super::client_operation_allowed("cancelled"));
        for view in ["shipments","deliveries"] {
            assert!(super::destination_url(id,&format!("/?view={view}&orderCompany=1&noticeType=operation&noticeId=12")).is_some());
            assert!(super::destination_url(id,&format!("/?view={view}&orderCompany=1&noticeType=order&noticeId=12")).is_none());
        }
    }
    #[test]
    fn stock_and_purchase_price_events_have_native_presentation(){
        for status in ["inventory_low","inventory_restocked","inventory_adjusted","purchase_price_updated"]{
            let item=serde_json::json!({"events":[status]});
            assert_eq!(super::operation_status(&item),status);
            assert!(super::event_text("operation",status,1).is_ok());
            let (title,body)=super::notification_content("com.omnigoglobal.admin","hepsiburada","Örnek Firma",1,"operation",status).unwrap();
            assert_eq!(title,"Omnigo Global Yönetici Portalı");assert!(body.starts_with("Örnek Firma; "));assert!(!body.contains("yeni sipariş"));
        }
        assert!(super::destination_url("com.omnigoglobal.admin","/?view=inventory&orderCompany=2&noticeType=operation&noticeId=81").is_some());
        assert!(super::destination_url("com.omnigoglobal.admin","/?view=returns&orderCompany=2&noticeType=operation&noticeId=82").is_some());
        assert!(super::destination_url("com.omnigoglobal.client","/?view=inventory&orderCompany=2&noticeType=operation&noticeId=81").is_none());
    }
    #[test]
    fn targeted_notifications_only_open_the_expected_roles_and_routes(){
        let admin="com.omnigoglobal.admin";
        let client="com.omnigoglobal.client";
        for status in ["marketplace_import_completed","marketplace_import_failed","upcoming_payout_amount_changed","store_setup_completed","inventory_restocked"]{
            let item=serde_json::json!({"events":[status]});
            assert_eq!(super::operation_status(&item),status);
            assert!(super::notification_content(admin,"portal","Mağaza",1,"operation",status).is_ok());
            assert_eq!(super::client_operation_allowed(status),status=="store_setup_completed");
        }
        for role in [admin,client]{
            assert!(super::destination_url(role,"/?view=overview&orderCompany=9&noticeType=operation&noticeId=32").is_some());
            for value in ["/?view=overview","/?view=overview&noticeType=operation&noticeId=32","/?view=overview&orderCompany=9&noticeType=user&noticeId=32"]{
                assert!(super::destination_url(role,value).is_none());
            }
        }
    }
    #[test]
    fn notification_links_stay_inside_the_app(){
        let id="com.omnigoglobal.admin";
        assert!(super::destination_url(id,"/?view=orders&orderCompany=1&noticeType=order&noticeId=5").is_some());
        for path in ["https://other.test/","//other.test/","/?view=orders&redirect=https://other.test","/?view=orders&noticeId=0","/?view=orders&view=support","/?view=orders#anything"]{assert!(super::destination_url(id,path).is_none());}
        assert!(super::destination_url("com.omnigoglobal.client","/?view=orders").is_none());
        assert!(super::destination_url("com.omnigoglobal.client","/?view=payments&orderCompany=1").is_some());
        assert!(super::destination_url("com.omnigoglobal.client","/?view=shipments").is_none());
        assert!(!super::event_allowed("com.omnigoglobal.client","order"));
        assert!(super::event_allowed("com.omnigoglobal.client","operation"));
        assert!(!super::event_allowed("com.omnigoglobal.client","shipment"));
        assert_eq!(super::notification_content(id,"hepsiburada","Vizyona Beyaz Eşya",1,"operation","cancelled").unwrap().1,"Vizyona; Sipariş iptal edildi.");
    }
}
