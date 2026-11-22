import test from 'node:test';
import assert from 'node:assert/strict';
import {documentFilename,documentDownloadDate} from '../public/document-filename.js';
import {deliveryFilename} from '../public/delivery-filename.js';
import {shipmentExportFilename} from '../public/shipment-export.js';

const context={company:{name:'Vizyon Beyaz Eşya'},provider:'Hepsiburada',downloadedAt:'2026-10-18T21:00:00Z'};
test('Firma ve Türkiye indirme günü PDF ve Excel adlarına taşınır',()=>{
 for(const type of ['pdf','xlsx']){
  assert.equal(deliveryFilename('Ankara',type,context),`Vizyona – 19.10.2026 Teslimat Evrakları.${type}`);
  const data={...context,city:'Eskişehir',month:'2026-06',deliveryDate:'2026-11-01'};
  assert.equal(shipmentExportFilename(data,type,context.downloadedAt),`Vizyona – 19.10.2026 Şehir Dışı Sevkiyat Listesi.${type}`);
  assert.equal(documentFilename({...context,city:'all',provider:'all',kind:'shipment',type}),`Vizyona – 19.10.2026 Şehir Dışı Sevkiyat Listesi.${type}`);
 }
 assert.equal(documentDownloadDate('2026-10-18T20:59:59Z'),'18.10.2026');
 assert.equal(documentDownloadDate('2026-12-31T21:00:00Z'),'01.01.2027');
});
test('Tüm panel seçimi yalnız bir panelin siparişleriyle daraltılmaz; başka firmaya Vizyona yazılmaz',()=>{
 assert.equal(deliveryFilename('Kırıkkale','pdf',{...context,provider:'all',orders:[{provider:'n11'}]}),'Vizyona – 19.10.2026 Teslimat Evrakları.pdf');
 assert.equal(deliveryFilename('Ankara','xlsx',{...context,company:{name:'Örnek Mağaza'}}),'Örnek Mağaza – 19.10.2026 Teslimat Evrakları.xlsx');
 assert.equal(shipmentExportFilename({...context,city:'all'},'pdf',context.downloadedAt),'Vizyona – 19.10.2026 Şehir Dışı Sevkiyat Listesi.pdf');
});
test('Dosya adı yol oluşturmaz, firma eksikse yanıltıcı ad üretilmez',()=>{
 const filename=deliveryFilename('Ankara','xlsx',{...context,company:{name:'../Yeni: Firma\\Şube?'}});
 assert.doesNotMatch(filename,/[<>:"/\\|?*]/);
 assert.throws(()=>deliveryFilename('Ankara','pdf',{provider:'all'}),/firması/);
 assert.throws(()=>deliveryFilename('Ankara','exe',context));
 assert.throws(()=>documentDownloadDate('geçersiz'));
 assert.ok(Buffer.byteLength(deliveryFilename('Eskişehir','xlsx',{...context,company:{name:'Ş'.repeat(200)}}))<=240);
});
