import assert from 'node:assert/strict';
import test from 'node:test';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {customerPageVisible} from '../public/customer-access.js';
import {managementOrderRows,managementOrdersShell,managementOrdersPager} from '../public/management-orders-view.js';

test('Seçilen mağazanın yönetiminde Siparişler dördüncüdür ve müşterinin kapalı izni korunur',()=>{
 const names={orders:'Siparişler',inventory:'Stok Yönetimi',customers:'Mağazalar',overview:'Genel bakış',fileCenter:'Dosya Merkezi'};
 const state={company:2,user:{role:'admin'},view:'orders',data:{workspaceAccess:{pages:{orders:'hidden'},grants:{orders:{view:false}},setupOptions:{}}}};
 const nav=workspaceNavigation(names,state);
 const management=nav.split('data-menu-group="management"')[1].split('</section>')[0];
 assert.deepEqual([...management.matchAll(/data-nav="([^"]+)"/g)].map(match=>match[1]),['overview','customers','fileCenter','orders']);
 assert.equal((nav.match(/data-nav="orders"/g)||[]).length,1);
 const client={...state,user:{role:'client',companyId:2}};
 assert.equal(customerPageVisible(client,'orders'),false);
 assert.doesNotMatch(workspaceNavigation(names,client),/data-nav="orders"/);
});

test('Teslimat adresleri doğru alıcılarla eşleşir, siparişin toplamı yalnız bir kere yazılır',()=>{
 const order={id:33,orderNumbers:['S-10'],createdAt:'2026-11-18T08:15:00+03:00',provider:'n11',displayRowStatus:'invoiced',invoiceCents:2000000,
  products:[{productCode:'SKU-1',title:'Ürün <1>',quantity:2}],destinations:[
   {recipientName:'Ayşe',email:'ayse@example.invalid',phone:'123',deliveryAddress:'Birinci Cadde',province:'Ankara',district:'Mamak',invoiceType:'Bireysel'},
   {recipientName:'Ali',phone:'456',deliveryAddress:'İkinci Cadde',province:'Kayseri',district:'Talas',invoiceType:'Kurumsal'}]};
 const rows=managementOrderRows([order]);
 assert.match(rows,/Ayşe[\s\S]*?ayse@example\.invalid[\s\S]*?Mamak \/ Ankara[\s\S]*?Ali[\s\S]*?Talas \/ Kayseri/);
 assert.equal((rows.match(/management-order-amount/g)||[]).length,1);
 assert.equal((rows.match(/data-order-detail=/g)||[]).length,1);
 assert.match(rows,/<\/details><button[^>]+data-shipment-copy="productCode"/);
 assert.match(rows,/2 adet/);assert.match(rows,/Ürün &lt;1&gt;/);
 assert.deepEqual([...rows.matchAll(/data-shipment-copy="([^"]+)" data-shipment-copy-value="([^"]+)"/g)].map(match=>match.slice(1)),[
  ['orderNumber','S-10'],['recipientName','Ayşe'],['productCode','SKU-1'],['deliveryAddress','Birinci Cadde\nMamak / Ankara'],
  ['billingType','Bireysel'],['invoiceCents','₺20.000,00'],['recipientName','Ali'],['deliveryAddress','İkinci Cadde\nTalas / Kayseri'],['billingType','Kurumsal'],
 ]);
 for(const tone of ['cancelled','completed','shipped','invoiced'])assert.ok(managementOrderRows([{...order,displayRowStatus:tone}]).includes('shipment-row-'+tone));
 const withoutAmount=managementOrderRows([{...order,invoiceCents:null}]);
 assert.match(withoutAmount,/management-order-amount" rowspan="2">—/);
 assert.doesNotMatch(withoutAmount,/data-shipment-copy="invoiceCents"/);
 assert.match(managementOrdersShell({query:'',provider:'all',province:'all'}),/name="query"[\s\S]*name="provider"[\s\S]*name="province"/);
 assert.doesNotMatch(rows,/Fatura adresi/);
 assert.match(managementOrdersPager({page:1,pageSize:25,total:26}),/data-orders-page="2" disabled/);
});
