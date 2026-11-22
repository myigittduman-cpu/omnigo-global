import test from 'node:test';
import assert from 'node:assert/strict';
import {prepareShipmentDocument,shipmentDocumentFile} from '../public/shipment-document-files.js';
import {shipmentDocumentControls} from '../public/shipment-documents.js';
import {salesFreshness} from '../public/freshness.js';
import {connectionHealth} from '../public/channel-cards.js';
test('PDF pages remain byte-identical, JPEG conversion is not offered for PDF bytes',async()=>{
 const bytes='%PDF-1.4\n1 0 obj\n<< /Type /Pages /Count 2 >>\nendobj\n%%EOF';
 const file=new File([bytes],'ornek.pdf',{type:'application/pdf'}),prepared=await prepareShipmentDocument(file);
 assert.equal(prepared.type,'application/pdf');assert.equal(await prepared.text(),bytes);
 const downloaded=await shipmentDocumentFile({bytes:btoa(bytes),mime:'application/pdf'},'pdf');assert.equal(await downloaded.text(),bytes);
 await assert.rejects(shipmentDocumentFile({bytes:btoa(bytes),mime:'application/pdf'},'jpeg'),/PDF/);
 await assert.rejects(prepareShipmentDocument(new File(['<html>invalid'], 'bad.pdf',{type:'application/pdf'})),/PDF/);
 await assert.rejects(prepareShipmentDocument(new File(['%PDF-1.4\n',new Uint8Array(4*1024*1024)],'large.pdf',{type:'application/pdf'})),/4 MB/);
 assert.equal((await shipmentDocumentFile({bytes:btoa('legacy-jpeg')},'jpeg')).type,'image/jpeg');
 assert.match(shipmentDocumentControls({documents:{invoice:true}}),/Fatura · Dosya ekle, görüntüle veya indir/);
});
test('Freshness warns after two minutes for active fast integrations',()=>{
 const now=Date.now(),data=age=>({sales:[{integration:true,channel:'n11'}],channels:[{name:'n11',lastSyncAt:new Date(now-age).toISOString()}]});
 assert.match(salesFreshness(data(119000)),/^Son satış/);assert.match(salesFreshness(data(121000)),/^Aktarım gecikmiş/);
 assert.match(connectionHealth({health:{status:'warning',message:'Yeni veri bekleniyor.'}}),/Güncel aktarım bekleniyor/);
});
