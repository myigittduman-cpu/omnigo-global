import {labelMap as localizedLabels} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
// Field names checked in the signed-in seller panels on 21 October 2026.
export const paymentSections=localizedLabels({sales:'Satış ve indirimler',commission:'Komisyon',service:'Hizmet ve kargo',tax:'Vergi ve KDV',adjustment:'İade ve diğer kalemler'});
const definitions={
 Hepsiburada:{sales:['Satış tutarı','Kampanya indirimleri','Tahmini toplam gelir'],commission:['Komisyon kesintisi'],service:['Hizmet bedeli','Tahsilat yönetim bedeli'],tax:['E-Ticaret stopajı'],adjustment:['Tahmini toplam gider']},
 n11:{sales:['Satış Tutarı','KDV Hariç Tutar','Mağaza İndirim Tutarı','Mağaza Kupon İndirimi','Net Satış Tutarı'],commission:['Komisyon Tutarı'],service:['Kargo Bedeli','Pazarlama Bedeli','Pazaryeri Bedeli'],tax:['KDV Tutarı','Vergi Kesintisi'],adjustment:['İade Tutarı','Ceza Bedeli','Yurtdışı Operasyon İade Bedeli','Diğer Kesinti','n11 Para Ödemesi']},
 'PTT AVM':{sales:['Satış Tutarı','Platform İndirimi','Kupon'],commission:['Komisyon Kesintisi'],service:['Kargo Bedeli','Ödeme Altyapısı Bedeli','Kargo/Desi Farkı'],tax:['E-ticaret Stopajı'],adjustment:['Ceza Kesintisi','Diğer']},
 idefix:{sales:['Ürün Tutar','Satıcı Kupon + Satıcı İndirim','Platform Kupon + Platform İndirim','Satıcı Tutarı (Ödenecek Olan)'],commission:['Komisyon Tutar'],service:['Kargo faturası','Hizmet bedeli'],tax:['Stopaj'],adjustment:['İade']},
 Pazarama:{sales:['İşlem Tutarı'],commission:['Komisyon Tutarı'],service:['Hizmet Ücreti','Kargo Bedeli','Erken Ödeme Bedeli','Entegrasyon Tutarı'],tax:['Stopaj Tutarı'],adjustment:['Tedarik Edilemedi Bedeli','Geciken Kargo Bedeli','Kusurlu/Ayıplı/Eksik Ürün Gönderimi','Marka Fatura Belge Uyuşmazlığı','Kargo Bilgi Eksikliği','Platform Rekabet İhlali','Eksik Fatura']},
 'Koçtaş':{sales:['Siparişler','Nakliye fiyatı','Sipariş ücretleri'],commission:['Komisyon ücretleri'],service:['Abonelik'],tax:['Vergiler'],adjustment:['Diğer fatura','Diğer kredi notu','Operatöre ödenecek']}
};
export function paymentTemplate(channel){
 const groups=definitions[channel]||{sales:['Satış tutarı'],commission:['Komisyon tutarı'],service:['Hizmet bedeli'],tax:[],adjustment:[]};
 const rows=Object.entries(paymentSections).flatMap(([section])=>(groups[section]||[]).map(label=>({section,label,amountCents:null,rateBasisPoints:null})));
 if(!rows.some(row=>row.label==='KDV Tutarı'))rows.push({section:'tax',label:'KDV Tutarı',amountCents:null,rateBasisPoints:null});
 return rows;
}
export function paymentTemplateNote(channel){
 const shared=i18nT('Paneldeki tutarları ve varsa oranları girin. Boş alanlar kaydedilmez; sıfır yazdığınız alanlar sıfır olarak gösterilir. Farklı oranlar için aynı bölüme ayrı kalem ekleyebilirsiniz. KDV ayrıca gösteriliyorsa KDV alanını doldurun.');
 return shared+(channel==='Pazarama'?i18nT(' Mutabakat raporundaki tutarlar KDV dahil, Faturalar ekranındaki tutarlar KDV hariçtir; aşağıdan kullandığınız kaynağın KDV durumunu seçin.'):channel==='PTT AVM'?i18nT(' Platform indiriminin bedelini platform karşılar; bu bilgi net hakedişten yeniden düşülmez.'):'');
}
