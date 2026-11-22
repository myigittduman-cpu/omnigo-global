import {edit} from './reviewed-edits.mjs';
await edit('account-security.js',[["'Kodun gönderileceği adres: '+result.email","i18nText`Kodun gönderileceği adres: ${result.email}`"]]);
await edit('action-confirmation.js',[["'Müşteri: '+captured.name","i18nText`Müşteri: ${captured.name}`"]]);
await edit('android-install.js',[
 ["release.title+' · Android güncellemesi'","i18nT(release.title)+i18nT(' · Android güncellemesi')"],
 ["status.textContent='Bu bağlantıyı telefonunuzun Chrome veya başka bir tarayıcısında açın.'","status.textContent=i18nT('Bu bağlantıyı telefonunuzun Chrome veya başka bir tarayıcısında açın.')"],
 ["status.textContent='APK indirmesi başlatılıyor. Başlamazsa aşağıdaki indirme düğmesine dokunun.'","status.textContent=i18nT('APK indirmesi başlatılıyor. Başlamazsa aşağıdaki indirme düğmesine dokunun.')"]
]);
await edit('app.js',[["state.user.operatorName+' · Müşteri hesabı'","state.user.operatorName+i18nT(' · Müşteri hesabı')"]]);
await edit('channel-cards.js',[
 ["'Son aktarım: '+stamp.toLocaleString", "i18nT('Son aktarım: ')+stamp.toLocaleString"],
 ["checking:'Kontrol bekleniyor'","checking:i18nT('Kontrol bekleniyor')"],
 ["inactive:'Pazaryeri pasif'","inactive:i18nT('Pazaryeri pasif')"],
 ["||'Kontrol bekleniyor'","||i18nT('Kontrol bekleniyor')"],
 ['esc(h.message)', 'esc(i18nT(h.message))']
]);
await edit('customer-actions.js',[["' · Kayıt '+Number(row.record_id)","i18nText` · Kayıt ${Number(row.record_id)}`"]]);
await edit('customer-marketplaces.js',[["company.name+' · Pazaryeri ayarları'","company.name+i18nT(' · Pazaryeri ayarları')"]]);
await edit('customer-stores.js',[["status.textContent='Kaydediliyor…'","status.textContent=i18nT('Kaydediliyor…')"]]);
await edit('freshness.js',[["' · Son kayıt: '","i18nT(' · Son kayıt: ')"]]);
await edit('live.js',[["'Sunucu kontrolü: '","i18nT('Sunucu kontrolü: ')"]]);
await edit('page-refresh.js',[
 ["'Son yenileme: '","i18nT('Son yenileme: ')"],
 ["+' Yenileniyor…'","+i18nT(' Yenileniyor…')"],
 ["+' Yenile'","+i18nT(' Yenile')"]
]);
await edit('mobile-permission.js',[["'Bildirimler henüz açılmadı. '","i18nT('Bildirimler henüz açılmadı. ')"]]);
await edit('settings-view.js',[
 ["petrol:'Petrol · Turkuaz'","petrol:i18nT('Petrol · Turkuaz')"],
 ["' · İşlem yapan: '+esc(user.operatorName)","i18nText` · İşlem yapan: ${esc(user.operatorName)}`"]
]);
await edit('payment-details.js',[
 ["included:'KDV dahil'","included:i18nT('KDV dahil')"],
 ["unspecified:'Belirtilmedi'","unspecified:i18nT('Belirtilmedi')"],
 ["||'Belirtilmedi'","||i18nT('Belirtilmedi')"],
 [":'Belirtilmedi'",":i18nT('Belirtilmedi')"],
 ["?'Banka teyidi bekleniyor'","?i18nT('Banka teyidi bekleniyor')"]
]);
await edit('returns-view.js',[["row.amountCents==null?'Bildirilmedi'","row.amountCents==null?i18nT('Bildirilmedi')"]]);
await edit('reporting.js',[["'Rapor hazırlanamadı: '","i18nT('Rapor hazırlanamadı: ')"]]);
await edit('shipment-bulk.js',[["result.updated+' satır güncellendi.'","i18nText`${result.updated} satır güncellendi.`"]]);
await edit('shipment-fulfillment.js',[["esc(row.fulfillmentStatusText||'Sevk bekliyor')","esc(i18nT(row.fulfillmentStatusText||'Sevk bekliyor'))"]]);
await edit('order-pricing.js',[["<dt>${label}</dt>","<dt>${i18nT(label)}</dt>"]]);
