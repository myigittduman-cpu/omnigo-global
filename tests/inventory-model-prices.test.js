import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryView} from '../public/inventory-view.js';
import {inventoryStockForm,stockPreview} from '../public/inventory-stock-form.js';
import {inventoryPriceCell} from '../public/inventory-price-view.js';
import {inventoryNotificationDetail,inventoryNotificationTitle} from '../public/inventory-notification.js';
import {inventoryModeSetting} from '../public/inventory-mode-setting.js';

const product={key:'product-1',title:'<Ürün>',sku:'SKU-1',quantity:0,storeQuantity:null,marketplaceQuantity:2,totalQuantity:0,purchasePriceCents:3061122,marketplaceStocks:[{}]};
const view=(mode,admin=true)=>inventoryView({admin,query:'',filter:'depleted',data:{settings:{mode},items:[product]}});
for(const admin of [true,false])test(`Horoz tek stok ve ürün fiyatı sütunu; admin=${admin}`,()=>{
 const html=view('horoz',admin),head=html.match(/<thead[\s\S]*?<\/thead>/)[0];
 assert.match(head,/>Stok<\/th>/);assert.match(head,/>Alış fiyatı<\/th>/);assert.doesNotMatch(head,/Mağaza stoğu|Toplam stok|Pazaryeri stoğu/);
 assert.match(html,/data-purchase-price="product-1"/);assert.match(html,/30\.611,22/);assert.match(html,/value="depleted" selected/);
 assert.equal((html.match(/<th scope=/g)||[]).length,admin?6:5);
 assert.equal((html.match(/<td role=/g)||[]).length,admin?6:5);
 if(admin)assert.equal((html.match(/class="btn secondary inventory-action/g)||[]).length,5);
 else assert.doesNotMatch(html,/data-stock-edit|data-store-sale/);
});
test('Mağaza modeli stok sütunlarını korur ve alış fiyatını ekler',()=>{
 const html=view('both');for(const label of ['Pazaryeri stoğu','Mağaza stoğu','Toplam stok','Alış fiyatı'])assert.match(html,new RegExp('>'+label+'<'));
 assert.equal((html.match(/<th scope=/g)||[]).length,8);
 assert.equal((html.match(/<td role=/g)||[]).length,8);
});
test('Fiyat anahtarı satır sırasına bağlı değildir; silinen ürün salt okunur',()=>{
 assert.match(inventoryPriceCell(product),/data-purchase-price="product-1"/);
 assert.match(inventoryPriceCell({...product,key:'product-2',purchasePriceCents:null}),/data-purchase-price="product-2"[^>]*>Fiyat gir/);
 assert.doesNotMatch(inventoryPriceCell({...product,removed:true}),/<button/);
 assert.match(inventoryPriceCell({...product,purchasePriceCents:0}),/0,00/);
});
test('Horoz sayım/satış/iade formu ortak sayımı kullanır',()=>{
 assert.match(inventoryStockForm(product,false,{horoz:true}),/Güncel ortak stok adedi/);
 assert.match(inventoryStockForm(product,true,{horoz:true}),/value="store_return"/);
 assert.equal(stockPreview(product,'2',true,{horoz:true}),'Kaydedilecek ortak stoğu: -2 adet');
 assert.equal(stockPreview(product,'2',true,{horoz:true,mode:'store_return'}),'Kaydedilecek ortak stoğu: 2 adet');
});
test('Fiyat bildirimi ürün ve kullanıcı bilgisiyle kurumsal alanlar açar',()=>{
 const event={events:['purchase_price_updated'],productTitle:'<Ürün>',sku:'SKU',actorName:'Ayşe',previousPriceCents:null,purchasePriceCents:3061122};
 const html=inventoryNotificationDetail(event);assert.equal(inventoryNotificationTitle(event),'Alış fiyatı güncellendi');
 assert.match(html,/&lt;Ürün&gt;/);assert.match(html,/Ayşe/);assert.match(html,/30\.611,22/);assert.doesNotMatch(html,/Sipariş numarası/);
 assert.match(inventoryModeSetting('horoz'),/value="horoz" selected/);
});
