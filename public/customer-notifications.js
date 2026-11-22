import {html,t} from './i18n.js';
import {customerOrderOptions} from './customer-order-notifications.js';
import {sectionAllowsRole,sectionHasStoreRole} from './customer-section-audience.js';

export const notificationOptions={
 newOrder:['Yeni siparişler',[],'Bu mağazaya yeni bir pazaryeri siparişi geldiğinde Omnigo yöneticilerine bildirim gönderir.'],
 cancellation:['İptal bildirimleri',['orders'],'İptal talepleri ve gerçekleşen iptaller için genel seçimi yönetir.'],
 orderCancellationRequests:['Yeni iptal talebi',['orders'],'Pazaryerinden sipariş için iptal talebi geldiğinde bildirim gönderir.'],
 orderCancelled:['Sipariş iptal edildi',['orders'],'Pazaryerindeki sipariş iptali sisteme işlendiğinde bildirim gönderir.'],
 returns:['İade talepleri',['returns'],'Yeni iade taleplerini ve kayıtlı iade taleplerinin durum değişikliklerini bildirir.'],
 stock:['Stok bildirimleri',['inventory'],'Stok tükenmesi, yeniden stoğa giriş, stok düzeltmesi ve alış fiyatı bildirimlerini yönetir.'],
 stockDepleted:['Stok tükendi',['inventory'],'Takip edilen ürünün stoğu sıfıra düştüğünde bildirim gönderir.'],
 stockRestocked:['Stok yeniden geldi',['inventory'],'Tükenmiş ürünün doğrulanmış toplam stoğu yeniden pozitif olduğunda bildirir.'],
 stockAdjusted:['Stok düzeltmesi ve farkı',['inventory'],'Ortak stok düzeltildiğinde veya pazaryeri ile Omnigo miktarları arasında fark bildirildiğinde haber verir.'],
 stockPurchasePrice:['Alış fiyatı güncellendi',['inventory'],'Ürünün alış fiyatı güncellendiğinde bildirim gönderir.'],
 documents:['Fatura ve teslimat evrağı işlemleri',['orders'],'Sipariş kayıtlarına eklenen veya sipariş kayıtlarından kaldırılan fatura ve teslimat evraklarını bildirir.'],
 transfer:['Siparişin operasyon listesine aktarılması',['shipments','deliveries'],'Siparişin sevkiyat veya teslimat işlem listesine alınmasını bildirir.'],
 shipment:['Sevkiyat değişiklikleri',['shipments'],'Sevkiyat kayıtlarındaki işlemleri, sevk onaylarını ve sevkiyata bağlı belge işlemlerini bildirir.'],
 payments:['Hakediş takvimi güncellemeleri',['payments'],'Omnigo’nun hakediş takvimindeki kayıt ekleme, düzeltme ve silme işlemlerini bildirir.'],
 support:['Destek bildirimleri',['support'],'Destek talebi açıldığında veya kapatıldığında Omnigo’ya, talep sonucu ilgili mağaza kullanıcısına bildirilir.'],
 users:['Kullanıcı işlemleri',['support'],'Mağaza kullanıcılarının hesap ve erişim işlemlerini bildirir.'],
 ...customerOrderOptions,
};

const groups=[
 ['Siparişler',['newOrder','cancellation','orderCancellationRequests','orderCancelled']],
 ['İptal & İade Talepleri',['returns']],
 ['Stok Yönetimi',['stock','stockDepleted','stockRestocked','stockAdjusted','stockPurchasePrice']],
 ['Faturalar',['documents']],
 ['Şehir Dışı Sevkiyat ve Teslimatlar',['transfer','shipment','shipmentAdded','deliveryAdded']],
 ['Hakedişlerim',['payments']],
 ['Destek ve kullanıcı işlemleri',['support','users']],
];
const parentSettings={orderCancellationRequests:'cancellation',orderCancelled:'cancellation',
 stockDepleted:'stock',stockRestocked:'stock',stockAdjusted:'stock',stockPurchasePrice:'stock'};
const customerOnly=key=>Object.hasOwn(customerOrderOptions,key);
const enabled=(configuration,key)=>Object.hasOwn(customerOrderOptions,key)?configuration?.notifications?.[key]===true:configuration?.notifications?.[key]!==false;

export function notificationAvailable(state,key){
 const access=state.data?.workspaceAccess,[,pages]=notificationOptions[key]||[];
 if(!pages)return false;
 if(!pages.length)return state.user?.role==='admin';
 if(state.user?.role==='client'&&!customerOnly(key)&&!['payments','support'].includes(key))return false;
 if(state.user?.role==='admin'&&customerOnly(key))return false;
 return pages.some(page=>access?access.pages?.[page]==='shown'&&access.grants?.[page]?.view===true:state.user?.role==='admin'||page==='support');
}
export function notificationEnabled(state,key){
 const configuration=state.data?.workspaceAccess,parent=parentSettings[key];
 return notificationAvailable(state,key)&&enabled(configuration,key)&&(!parent||enabled(configuration,parent));
}

function notificationSetting(configuration,key){
 const [label,pages,help]=notificationOptions[key];
 const recipients=[];
 if(!customerOnly(key)&&(!pages.length||pages.some(page=>sectionAllowsRole(page,configuration.pages?.[page],'admin')))){
  recipients.push(t('Omnigo operasyon ekibi'));
 }
 if((customerOnly(key)||['payments','support'].includes(key))&&pages.some(page=>sectionHasStoreRole(page,configuration.pages?.[page]))){
  recipients.push(t('Yetkili mağaza kullanıcıları'));
 }
 const id=`permission-notification-${key}`;
 return html`<label><input type="checkbox" data-customer-notification="${key}" aria-labelledby="${id}-label" aria-describedby="${id}-help ${id}-recipient"
  ${enabled(configuration,key)?'checked':''} ${recipients.length?'':'disabled'}>
  <span><span id="${id}-label">${t(label)}</span><small id="${id}-help" class="permission-help">${t(help)}</small>
   <small id="${id}-recipient" class="permission-notification-recipient">${recipients.join(' · ')||t('İlgili sayfa erişime açıldığında uygulanır.')}</small>
  </span></label>`;
}

export function customerNotificationSettings(configuration){
 return html`<section class="permission-notification-settings" aria-label="Uygulama bildirimleri">
  <p class="form-help">Yeni siparişleri Omnigo yöneticileri alır. Diğer bildirimler ilgili sayfayı görebilen kullanıcılara gider. Stok ve iptal bildirimlerinde ayrıntı seçimleri için ilgili grubun açık olması gerekir.</p>
  ${groups.map(([label,keys])=>html`<fieldset class="permission-card permission-notification-area">
   <legend>${t(label)}</legend><div class="permission-notification-grid">${keys.map(key=>notificationSetting(configuration,key)).join('')}</div>
  </fieldset>`).join('')}
 </section>`;
}
