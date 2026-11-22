import test from 'node:test';
import assert from 'node:assert/strict';
import {t,text,html,setLanguage,currentLanguage} from '../public/i18n.js';
import {settingsContent} from '../public/settings-view.js';
import {paymentTemplate} from '../public/payment-templates.js';
import {shipmentEditorRow} from '../public/shipment-editor-view.js';
import {esc} from '../public/ui.js';
import {displayStatus} from '../public/status-label.js';
import {orderNotificationPresentation,desktopNotificationPresentation} from '../public/order-alert-model.js';
import {reportSupportError} from '../public/report-file-action.js';
import {availableShipmentMonths} from '../public/shipment-calendar.js';
import {shipmentColumns} from '../public/shipments-view.js';
import {shipmentExportColumns} from '../public/shipment-export-fields.js';
import {menuDescriptions} from '../public/menu-descriptions.js';

test('Dil: kaynak etiketleri çevrilir, müşteri içeriği çevrilmez ve güvenli kaçış korunur',()=>{
 try{for(const language of ['en','de']){
  setLanguage(language);const customer='Kaydet <script>alert(1)</script>';
  const view=html`<section><h2>Ayarlar</h2><strong>${esc(customer)}</strong><input title="${esc(customer)}" placeholder="Ad, telefon, ürün veya adres…"></section>`;
  assert.ok(view.includes(esc(customer)));assert.ok(!view.includes('<script>'));assert.ok(!view.includes('>Ayarlar<'));assert.ok(!view.includes('placeholder="Ad, telefon'));
  assert.equal(text`${7} sipariş`,language==='en'?'7 orders':'7 Bestellungen');
 }}finally{setLanguage('tr');}
});
test('Dil: örtük option değerleri ve yer tutucular kayıpsız korunur',()=>{
 try{setLanguage('de');
  assert.equal(html`<option>Kaydet</option>`,'<option value="Kaydet">Speichern</option>');
  const value='Kaydet &amp; müşteri';assert.equal(html`<option>${value}</option>`,`<option value="${value}">${value}</option>`);
  assert.equal(html`<option value="paid">Hesaba geçti</option>`,'<option value="paid">Auf dem Konto eingegangen</option>');
  assert.equal(html`<p>${'{0} Müşteri'}</p>`,'<p>{0} Müşteri</p>');
  setLanguage('tr');assert.equal(html`<p>Kaydet ${'A &amp; B'}</p>`,'<p>Kaydet A &amp; B</p>');
 }finally{setLanguage('tr');}
});
test('Dil: hakediş taslakları ve sevkiyat formu kayıt değerleri üç dilde aynıdır',()=>{
 const markets=['Hepsiburada','n11','PTT AVM','idefix','Pazarama','Koçtaş','Trendyol'];
 setLanguage('tr');const templates=markets.map(paymentTemplate);
 const editor={id:1,values:{customerName:'Kaydet',recipientName:'Sil',productName:'A & B',customerType:'Kurumsal',quantity:2,invoiceAmount:'20300,10',orderedAt:'2026-10-25T10:20:00'}};
 const fields=markup=>[...markup.matchAll(/<input\b[^>]*>/g)].map(match=>match[0].match(/\b(?:name|type|value)="[^"]*"/g));
 const original=fields(shipmentEditorRow(editor,'2026-10',['Ankara']));
 try{for(const language of ['en','de']){setLanguage(language);assert.deepEqual(markets.map(paymentTemplate),templates);const view=shipmentEditorRow(editor,'2026-10',['Ankara']);assert.deepEqual(fields(view),original);assert.ok(view.includes('value="Kurumsal"'));assert.ok(view.includes('value="Kaydet"'));}}
 finally{setLanguage('tr');}
});
test('Dil: ayarlarda üç sabit seçenek vardır; geçersiz dil Türkçeye döner',()=>{
 try{for(const language of ['tr','en','de']){setLanguage(language);const markup=settingsContent('language',{role:'client'},{language});for(const value of ['tr','en','de'])assert.ok(markup.includes(`value="${value}"`));assert.ok(markup.includes(`value="${language}" selected`));}setLanguage('../../unsafe');assert.equal(currentLanguage(),'tr');assert.equal(t('Kaydet'),'Kaydet');}
 finally{setLanguage('tr');}
});
test('Dil: sipariş durumları ve bildirim tutarı çevrilirken kaynak kayıt ve işlem kodu korunur',()=>{
 const order={company:'Örnek Türkçe Firma',provider:'hepsiburada',type:'order',count:1,amountCents:2029910};const original=structuredClone(order);
 try{for(const lang of ['en','de']){setLanguage(lang);assert.notEqual(displayStatus('Hazırlanıyor / Kargolandı'),'Hazırlanıyor / Kargolandı');const notice=orderNotificationPresentation(order);assert.ok(notice.body.includes(order.company));assert.ok(notice.body.includes(lang==='de'?'20.299,10':'20,299.10'));assert.deepEqual(order,original);assert.equal(reportSupportError({navigator:{}},'paylaşım').message,t('Bu tarayıcı dosya paylaşımını desteklemiyor. Safari veya Chrome’un güncel sürümünden açın.'));}}
 finally{setLanguage('tr');}
});
test('Dil: Hesap başlığı hesaplama bağlamıyla karışmaz; Array.map çeviri bağlamı sayılmaz',()=>{
 try{setLanguage('de');assert.equal(t('Hesap','account'),'Konto');assert.equal(t('Hesap'),'Berechnung');assert.deepEqual(['Kaydet','İptal edildi'].map(t),['Speichern','Storniert']);}finally{setLanguage('tr');}
});
test('Dil: kurulu Windows uygulamasının kimliği korunur, bildirim gövdesi seçilen dildedir',()=>{
 const event={company:'Örnek Firma',provider:'n11',type:'order',count:1,amountCents:12345};
 try{for(const lang of ['tr','en','de']){setLanguage(lang);const native=desktopNotificationPresentation(event);assert.equal(native.title,'OG Yönetici Portalı');assert.equal(native.body,orderNotificationPresentation(event).body);}}
 finally{setLanguage('tr');}
});

test('Dil: ay ve tablo başlıkları çevrilir; ay anahtarları ve sütun sırası korunur',()=>{
 setLanguage('tr');const months=availableShipmentMonths(new Date('2026-10-25T00:00:00Z')),keys=months.map(m=>m.value),columns=shipmentColumns.map(([key])=>key),exports=shipmentExportColumns.map(([key])=>key);
 try{for(const lang of ['en','de']){
  setLanguage(lang);assert.deepEqual(months.map(m=>m.value),keys);assert.deepEqual(shipmentColumns.map(([key])=>key),columns);assert.deepEqual(shipmentExportColumns.map(([key])=>key),exports);
  assert.equal(months.find(m=>m.value==='2026-10').label,lang==='en'?'October 2026':'Oktober 2026');
  for(const label of ['Tel No','Teslimat Adresi','Fatura Adresi',menuDescriptions.returns])assert.notEqual(t(label),label);
 }}finally{setLanguage('tr');}
});
