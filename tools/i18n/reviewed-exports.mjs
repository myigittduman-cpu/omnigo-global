import {edit} from './reviewed-edits.mjs';
await edit('report-export-data.js',[
 ["head:[i18nT('Pazaryeri'),i18nT('Gün'),amountLabel,i18nT('Sipariş'),'Kapsam'", "head:[i18nT('Pazaryeri'),i18nT('Gün'),amountLabel,i18nT('Sipariş'),i18nT('Kapsam')"],
 ["d.netCents==null?'Veri yok'", "d.netCents==null?i18nT('Veri yok')"],
 ["i18nT('Brüt adet'),'Net adet'", "i18nT('Brüt adet'),i18nT('Net adet')"],
 ["i18nT('İade (TL)'),'Net (TL)'", "i18nT('İade (TL)'),i18nT('Net (TL)')"],
 ["i18nT('Pazaryeri'),'Planlanan tarih','Beklenen (TL)'", "i18nT('Pazaryeri'),i18nT('Planlanan tarih'),i18nT('Beklenen (TL)')"],
 ["i18nT('Gerçekleşen (TL)'),'Kaynak / teyit'", "i18nT('Gerçekleşen (TL)'),i18nT('Kaynak / teyit')"],
 ["head:['Bilgi'", "head:[i18nT('Bilgi')"],
 ["rows:[['Firma'", "rows:[[i18nT('Firma')"],
 ["['Not',n]", "[i18nT('Not'),n]"],
 ["s.channel+' son aktarım'", "i18nText`${s.channel} son aktarım`"],
 ["c+': otomatik hakediş verisi henüz yok; güncel hakediş toplama dahil değil.'", "i18nText`${c}: otomatik hakediş verisi henüz yok; güncel hakediş toplama dahil değil.`"]
]);
await edit('report-xlsx.js',[
 ...['Açıklama','Ürün','Gösterge','Bilgi','Durum','Ürün kodu','Veri kapsamı','Oluşturulma','Sipariş sayısı'].map(label=>[`==='${label}'`,`===i18nT('${label}')`]),
 ["label.includes('Kaynak')", "label.includes(i18nT('Kaynak'))"],
 ["i18nT('Gün'),'Planlanan tarih'", "i18nT('Gün'),i18nT('Planlanan tarih')"],
 ["i18nT('Brüt adet'),'Net adet'", "i18nT('Brüt adet'),i18nT('Net adet')"],
 ["' · Karşılaştırma: '", "i18nT(' · Karşılaştırma: ')"]
]);
await edit('report-pdf.js',[
 ["row[0]!=='Sipariş sayısı'","row[0]!==i18nT('Sipariş sayısı')"],
 ["' · Kaynak ve kapsam son bölümde belirtilmiştir.'","i18nT(' · Kaynak ve kapsam son bölümde belirtilmiştir.')"]
]);
await edit('shipment-export.js',[["' · Şehir Dışı Sevkiyat Listesi'","i18nT(' · Şehir Dışı Sevkiyat Listesi')"]]);
await edit('shipment-workbook.js',[
 ["workbook.addWorksheet('Sevkiyatlar')", "workbook.addWorksheet(i18nT('Sevkiyatlar'))"],
 ["'Sipariş sahibi bildirilmedi; alıcı: '+(item[key]||'')", "i18nText`Sipariş sahibi bildirilmedi; alıcı: ${item[key]||''}`"],
 ["' · Sipariş numarası olmayan '+data.summary.unmatchedRows+' satır ayrı sayıldı.'", "i18nText` · Sipariş numarası olmayan ${data.summary.unmatchedRows} satır ayrı sayıldı.`"]
]);
await edit('delivery-workbook.js',[["'Teslimat Evrakı · '", "i18nT('Teslimat Evrakı · ')"]]);
