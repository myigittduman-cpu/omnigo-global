import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentProductMode} from '../public/shipment-product-policy.js';
import {shipmentTable} from '../public/shipments-view.js';
import {ordersView} from '../public/orders-view.js';
import {managementOrdersShell,managementOrderRows} from '../public/management-orders-view.js';
import {cityProductsContent,cityProductRows} from '../public/city-products-view.js';

const name='Profilo <Tam Ürün> 641 Lt NoFrost Üstten Donduruculu Kombi Tipi Buzdolabı';
const shipment={id:10,orderNumber:'S-10',productCode:'SKU-10',productName:name,quantity:2,invoiceCents:30000,province:'Ankara',district:'Mamak'};
const order={id:10,grossCents:30000,externalId:'S-10',products:[{productCode:'SKU-10',title:name,quantity:2}],destinations:[{recipientName:'Birinci Alıcı',province:'Ankara',district:'Mamak'}],invoiceCents:30000};
const cityOrder={id:10,quantity:2,deliveries:[{recipientName:'Birinci Alıcı',city:'Ankara',district:'Mamak',products:[{id:10,sku:'SKU-10',productName:name,quantity:2}]}]};
const data={items:[shipment],total:1,totalRecords:1,page:0,pageSize:15};
const shipmentModel=productMode=>({productMode,layout:'invoice',month:'2026-11',provider:'all',city:'all',selectedIds:new Set(),draftChanges:[],filters:{},data});
const productCell=html=>html.match(/<td class="shipment-cell-productCode">[\s\S]*?<\/td>/)?.[0];

test('VİZYONA istisnası kalıcı mağaza kimliğine bağlıdır; yeni ve diğer mağazalar tam ad kullanır',()=>{
 for(const id of [1,'1'])assert.equal(shipmentProductMode(id),'skuQuantity');
 for(const id of [2,3,25,9999,null,undefined,''])assert.equal(shipmentProductMode(id),'fullName');
});

test('Sevkiyat ürün sütunu tam adı güvenli gösterir; VİZYONA SKU, adet ve oku korur',()=>{
 const before=structuredClone(data);
 const full=shipmentTable(shipmentModel(shipmentProductMode(2))),legacy=shipmentTable(shipmentModel(shipmentProductMode(1)));
 assert.match(full,/shipment-head-productCode">Ürün<\/th>/);assert.match(productCell(full),/Profilo &lt;Tam Ürün&gt; 641 Lt/);
 assert.doesNotMatch(productCell(full),/SKU-10|<details|<summary|shipment-product-quantity/);
 assert.match(legacy,/shipment-head-productCode">SKU\/Adet<\/th>/);assert.match(productCell(legacy),/SKU-10/);assert.match(productCell(legacy),/2 adet/);assert.match(productCell(legacy),/<summary/);
 assert.match(productCell(full),/2 adet/);assert.equal(shipmentTable(shipmentModel()),full);assert.deepEqual(data,before);
});

test('İki Siparişler görünümü aynı mağaza kuralını uygular; toplam adet, fatura ve alıcılar korunur',()=>{
 for(const company of [1,2,999]){
  const productMode=shipmentProductMode(company),model={productMode,page:0,query:'',provider:'all',province:'all',from:'2026-11-01',to:'2026-11-28',data:{items:[order],total:1,pageSize:15}},full=company!==1;
  for(const html of [ordersView(model),managementOrdersShell(model)+managementOrderRows([order],productMode)]){
   assert.match(html,new RegExp(full?'>Ürün</th>':'>SKU/Adet</th>'));
   const cell=html.match(/<td[^>]*class="shipment-sku-cell"[^>]*>[\s\S]*?<\/td>/)?.[0];
   assert.ok(cell);assert.match(cell,/Profilo &lt;Tam Ürün&gt;/);
   if(full){assert.doesNotMatch(cell,/SKU-10|<details|<summary/);if(html.includes('management-orders-table'))assert.match(cell,/2 adet/);}else{assert.match(cell,/SKU-10/);assert.match(cell,/<summary/);}
   assert.match(html,/₺300,00/);
  }
 }
});

test('Şehir Dışı Ürünler tam ürün adı gösterir; çoklu teslimatlarda ürün-adres ilişkisi bozulmaz',()=>{
 const second={recipientName:'İkinci Alıcı',city:'Kayseri',district:'Talas',products:[{id:11,sku:'SKU-11',productName:'İkinci tam ürün adı',quantity:1}]};
 const combined={...cityOrder,quantity:3,deliveries:[...cityOrder.deliveries,second]};
 for(const role of ['admin','client'])for(const company of [1,2,999]){
  const mode=shipmentProductMode(company),state={company,user:{role,companyId:company},data:{deliveryCities:['Ankara','Kayseri']}};
  const shell=cityProductsContent(state),rows=cityProductRows({items:[combined]},mode);
  assert.match(shell,new RegExp(company===1?'>Ürün SKU</th>':'>Ürün</th>'));
  const sections=rows.split('class="city-product-destination"');
  assert.match(sections[1],/Birinci Alıcı[\s\S]*Profilo &lt;Tam Ürün&gt;/);assert.doesNotMatch(sections[1],/İkinci tam ürün adı/);
  assert.match(sections[2],/İkinci Alıcı[\s\S]*İkinci tam ürün adı/);assert.match(rows,/city-products-quantity">3<\/td>/);
  if(company!==1)assert.doesNotMatch(rows,/SKU-10|SKU-11|2 adet|1 adet|<details|<summary/);else assert.match(rows,/<summary/);
 }
});

test('Tam ürün adı miktarı stok koduna göre toplar; bilinmeyen adet ve kodlar birleştirilmez',async()=>{
 const {shipmentNamedProducts}=await import('../public/shipment-product-policy.js');
 const items=[{productCode:'abc',title:'Birinci ürün',quantity:2},{productCode:'ABC',title:'Birinci ürün',quantity:3},{productCode:'def',title:'İkinci ürün',quantity:null},{title:'Kodsuz ürün',quantity:0},{title:'Kodsuz ürün',quantity:4}];
 const before=structuredClone(items);
 assert.deepEqual(shipmentNamedProducts(items),[{name:'Birinci ürün',quantity:5},{name:'İkinci ürün',quantity:null},{name:'Kodsuz ürün',quantity:0},{name:'Kodsuz ürün',quantity:4}]);
 assert.deepEqual(items,before);
 const html=managementOrderRows([{...order,products:items}],'fullName');
 assert.match(html,/5 adet/);assert.match(html,/Adet aktarılmamış/);assert.match(html,/0 adet/);assert.doesNotMatch(html,/<details|<summary/);
});
