import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentTable} from '../public/shipments-view.js';
import {shipmentCargoSlipPdf} from '../public/shipment-cargo-slip.js';

const row={id:1,provider:'n11',orderNumber:'001',productCode:'SKU-01',productName:'Test ürünü',quantity:2,invoiceCents:125000};
const company={id:3,name:'TERZİOĞLU SHOP'};
const model={layout:'invoice',canShare:true,month:'2026-11',provider:'all',city:'all',
 filters:{},draftChanges:[],selectedIds:new Set(),data:{items:[row,{...row,id:2}],invoiceGroups:[[1,2]],total:1,page:0,pageSize:10}};

test('Kargo fişi seçili mağazanın yetkili fatura düzeninde sipariş başına bir düğme gösterir',()=>{
 const html=shipmentTable(model);
 assert.equal([...html.matchAll(/data-cargo-slip=/g)].length,1);
 assert.match(html,/<td class="shipment-cell-billingAmount">[\s\S]*?data-cargo-slip="1"[^>]*>[\s\S]*?Yazdır<\/button>/);
 const amount=html.match(/<td class="shipment-cell-billingAmount">([\s\S]*?)<\/td>/)?.[1]??'';
 assert.match(amount,/2\.500,00/);
 assert.doesNotMatch(amount,/table-copy-button|data-shipment-copy=/);
 const approved=shipmentTable({...model,canInvoice:true});
 assert.match(approved,/class="shipment-cargo-actions">[\s\S]*?data-shipment-invoice="1"[\s\S]*?data-cargo-slip="1"/);
 assert.doesNotMatch(shipmentTable({...model,canShare:false}),/data-cargo-slip|data-shipment-copy="invoiceCents"/);
 assert.doesNotMatch(shipmentTable({...model,layout:'grouped'}),/data-cargo-slip|data-shipment-copy="invoiceCents"/);
});

test('Kargo fişi Türkçe içerikle barkodlu ve barkodsuz PDF üretir',async()=>{
 const label={format:'CODE128',packageId:'PK1',value:'001234567890',carrier:'Test Kargo'};
 const document=shipmentCargoSlipPdf([{...row,shippingLabels:[label]}],company);
 const fallback=shipmentCargoSlipPdf([row],company);
 for(const blob of [document,fallback]){
  assert.equal(blob.type,'application/pdf');
  assert.match(await blob.slice(0,8).text(),/^%PDF-/);
 }
 assert.ok(document.size>fallback.size);
});
