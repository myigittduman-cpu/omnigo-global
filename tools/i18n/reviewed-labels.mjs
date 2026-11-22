import {edit} from './reviewed-edits.mjs';
await edit('shipments-view.js',[
 ['html as i18nHtml}', 'html as i18nHtml,labelRows}'],
 ["export const shipmentColumns=[[", "export const shipmentColumns=labelRows([["],
 ["['customerType','Müşteri Tipi']];", "['customerType','Müşteri Tipi']]);"],
 ["' sonuç / '","i18nT(' sonuç / ')"],
 ["' kayıt'","i18nT(' kayıt')"]
]);
await edit('shipment-editor-view.js',[
 ['<option ${value===option?\'selected\':\'\'}>${option}</option>', '<option value="${esc(option)}" ${value===option?\'selected\':\'\'}>${type===\'type\'?i18nT(option):option}</option>'],
 ["editor.saving?'Kaydediliyor…'","editor.saving?i18nT('Kaydediliyor…')"]
]);
await edit('customer-setup-view.js',[
 ['<label>${label}<input','<label>${i18nT(label)}<input'],
 ["i18nT('Tanımlı mağaza'):'Ekle'","i18nT('Tanımlı mağaza'):i18nT('Ekle')"]
]);
await edit('customer-api-help.js',[
 ['provider.fields.map(([,label])=>label)', 'provider.fields.map(([,label])=>i18nT(label))']
]);
await edit('customer-setup-connections.js',[
 ["i18nT('API kayıtlı · aktarım durumu son kontrolde'):'API bilgileri bekleniyor'", "i18nT('API kayıtlı · aktarım durumu son kontrolde'):i18nT('API bilgileri bekleniyor')"]
]);
await edit('returns-view.js',[
 [":'Bildirilmedi'", ":i18nT('Bildirilmedi')"],
 ["||'Bildirilmedi'", "||i18nT('Bildirilmedi')"],
 ["||'Kontrol gerekiyor'", "||i18nT('Kontrol gerekiyor')"],
 ["'Sipariş satırı: '+esc(row.lineId)", "i18nText`Sipariş satırı: ${esc(row.lineId)}`"],
 ['esc(row.statusLabel)', 'esc(i18nT(row.statusLabel))'],
 ['<th>${label}</th>', '<th>${i18nT(label)}</th>']
]);
await edit('operation-notification.js',[
 ["event==='cancelled'?'iptal edildi'", "event==='cancelled'?i18nT('iptal edildi')"]
]);
await edit('api.js',[
 ["result.error||i18nT('Dosya yüklenemedi.')", "i18nT(result.error)||i18nT('Dosya yüklenemedi.')"],
 ["result.error||i18nT('İşlem tamamlanamadı.')", "i18nT(result.error)||i18nT('İşlem tamamlanamadı.')"]
]);
await edit('account-deletion.js',[
 ["result.message+' Talep numarası: '+result.requestId", "i18nT(result.message)+i18nText` Talep numarası: ${result.requestId}`"],
 ["error.status===401?'Uygulamaya giriş yaptıktan sonra bu sayfayı tekrar açın veya yukarıdaki e-posta yolunu kullanın.':'Talep ekranına ulaşılamadı. E-posta üzerinden başvurabilirsiniz.'", "error.status===401?i18nT('Uygulamaya giriş yaptıktan sonra bu sayfayı tekrar açın veya yukarıdaki e-posta yolunu kullanın.'):i18nT('Talep ekranına ulaşılamadı. E-posta üzerinden başvurabilirsiniz.')"]
]);
