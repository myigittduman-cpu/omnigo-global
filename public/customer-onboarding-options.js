import {html,t} from './i18n.js';
import {esc} from './ui.js';
import {sectionEnabled} from './customer-section-audience.js';

const liveScreens=[
 ['orders','Siparişler Ekranı','Bağlı pazaryerlerindeki siparişleri, ürün satırlarını ve sipariş durumlarını gösterir. Satış raporları da bu kayıtlardan beslenir.'],
 ['inventory','Stok Yönetimi Ekranı','Desteklenen bağlantılardan gelen ürünleri, stok adetlerini ve satış durumlarını gösterir. Mağaza stoğuyla ilişkisini seçtiğiniz stok modeli belirler.'],
 ['inbox','Pazaryeri Soruları Ekranı','Desteklenen pazaryerlerinden gelen ürün sorularını ve yanıt durumlarını gösterir. Yanıt gönderme, ayrı bir çalışma seçeneği ve işlem iznidir.'],
 ['returns','İptal ve İade Talepleri Ekranı','Bağlı pazaryerlerinden doğrulanabilen iptal ve iade taleplerini Omnigo yöneticilerine gösterir.'],
 ['payments','Hakedişler ve Yaklaşan Ödemeler Ekranı','Hepsiburada, n11 ve Pazarama bağlantıları ödeme tarihi ile tutarı sağladığında yaklaşan ödemeleri gösterir. Diğer kayıtlar için mağazada etkin olan manuel işlemler kullanılır.'],
 ['shipments','Şehir Dışı Sevkiyat takip ekranı','Mağazanın sevkiyat kapsamına giren siparişleri ve sevk durumlarını takip eder. İlk aktarım tarihi listeye alınacak başlangıç dönemini belirler.'],
 ['deliveries','Şehir Dışı Teslimat takip ekranı','Sevkiyata alınan ürünleri ve teslimat durumlarını sipariş ile sevkiyat kayıtlarından takip eder.'],
 ['cityProducts','Şehir Dışı Ürünler Ekranı','Seçili sevkiyat illerindeki siparişleri teslim alacak kişi, telefon, teslimat adresi, ürün ve adet bilgileriyle gösterir. Kullanıcı için ayrıca görüntüleme yetkisi verilir.'],
 ['shipmentInvoices','Yüklenecek Faturalar ekranı','Faturalandırılabilecek siparişleri iş listesine getirir. Fatura belgesi yüklemek için manuel Fatura yükleme seçeneği kullanılır.'],
 ['fileCenter','Dosya Merkezi Ekranı','Stok Yönetimi kaynaklı pazaryeri ürünlerini, yayın fiyatlarını ve satış durumlarını gösterir. Hazırlanan dosyaların arşivi ayrıca erişilebilir.'],
];

const manualScreens=[
 ['payoutEstimates','PTT AVM, idefix, Koçtaş ve Trendyol tahmini hakedişleri','Beklenen ödeme tarihini ve tahmini net tutarı mağazanın hakediş takvimine kaydeder. Trendyol için mağazanın bağlantısı kurulmuş olmalıdır.'],
 ['payoutReceipts','Hesaba geçen ödemelerin kaydı ve onayı','Pazaryerinden gelen ödemenin hesaba geçtiğini kaydetmek ve yetkili kullanıcı tarafından onaylamak için kullanılır.'],
 ['invoiceUpload','Fatura yükleme','Siparişe ait fatura belgesini yetkiniz olan kayıtla ilişkilendirir.'],
 ['deliveryDocumentUpload','Sipariş belgesi yükleme','Siparişler bölümünde yetkiniz olan siparişin belge arşivine dosya eklemenizi sağlar.'],
 ['shipmentEntry','Elle sevkiyat ekleme','Otomatik listede yer almayan uygun sevkiyat kayıtlarını yetkili kullanıcı ekleyebilir.'],
 ['operationCorrections','Operasyon düzeltmeleri','Yetkiniz olan operasyon kayıtlarında durum ve kayıt düzeltmeleri yapmanızı sağlar.'],
 ['storeStock','Mağaza stoğu','Fiziksel mağaza stoğuna miktar girişi yapmanızı sağlar. Pazaryeri stoğuyla ilişkisini seçilen stok modeli belirler.'],
 ['stockCount','Stok sayımı','Fiilî sayım sonucu belirlenen stok miktarını yetkili kullanıcı kaydedebilir.'],
 ['storeSalesReturns','Mağaza satışı ve iadesi','Mağazada yapılan satış ve iadeleri kaydeder. Stok etkisi seçilen modele göre işlenir.'],
 ['purchaseCost','Alış maliyeti','Ürünün alış maliyetini girmenizi sağlar. Kârlılık hesabı bu maliyeti kullanır.'],
 ['commissionRates','Tarihli komisyon oranı','Siparişte komisyon doğrulanamadığında kârlılık hesabı için geçerlilik tarihi olan bir oran kaydetmenizi sağlar.'],
 ['questionReply','Soruya yanıt yazma','Desteklenen pazaryerindeki soruya portal üzerinden yanıt göndermenizi sağlar.'],
 ['replyTemplates','Hazır yanıt seçimi','Soru yanıtına eklemek üzere kayıtlı bir yanıt metnini seçmenizi sağlar. Son metni göndermeden önce düzenleyebilirsiniz.'],
 ['support','Destek talepleri','Yetkili mağaza kullanıcılarının Omnigo ile destek talepleri üzerinden iletişim kurmasını sağlar.'],
 ['workNotes','Çalışma notları','Mağazanın işleri için portalda çalışma notu oluşturup takip etmenizi sağlar.'],
 ['manualReports','Elle hazırlanan raporlar','Mağazanın elle hazırladığı raporları yetkili kullanıcıların erişebileceği şekilde kaydeder.'],
];

const fixedSections=[
 ['Bağlantı Kontrolü','Mağazanın API bağlantıları ve aktarım durumları.'],
 ['Hazırlanan Dosyalar','Yetkili kullanıcının oluşturduğu rapor ve çıktılara erişim.'],
 ['Kurulum tercihleri','Mağazaya ait ekran seçimleri, stok modeli ve çalışma ayarları.'],
];

export function setupOptionInfo(label,description,controlLabel=label){
 return html`<details class="customer-setup-option-info">
  <summary aria-label="${esc(t(controlLabel)+' '+t('hakkında bilgi'))}"><span aria-hidden="true">i</span></summary>
  <div class="customer-setup-option-description">
   <strong data-setup-info-title>${esc(t(label))}</strong>
   <p data-setup-info-description>${esc(t(description))}</p>
  </div>
 </details>`;
}

function choice(name,[key,label,description],checked){
 return html`<div class="store-setup-choice">
  <label class="customer-setup-option-text">
   <input type="checkbox" name="${name}" value="${key}" ${checked?'checked':''}>
   <strong>${esc(t(label))}</strong>
  </label>
  ${setupOptionInfo(label,description)}
 </div>`;
}

export function onboardingOptions(configuration){
 const saved=configuration.setupOptions;
 return html`<fieldset class="customer-module-choices store-setup-options full-width">
  <legend>Canlı Ekran Seçimi</legend>
  <p class="form-help">Mağazada kullanılacak canlı aktarım ekranlarını seçin. Veriler ilgili pazaryerinin API desteğine ve bağlantı durumuna göre gelir.</p>
  <div class="store-setup-grid">${liveScreens.map(entry=>choice('liveSetupScreen',entry,
   saved?saved.live.includes(entry[0]):sectionEnabled(entry[0],configuration.pages[entry[0]]??'admin'))).join('')}</div>
 </fieldset>
 <fieldset class="customer-module-choices store-setup-options full-width">
  <legend>Manuel Ekran Seçimi</legend>
  <p class="form-help">Mağazada elle yürütülecek işlemleri seçin. Kullanıcı erişimi mevcut rol ve işlem izinlerine bağlıdır.</p>
  <div class="store-setup-grid">${manualScreens.map(entry=>choice('manualSetupScreen',entry,
   saved?saved.manual.includes(entry[0]):true)).join('')}</div>
 </fieldset>
 <fieldset class="customer-module-choices store-setup-options full-width">
  <legend>Her mağazada hazır bulunan bölümler</legend>
  <dl class="store-setup-fixed">${fixedSections.map(([label,description])=>html`<div>
   <dt>${esc(t(label))}</dt><dd>${esc(t(description))}</dd>
  </div>`).join('')}</dl>
 </fieldset>`;
}

export function selectedOnboardingOptions(data){
 return {live:data.getAll('liveSetupScreen'),manual:data.getAll('manualSetupScreen')};
}
