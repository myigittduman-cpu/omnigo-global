import test from 'node:test';
import assert from 'node:assert/strict';
import {cityProductRows} from '../public/city-products-view.js';
import {shipmentTrackingResult} from '../public/shipment-document-tracking-view.js';
import {shipmentRowStatus, shipmentStatusLabel} from '../public/shipment-status.js';

test('Ürünler ekranı yalnız sunucunun işaretlediği siparişi boyar', () => {
 const order={id:'abc',quantity:1,deliveries:[{city:'Ankara',products:[{id:'x',sku:'A',quantity:1}]}]};
 assert.doesNotMatch(cityProductRows({items:[order]}),/shipment-row-cancelled/);
 const marked=cityProductRows({items:[{...order,cancellationMark:'partial'}]});
 assert.match(marked,/shipment-row-cancelled/);
 assert.match(marked,/Kısmi iptal/);
});

test('Sınırlı fatura satırında iptal okunur ve indirilecek belge eylemi sunulmaz', () => {
 const row={id:1,cancellationMark:'cancelled',customerReadOnly:true,documentRestricted:true,productName:'Örnek'};
 const result=shipmentTrackingResult({kind:'invoice'},{
  queue:'completed',data:{items:[row],total:1,page:0,pageSize:10},query:'',canDocuments:true,canUpload:true,
 });
 assert.match(result,/class="shipment-row-cancelled"/);
 assert.match(result,/İptal edildi/);
 assert.doesNotMatch(result,/data-shipment-document=|₺/);
 assert.equal(shipmentRowStatus(row),'cancelled');
 assert.equal(shipmentStatusLabel({...row,cancellationMark:'partial'}),'Kısmi iptal');
});
