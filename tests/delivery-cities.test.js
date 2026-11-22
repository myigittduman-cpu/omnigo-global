import test from 'node:test';
import assert from 'node:assert/strict';
import {applyDeliveryCities,deliveryCityQuery,deliveryCityLabel,deliveryCityFilter,deliveryCityMatches} from '../public/delivery-city-filter.js';
import {deliveryView} from '../public/delivery-view.js';
import {deliveryFilename} from '../public/delivery-filename.js';
import {refreshedDeliverySelection} from '../public/delivery-live-selection.js';
import {setLanguage} from '../public/i18n.js';
import {createDeliveryWorkbook} from '../public/delivery-workbook.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';

test('Şehir değişiminde önceki sayfa/işaretler temizlenir, güncel uygun siparişler seçilir',()=>{
 const model={city:'Eskişehir',queue:'all',page:2,provider:'Hepsiburada',date:'2026-10-27',selected:new Set([99]),manualSelection:true};
 applyDeliveryCities(model,['Kayseri','Ankara','Kırıkkale']);
 assert.equal(model.city,'all');assert.equal(model.page,0);assert.equal(model.selected.size,0);assert.equal(model.manualSelection,false);
 assert.equal(model.provider,'Hepsiburada');assert.equal(model.date,'2026-10-27');assert.equal(deliveryCityLabel(model),'Ankara, Kırıkkale, Kayseri');
 assert.deepEqual(new URLSearchParams(deliveryCityQuery(model)).getAll('cities[]'),['Ankara','Kırıkkale','Kayseri']);
 const order={id:1,active:true,city:'Ankara',issues:[],products:[]};
 assert.deepEqual([...refreshedDeliverySelection(model,[order,{...order,id:2,active:false},{...order,id:3,issues:['Eksik']}])],[1]);
 const original=structuredClone(model);assert.throws(()=>applyDeliveryCities(model,[]),/En az/);assert.deepEqual(model,original);
});
test('Çoklu il başlığı ve kartlar, boş seçim ve üç dilde API değerleri',()=>{
 const model={city:'all',cities:['Ankara','Kırıkkale','Kayseri'],selected:new Set(),queue:'all',date:'2026-10-27'};
 const html=deliveryView(model);assert.match(html,/<h2>Ankara, Kırıkkale, Kayseri<\/h2>/);
 for(const city of model.cities)assert.match(html,new RegExp(`data-city="${city}" aria-pressed="true"`));
 assert.match(html,/data-city="all" aria-pressed="false"/);assert.match(html,/3 şehir seçili/);
 assert.match(deliveryCityFilter({...model,cityDraft:[],cityFilterError:true}),/En az bir şehir seçin/);
 try{for(const language of ['en','de']){setLanguage(language);const translated=deliveryCityFilter(model);assert.doesNotMatch(translated,/Şehirler|şehir seçili|Birden fazla şehir/);for(const city of model.cities)assert.ok(translated.includes(`value="${city}" checked`));}}finally{setLanguage('tr');}
});
test('Çoklu şehir Excel içeriği ve PDF/Excel dosya adları seçim kapsamını korur',async()=>{
 const cities=['Ankara','Kırıkkale','Kayseri'],sender={name:'Örnek Firma'},context={company:sender,downloadedAt:'2026-10-26T10:00:00Z'};
 for(const type of ['pdf','xlsx'])assert.equal(deliveryFilename(cities,type,context),`Örnek Firma – 26.10.2026 Teslimat Evrakları.${type}`);
 assert.throws(()=>deliveryFilename(['Ankara','../Kayseri'],'pdf',context));
 const orders=cities.map((city,index)=>({provider:'n11',city,orderNumber:'TEST-'+index,recipientName:'Örnek',address:city+' Test Adres',phone:'05000000000',products:[{title:'Ürün',quantity:1}]}));
 const blob=await createDeliveryWorkbook({city:cities.join(', '),cities,sender,orders},{});
 const workbook=new Workbook();await workbook.xlsx.load(await blob.arrayBuffer());
 const values=workbook.getWorksheet('n11').getSheetValues().flat(2);
 for(const order of orders){assert.ok(values.includes(order.orderNumber));assert.ok(values.includes(order.address));}
 assert.ok(values.includes('Teslimat Evrakı · Ankara, Kırıkkale, Kayseri'));
});

test('Tüm Türkiye listesinde arama seçim kapsamını daraltmaz ve bölgesel görünüm değişmez',()=>{
 const model={shippingMode:'nationwide',availableCities:['Ankara','İstanbul','Kahramanmaraş'],city:'all',cityDraft:['Ankara','İstanbul'],citySearch:'istanbul'};
 const view=deliveryCityFilter(model);assert.match(view,/data-city-search/);assert.match(view,/data-city-clear/);assert.match(view,/value="Ankara" checked/);assert.match(view,/data-city-option hidden/);
 assert(deliveryCityMatches('İstanbul','ISTANBUL'));assert(deliveryCityMatches('Kahramanmaraş','marAS'));assert(!deliveryCityMatches('Ankara','istanbul'));
 assert.doesNotMatch(deliveryCityFilter({...model,shippingMode:'regional'}),/data-city-search|data-city-clear/);
 applyDeliveryCities(model,['İstanbul','Ankara']);assert.deepEqual(model.cities,['Ankara','İstanbul']);assert.equal(model.citySearch,'');assert.equal(model.cityFilterOpen,false);
});
