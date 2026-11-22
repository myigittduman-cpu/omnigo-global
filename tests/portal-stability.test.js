import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryCapabilities,inventoryActions} from '../public/inventory-capabilities.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {invoiceShipmentCells} from '../public/shipment-invoice-view.js';
import {invokeReportBridge} from '../public/report-file-action.js';
import {returnsView} from '../public/returns-view.js';

test('Lojistik rolü stok ayrıntısı alamaz; aynı izne sahip mağaza yetkilisi ve yönetici etkilenmez',()=>{
 const grants=Object.fromEntries(inventoryActions.map(action=>[action,true]));
 for(const role of ['accounting_logistics','store_manager','store_admin']){
  const state={user:{role:'client'},data:{workspaceAccess:{storeRole:role,pages:{inventory:'shown'},grants:{inventory:grants}}}};
  const caps=inventoryCapabilities(state,grants);
  assert.equal(caps.viewMarketplaceStocks,role!=='accounting_logistics');assert.equal(caps.editStock,true);
  const menu=workspaceNavigation({overviewTop:'En Çok Satan 10',overviewWork:'İşlem Bekleyenler',shipmentInvoices:'Faturalar',shipmentDeliveryDocuments:'Teslimat Evrakları'},state);
  assert.equal(menu.includes('workspace-directory--accounting-logistics'),role==='accounting_logistics');
 }
 assert(inventoryCapabilities({user:{role:'admin'}},grants).viewMarketplaceStocks);
});
test('Fatura düzeni iki adresi ayrı etiket ve kopyalama alanlarıyla gösterir',()=>{
 const html=invoiceShipmentCells({id:1,orderNumber:'1',invoiceAddress:'Fatura Sokak 1',deliveryAddress:'Teslimat Sokak 2',province:'Ankara'});
 assert.match(html,/Fatura adresi<\/small>Fatura Sokak 1/);assert.match(html,/Teslimat adresi<\/small>Teslimat Sokak 2/);
 assert.match(html,/data-shipment-copy="deliveryAddress"/);
});
test('Windows köprüsü ödeme belgesine Omnigo öneki ekleyemez',async()=>{
 const name='VİZYONA 16.11.26-30.11.26 Ödeme Tablosu.pdf',calls=[];
 const old={__OMNIGO_DESKTOP__:true,save:async file=>{calls.push(file);throw new Error('Rapor dosyasının adı geçersiz.');}};
 await assert.rejects(invokeReportBridge('save',name,'data',()=>true,old),/0\.2\.63/);
 assert.deepEqual(calls,[name]);
 let saved;assert.equal(await invokeReportBridge('save',name,'data',()=>true,{save:async file=>{saved=file;return 'saved';}}),'saved');assert.equal(saved,name);
});
test('Uzun sipariş numarası ve gerçek talep tarihi ayrı hücrelerde güvenli gösterilir',()=>{
 const html=returnsView({page:0,data:{items:[{provider:'n11',id:'1',orderNumber:'123456789012345678901234567890',createdAt:'2026-11-17T10:30:00Z',group:'pending',statusLabel:'Yeni'}],total:1,sources:[]}});
 assert.match(html,/return-order-number/);assert.match(html,/return-request-date[\s\S]*<time datetime="2026-11-17T10:30:00Z">/);assert.match(html,/<colgroup>/);
});
