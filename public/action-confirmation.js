import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const markets={hepsiburada:'Hepsiburada',n11:'n11',pazarama:'Pazarama',pttavm:'PTT AVM',koctas:'Koçtaş',idefix:'idefix',trendyol:'Trendyol'};
let context={};
export function setActionContext(company){if(context.id===Number(company?.id)&&context.name===company?.name)return;context=company?{id:Number(company.id),name:company.name}:{};}
export function mutationPrompt(path,method,data={}){
 const route=path.split('?')[0];
 if(method==='GET')return null;
 if(/^\/records\/payments\/\d+\/approve$/.test(route))return i18nT('Ödemeyi onaylıyor musunuz? Ödeme, Hesaba Geçen listesine ve toplamına eklenecek.');
 if(route==='/records/payments/selection/delete')return i18nText`${data.payments?.length||0} seçili hakediş listelerden ve ilgili toplamlardan kaldırılacak. Önceki bilgiler işlem geçmişinde saklanacak. Silmeyi onaylıyor musunuz?`;
 if(/^\/records\/payments\/\d+$/.test(route)&&method==='PUT'&&data.correctApproved)return i18nT('Onaylı hakedişteki değişiklikler kaydedilsin mi? Pazaryeri, ödeme durumu, tutar veya tarih değişirse hakedişin yeniden onaylanması gerekir.');
 if(route==='/account/sessions/revoke')return i18nT('Seçilen oturum kapatılsın mı? O cihazda yeniden giriş yapılması gerekir.');
 if(/^\/admin\/users(?:\/\d+)?$/.test(route))return i18nT(method==='DELETE'?'Kullanıcının giriş hesabı silinsin mi? Açık oturumlar kapanır; geçmiş işlem kayıtları korunur.':method==='POST'?'Yeni kullanıcı hesabı oluşturulsun mu?':'Kullanıcı bilgileri ve rolü kaydedilsin mi? Giriş veya rol değişikliklerinde açık oturumlar kapanır.');
 if(/^\/admin\/users\/\d+\/sessions$/.test(route))return i18nT('Bu kullanıcının tüm açık oturumları kapatılsın mı?');
 if(/^\/admin\/users\/\d+\/portrait$/.test(route))return i18nT(method==='DELETE'?'Profil resmi kaldırılsın mı?':'Profil resmi kaydedilsin mi?');
 if(route==='/inventory/start')return i18nT('Bu müşterinin pazaryeri ürün ve stok aktarımını başlatmak istiyor musunuz? İşlem sunucuda devam eder.');
 if(route==='/management/marketplaces/start')return i18nT('Bu müşterinin kayıtlı ve hazırlanan pazaryeri bağlantıları için aktarım kontrolleri başlatılsın mı?');
 if(route==='/inventory/products')return i18nT('Ürünü ve başlangıç mağaza stoğunu kaydetmek istiyor musunuz?');
 if(route==='/inventory/image')return i18nT(method==='DELETE'?'Bu ürünün görselini kaldırmak istiyor musunuz? Stok ve sipariş bilgileri korunur.':'Ürün görselini kaydetmek istiyor musunuz? Mevcut görsel varsa yeni görsel onun yerine geçer.');
 if(route==='/inventory/matching')return i18nT('Ürünü seçili pazaryerleriyle eşleştirmek istiyor musunuz? Mağaza stoğu değişmez.');
 if(route==='/inventory/commands')return i18nT(data.sourceVersion?'Horoz stok işlemini kaydetmek istiyor musunuz? Değişiklik ortak Stok alanına ve stok hareketlerine yansır.':'Mağaza stok işlemini kaydetmek istiyor musunuz? Pazaryeri stoklarına müdahale edilmez.');
 if(route==='/inventory/visibility')return i18nT(data.action==='remove'?'Seçili ürünler stok tablosundan silinsin mi? Sonraki aktarımlarda kendiliğinden geri eklenmez. Pazaryeri ilanları, siparişler ve stok hareketleri korunur. Gerekirse Silinen ürünler bölümünden geri alabilirsiniz.':'Seçili ürünler güncel stok bilgileriyle tabloya geri alınsın mı? Geçmiş siparişler stoktan tekrar düşülmez.')+'\n'+i18nT('Seçilen ürün')+': '+(Array.isArray(data.products)?data.products.length:1);
 if(route==='/management/marketplaces-bulk')return i18nT(data.action==='hide'?'Seçilen mağaza kartları gizlensin mi?':'Seçilen mağaza kartları gösterilsin mi?')+'\n'+(data.targetNames||[]).join(', ')+'\n'+i18nT('Canlı aktarım ve geçmiş kayıtlar korunur.');
 const market=route.match(/^\/management\/marketplaces\/([a-z0-9]+)$/);
 if(market){const name=markets[market[1]]||i18nT('Pazaryeri');return ({add:i18nText`${name} mağazasını eklemek istiyor musunuz?`,edit:i18nText`${name} mağaza bilgilerini kaydetmek istiyor musunuz?`,show:i18nText`${name} mağazasını iki panelde de göstermek istiyor musunuz?`,hide:i18nText`${name} mağaza kartını iki panelde de gizlemek istiyor musunuz? Otomatik aktarım ve geçmiş kayıtlar korunur.`,disconnect:i18nText`${name} bağlantısını kaldırmak istiyor musunuz? API bilgileri silinir ve otomatik aktarım durur. Mağaza tanımı ve geçmiş kayıtlar korunur.`,delete:i18nText`${name} mağaza tanımını ve API bilgilerini silmek istediğinizden emin misiniz? Otomatik aktarım durur; geçmiş siparişler ve raporlar korunur.`})[data.action]||null;}
 if(method==='DELETE'&&/^\/shipments\/\d+\/documents\/delivery$/.test(route))return i18nT('Bu siparişin teslimat evrağını silmek istiyor musunuz? Evrak iki uygulamadan da kaldırılacak.');
 if(method==='DELETE'&&/^\/chat\/threads\/\d+\/messages\/\d+$/.test(route))return i18nT('Yazdığınız mesajı silmek istiyor musunuz?');
 if(method==='DELETE'&&/^\/chat\/threads\/\d+\/messages$/.test(route))return i18nText`${data.count||0} seçili mesaj tüm katılımcıların sohbetinden silinsin mi?`;
 if(method==='DELETE'&&route==='/chat/threads')return i18nText`${data.threads?.length||0} konuşma listenizden kaldırılsın mı? Yeni mesaj gelirse konuşma yeniden görünür.`;
 if(method==='DELETE')return i18nT('Bu kaydı silmek istediğinizden emin misiniz?');
 if(route.endsWith('/delete'))return i18nT('Bu kaydı silmek istediğinizden emin misiniz?');
 if(route.endsWith('/restore')||route.endsWith('/undo'))return i18nT('Bu işlemi geri almak istiyor musunuz?');
 if(/\/shipments\/\d+$/.test(route)&&method==='PUT'&&data.field!=='rowStatus')return i18nT('Bu siparişin seçtiğiniz bilgisini değiştirmek istiyor musunuz?');
 if(route==='/notifications/read-all')return i18nT('Listelenen bildirimleri okundu olarak işaretlemek istiyor musunuz?');
 if(/^\/integrations\/[a-z0-9]+$/.test(route))return data.action==='retry'?i18nT('Bağlantı kontrolünü yeniden sıraya almak istiyor musunuz? Pazaryerinin bekleme süreleri korunur.'):i18nT('API bilgilerini kaydedip bu müşteri için otomatik aktarımı başlatmak istiyor musunuz?');
 if(route==='/companies')return i18nT('Yeni müşteriyi oluşturmak istiyor musunuz? Tablolar boş başlayacak.');
 if(route==='/users')return i18nT('Seçili müşteriye erişebilecek kullanıcı hesabını oluşturmak istiyor musunuz?');
 if(route==='/management/logo')return i18nT('Müşteri logosunu güncellemek istiyor musunuz? Değişiklik İş Ortağım’a da yansır.');
 if(/^\/orders\/-?\d+\/documents\/(invoice|delivery)$/.test(route))return method==='DELETE'?i18nT('Bu sipariş belgesini silmek istediğinizden emin misiniz?'):i18nT('Belgeyi kaydetmek istiyor musunuz? Daha önce yüklenmiş belge varsa yeni dosya onun yerine geçer.');
 if(route==='/management/permissions-bulk')return i18nT('Seçilen hesapların mevcut yetkileri değişecek. Uygulansın mı?')+'\n'+(data.targetNames||[]).join(', ')+'\n'+i18nT(data.includeSettings?'Bölüm, şehir ve bildirim ayarları da değişecek.':'Yalnız seçilen bölümlerin işlem yetkileri değişecek.');
 if(route==='/management/permissions')return (data.targetName?i18nT('Müşteri')+': '+data.targetName+'\n':'')+i18nT('Değişiklikleri kaydetmek istiyor musunuz? Bölümler, şehirler ve kullanıcı yetkileri yalnız seçili müşteriye uygulanır.');
 if(route==='/management/new-records'&&method==='POST')return i18nT('Bu mağazanın yeni pazaryeri kayıtları alınsın mı?');
 if(route==='/management/menu-labels'&&method==='PUT')return i18nT(data.scope==='global'?'Değiştirdiğiniz menü adları tüm mağazalarda güncellensin mi?':data.scope==='user'?'Menü adları yalnız seçili kullanıcı için güncellensin mi?':'Menü adları seçili mağazanın yetkili kullanıcıları için güncellensin mi?');
 if(/^\/management\/users\/\d+\/password$/.test(route))return i18nT('Bu kullanıcının şifresi değiştirilsin mi? Açık oturumları kapanacak.');
 if(route==='/account/initial-password')return i18nT('Yeni şifrenizi kaydetmek istiyor musunuz? Sonrasında yeni şifrenizle giriş yapın.');
 if(route.startsWith('/management/'))return route.endsWith('/sessions')?i18nT('Bu kullanıcının açık oturumlarını kapatmak istiyor musunuz?'):route.endsWith('/reset')?i18nT('Şifre yenileme e-postasını göndermek istiyor musunuz?'):i18nT('Müşteri bilgilerini kaydetmek istiyor musunuz?');
 if(/^\/records\//.test(route))return route.startsWith('/records/payments')?i18nT('Hakedişi kaydetmek istiyor musunuz? Değişiklikler İş Ortağım’da görünür.'):i18nT('Kaydı kaydetmek istiyor musunuz?');
 if(/\/correction$|\/commit$/.test(route))return i18nT('Yaptığınız düzeltmeleri kaydetmek istiyor musunuz?');
 if(/\/documents\/|\/attachments$/.test(route))return i18nT('Seçtiğiniz dosyayı eklemek istiyor musunuz? Yetkili kullanıcılar dosyayı görüntüleyebilir ve indirebilir.');
 if(/^\/support\/tickets/.test(route)||/\/inbox\/threads\/\d+\/reply$/.test(route))return route.endsWith('/reply')?i18nT('Mesajı göndermek istiyor musunuz?'):route.endsWith('/status')?i18nT('Destek talebinin durumunu değiştirmek istiyor musunuz?'):i18nT('Destek talebini oluşturmak istiyor musunuz?');
 if(route==='/account/navigation')return i18nT('Değişiklikleri kaydetmek istiyor musunuz? Menü sırası bu cihazdaki hesabınıza uygulanır.');
 if(route==='/account/password/request')return i18nT('Şifre değişikliğini kaydetmek istiyor musunuz? İşlem e-posta doğrulamasıyla tamamlanır.');
 if(route==='/password/reset')return i18nT('Yeni şifrenizi kaydetmek istiyor musunuz?');
 if(route==='/account/username')return i18nT('Kullanıcı adınızı kaydetmek istiyor musunuz? E-posta, şifre ve mağaza bağlantılarınız değişmez.');
 if(route==='/account/preferences')return i18nT('Değişiklikleri kaydetmek istiyor musunuz?');
 if(route==='/account/security/email/request')return i18nT('Güvenlik ayarındaki değişikliği kaydetmek istiyor musunuz? İşlem e-posta doğrulamasıyla tamamlanır.');
 if(route==='/account/session')return data.keepSession?i18nT('Bu cihazda oturumunuzu siz kapatana kadar açık tutmak istiyor musunuz?'):i18nT('Oturumu açık tut seçeneği kapatılacak ve bu cihazdan çıkış yapılacak. Devam etmek istiyor musunuz?');
 return null;
}
export async function confirmMutation(path,method,data){
 const message=mutationPrompt(path,method,data);if(!message||typeof document==='undefined')return;
 const company=new URL(path,'https://local.invalid').searchParams.get('company')||data?.companyId;
 const captured=context;
 const scope=company&&Number(company)===captured.id?i18nText`Müşteri: ${captured.name}`:'';
 if(!await confirmAction(message,{scope})){const error=new Error(i18nT('İşlem iptal edildi. Değişiklik yapılmadı.'));error.cancelled=true;throw error;}
 if(company&&Number(company)===captured.id&&context!==captured)throw new Error(i18nT('Müşteri değişti. İşlemi ilgili müşteri ekranından yeniden başlatın.'));
}
export function confirmAction(message,{heading=i18nT('İşlemi onaylayın'),approveLabel=i18nT('Evet, onaylıyorum'),scope='',focusApprove=false}={}){
 return new Promise(resolve=>{
  const dialog=document.createElement('dialog');dialog.className='action-confirm-dialog';dialog.setAttribute('aria-label',heading);
  const title=document.createElement('h2');title.textContent=heading;
  const detail=document.createElement('p');detail.className='form-help';detail.textContent=scope;
  const text=document.createElement('p');text.textContent=message;
  const actions=document.createElement('div');actions.className='dialog-actions';
  const cancel=document.createElement('button');cancel.type='button';cancel.className='btn';cancel.textContent=i18nT('Vazgeç');cancel.onclick=()=>dialog.close('cancel');
  const approve=document.createElement('button');approve.type='button';approve.className='btn primary';approve.textContent=approveLabel;approve.onclick=()=>dialog.close('approve');
  actions.append(cancel,approve);dialog.append(title,detail,text,actions);document.body.append(dialog);
  dialog.addEventListener('close',()=>{const approved=dialog.returnValue==='approve';dialog.remove();resolve(approved);},{once:true});
  if(focusApprove)dialog.addEventListener('keydown',event=>{
   if(event.key==='Enter'&&(event.repeat||event.isComposing))event.preventDefault();
  });
  dialog.showModal();(focusApprove?approve:cancel).focus();
 });
}
