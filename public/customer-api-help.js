import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
export const setupProviders=[
 {key:'hepsiburada',name:'Hepsiburada',fields:[['merchantId','Merchant ID','text',36],['username','API kullanıcı adı','text',200],['serviceKey','Servis anahtarı / API şifresi','password',2048],['userAgent','Entegratör adı (User-Agent)','text',200]]},
 {key:'n11',name:'n11',fields:[['apiKey','API anahtarı (appKey)','password',2048],['apiSecret','API şifresi (appSecret)','password',2048]]},
 {key:'idefix',name:'idefix',fields:[['vendorId','Satıcı numarası (Vendor ID)','text',16],['apiKey','API Key','password',4096],['apiSecret','API Secret Key','password',4096]]},
 {key:'pttavm',name:'PTT AVM',fields:[['apiKey','API Key','password',4096],['accessToken','Access Token','password',4096]]},
 {key:'pazarama',name:'Pazarama',fields:[['sellerId','Satıcı ID','text',36],['apiKey','API Key','password',2048],['apiSecret','API Secret','password',2048]]},
 {key:'koctas',name:'Koçtaş',fields:[['shopId','Mağaza numarası (Shop ID)','number',16],['apiKey','API anahtarı','password',36]]},
 {key:'trendyol',name:'Trendyol',fields:[['supplierId','Satıcı numarası (Supplier ID)','number',16],['apiKey','API Key','password',2048],['apiSecret','API Secret','password',2048]]}
];
function requestText(provider,company,storeName,verify){
 const title=verify?'Omnigo entegrasyonu için API yetki kontrolü':'Omnigo entegrasyonu için API erişim talebi';
 const action=verify?'Mevcut API bağlantımızın aşağıdaki servislerin her birinde mağazamız adına işlem yapma yetkisini kontrol etmenizi rica ederiz.':'Aşağıdaki canlı aktarımları Omnigo üzerinden kullanabilmemiz için gerekli API erişimlerinin mağazamıza tanımlanmasını rica ederiz.';
 return `Konu: ${provider.name} – ${title}

Sayın ${provider.name} Satıcı Destek Ekibi,

${company} adına ${storeName||company} mağazamız için yazıyoruz. ${action}

• Siparişler: Geçmiş ve yeni siparişleri, paket ve kalem kimliklerini, SKU, adet, tarih ve durum değişikliklerini okuyabilmek; kayıtları sipariş bazında karşılaştırabilmek.
• Fatura ve teslimat: Alıcı ve fatura bilgilerini, adresleri, fatura türünü, indirimlerden sonra fatura kesilecek tutarı, kargo takip bilgisini ve pakete ait orijinal barkodu veya etiketi alabilmek.
• Ürünler: Aktif ilanları, ürün adlarını, varyantları, görselleri, satış durumunu, stok adetlerini, liste ve satış fiyatlarını okuyabilmek.
• Stok ve fiyat: Aktif ilanlarda stok adedini, satış fiyatını ve gerektiğinde liste fiyatını güncelleyebilmek; her ilanın kabul veya hata sonucunu sorgulayabilmek.
• Müşteri iletişimi: Ürün sorularını ve sipariş mesajlarını okuyabilmek, yanıt gönderebilmek ve yanıtın ulaştığını doğrulayabilmek.
• İptal ve iade: Açık ve sonuçlanmış talepleri, durumlarını, etkilenen ürünleri, adetleri ve tutarları okuyabilmek.
• Finans: Sipariş anındaki mağaza satış fiyatını, satıcı ve pazaryeri indirimlerini, alıcının ödediği tutarı, komisyon oranını, komisyon indirimini, KDV, hizmet, kargo ve diğer kesintileri okuyabilmek. Siparişe bağlı hesaba geçecek tutarı, yaklaşan hakedişleri, ödeme tarihlerini, gerçekleşen ödemeleri ve ekstre hareketlerini eşleştirebilmek.

Her başlık için kullanılacak servisleri, gerekli izinleri, geçmiş veriye erişim sınırını ve istek limitlerini paylaşabilir misiniz? API üzerinden sunulmayan alanları ve varsa resmî alternatiflerini ayrıca belirtmenizi rica ederiz. Erişim bilgilerini güvenli satıcı panelinden edinmek ve mevcut bağlantılarımızı korumak istiyoruz.

Teşekkür ederiz.
${company}`;
}
export function supportTemplate(provider,company,storeName){return requestText(provider,company,storeName,false);}
export function permissionTemplate(provider,company,storeName){return requestText(provider,company,storeName,true);}