import test from 'node:test';
import assert from 'node:assert/strict';
import {saveFeedbackFor} from '../public/save-feedback-policy.js';
import {isDialogCancel} from '../public/dialog-cancel.js';
import {inventorySalesDetails} from '../public/inventory-sales-details.js';

test('Dosya seçimi İptal, pencereyi kapatma isteği değildir',()=>{
 const dialog={},file={};
 assert.equal(isDialogCancel({target:file,currentTarget:dialog},dialog),false);
 assert.equal(isDialogCancel({target:dialog},dialog),true);
});
test('Onay yalnız kaydedilmiş kullanıcı değişikliğine verilir; okuma ve yan işlemler sessizdir',()=>{
 assert.equal(saveFeedbackFor('/management/customer','PUT',{}).kind,'success');
 assert.equal(saveFeedbackFor('/inventory/purchase-price','POST',{}).kind,'success');
 assert.equal(saveFeedbackFor('/inventory/purchase-price','POST',{changed:false}),null);
 for(const [url,method] of [['/management/customer','GET'],['/auth/login','POST'],['/notifications/read','POST'],['/push/register','POST'],['/shipments/search','POST']])assert.equal(saveFeedbackFor(url,method,{}),null);
 assert.equal(saveFeedbackFor('/integrations/n11','POST',{}, {action:'retry'}),null);
 assert.equal(saveFeedbackFor('/inventory/commands','POST',{state:'pending'}).kind,'pending');
 assert.equal(saveFeedbackFor('/inventory/commands?requestId=abc','GET',{state:'done'}).key,'abc');
 assert.equal(saveFeedbackFor('/inventory/commands?requestId=abc','GET',{state:'failed'}),null);
});
test('Satış ayrıntısı tanımlı olmayan pazaryerini de gösterir; satış adetlerini veya sırasını taşımaz',()=>{
 const html=inventorySalesDetails({title:'A',markets:[{provider:'n11',gross:4,net:4}],marketplaceAvailability:[{provider:'idefix',status:'not_found',label:'Ürün bulunamadı',detail:'<kaynak>'},{provider:'n11',status:'closed',label:'Satışa kapalı',detail:'Tanımlı'}]});
 assert.match(html,/idefix<\/th><td>0/);
 assert.match(html,/title="&lt;kaynak&gt;">Ürün bulunamadı/);
 assert.match(html,/n11<\/th><td>4<\/td><td>0<\/td><td>0<\/td><td>4/);
 assert.match(html,/Satışa kapalı/);
});
