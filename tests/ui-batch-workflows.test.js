import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentStatusLabel,shipmentRowStatus} from '../public/shipment-status.js';
import {operationNotificationLabel} from '../public/operation-notification.js';
import {deliveryCityLabel} from '../public/delivery-city-filter.js';
import {turkeyCities} from '../public/turkey-cities.js';
test('Kaynak teslimatı yeşil tamamlandı sınıfı ve açık teslim edildi metni kullanır',()=>{
 const row=Object.freeze({marketplaceDelivered:true,displayRowStatus:'completed',documents:{invoice:true}});
 assert.equal(shipmentRowStatus(row),'completed');assert.equal(shipmentStatusLabel(row),'Teslim edildi');
 assert.equal(shipmentStatusLabel({...row,marketplaceDelivered:false}),'Sipariş Tamamlandı');
});
test('Aktarım bildirimi hangi alıcının siparişi olduğunu korur ve metni güvenli gösterir',()=>{
 assert.equal(operationNotificationLabel({buyerName:'Merve Akgün',events:['shipment_added']}),'Merve Akgün siparişi Şehir Dışı Sevkiyat listesine eklendi');
 assert.ok(!operationNotificationLabel({buyerName:'<script>',events:['shipment_added']}).includes('<script>'));
});
test('81 il seçimi uzun il listesi yerine tek kısa başlık kullanır',()=>{
 assert.equal(deliveryCityLabel({cities:[...turkeyCities],availableCities:turkeyCities}),'Tüm Şehirler');
 assert.equal(deliveryCityLabel({cities:turkeyCities.slice(0,4),availableCities:turkeyCities}),'4 şehir seçili');
});
