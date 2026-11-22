import test from 'node:test';import assert from 'node:assert/strict';
import {deliveryCardCities,applyDeliveryScope} from '../public/delivery-scope.js';
import {defaultCities,turkeyCities} from '../public/turkey-cities.js';
import {marketplaceAccessCompany} from '../public/marketplace-access-policy.js';
import {attentionShell,attentionResult} from '../public/attention-center.js';
import {dossierView} from '../public/operation-dossier-view.js';
import {dossierQuery} from '../public/operation-dossier.js';
import {permissionUserPicker} from '../public/permission-user-picker.js';
import {customerPage} from '../public/customer-page.js';
test('İki operasyon alanı müşteri özetine eklenmez',()=>{assert.equal(attentionShell({user:{role:'client'}}),'');assert.match(attentionShell({user:{role:'admin'}}),/İlgilenilmesi gerekenler/);});
test('Bölgesel kartlar korunur; ulusal kapsam boş 81 kart üretmez',()=>{
 assert.deepEqual(deliveryCardCities({shippingMode:'regional',availableCities:defaultCities}),defaultCities);
 assert.deepEqual(deliveryCardCities({shippingMode:'nationwide',availableCities:turkeyCities,city:'all',data:{cities:[{name:'İstanbul',count:3},{name:'Ankara',count:0}]}}),['İstanbul']);
});
test('Kapsam değişince eski geçersiz filtre ve toplu seçim sıfırlanır',()=>{
 const model={city:'Kayseri',page:4,selected:new Set([1]),manualSelection:true};
 assert.equal(applyDeliveryScope(model,{shippingScope:{mode:'regional'},cities:[{name:'all'},{name:'Ankara'},{name:'Diğer / Kontrol'}]}),true);assert.equal(model.city,'Ankara');assert.equal(model.page,0);assert.equal(model.selected.size,0);
});
test('Ek şifre yalnız korunan mağaza yolunda yeniden doğrulanır',()=>{
 assert.equal(marketplaceAccessCompany('/integrations/n11/credentials?company=7'),7);assert.equal(marketplaceAccessCompany('/management/marketplaces?company=2'),2);assert.equal(marketplaceAccessCompany('/admin/connection-health?company=7'),null);assert.equal(marketplaceAccessCompany('/integrations/n11?company=-1'),null);
});
test('Konu ve fiyat kimliği satırla birlikte kalır; veri HTML olarak çalışmaz',()=>{const view=attentionResult({counts:{open:1,in_progress:0,resolved:0},notes:[],items:[{key:'a'.repeat(64),title:'<img>',detail:'Sipariş 0001',type:'shipments',assignee:'Omnigo',status:'open'}],page:0,pageCount:1});assert.match(view,/&lt;img&gt;/);assert.match(view,/data-attention-open="a{64}"/);assert.match(view,/Sipariş 0001/);});
test('Dosya sorgusu kullanıcıdan sipariş kimliği kabul etmez',()=>{const query=dossierQuery(2,{kind:'shipment',id:40,orderNumber:'FOREIGN',company:9});assert.equal(new URLSearchParams(query).get('company'),'2');assert.ok(!query.includes('FOREIGN'));});
test('Teslimat dosyası mevcut tarih alanını ve not kaçışını korur',()=>{const view=dossierView({target:{title:'Ürün',provider:'n11',orderNumber:'01'},fields:{state:'pending',receiver:'',eventDate:'',eventTime:'',serialNumbers:'',notes:'<script>'},files:[],history:[]});assert.match(view,/type="date"/);assert.match(view,/&lt;script&gt;/);assert.match(view,/form-grid/);assert.match(view,/pazaryerindeki sipariş, stok ve ödeme durumunu değiştirmez/);});
test('Boş kullanıcı listesi açılmaz; seçimde native details kullanılmaz',()=>{const empty=permissionUserPicker([],{},'Marka');assert.match(empty,/data-application-toggle[^>]*disabled/);assert.doesNotMatch(empty,/<details/);assert.match(empty,/permission-user-popover" hidden/);});
test('Müşteriler sayfasında işlem geçmişi kartı kaldırılır',()=>{const view=customerPage({company:1,companies:[{id:1,name:'Marka'}]});assert.doesNotMatch(view,/id="audit"/);assert.match(view,/id="customerMarketplaces"/);});
