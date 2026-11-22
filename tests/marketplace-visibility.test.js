import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {hiddenMarketplaces,marketplaceVisible} from '../public/marketplace-visibility.js';
import {shipmentProviders,shipmentSelection} from '../public/shipments-view.js';
import {shipmentShareSelection} from '../public/shipment-share.js';
import {deliveryProviders,deliveryView} from '../public/delivery-view.js';
import {marketplacePanels} from '../public/inbox-panels.js';
import {orderAlertGroups} from '../public/order-alert-model.js';
test('Görünüm politikası sunucuyla tutarlı; depolanan katalog silinmez',()=>{
 const php=fs.readFileSync(new URL('../php/src/MarketplaceVisibility.php',import.meta.url),'utf8');
 assert.deepEqual([...php.match(/HIDDEN = \[([^\]]+)\]/)[1].matchAll(/'([^']+)'/g)].map(m=>m[1]),hiddenMarketplaces);
 for(const name of ['TEKNOSA','Teknosa'])assert.equal(marketplaceVisible(name),false);
 for(const name of ['PTT AVM','pttavm','PTT_AVM','TRENDYOL'])assert.equal(marketplaceVisible(name),true);
 assert.deepEqual(shipmentProviders.map(([name])=>name),['all','Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol']);
 assert.deepEqual([...deliveryProviders].sort(),['Trendyol','PTT AVM','Hepsiburada','n11','idefix','Pazarama','Koçtaş'].sort());
});
test('Sevkiyat, teslimat, mesaj seçicileri ve eski paylaşım bağlantısı gizlenen kanalları açmaz',()=>{
 const all=['PTT AVM','Trendyol','Teknosa','Hepsiburada','n11','idefix','Pazarama','Koçtaş'];
 const html=marketplacePanels([],all.map(name=>({name})));
 for(const name of all.slice(2,3))assert.ok(!html.includes(name));
 const model={city:'all',provider:'n11',month:'2026-10',draftProvider:'n11',picker:null};
 const shipments=shipmentSelection(model);
 const delivery=deliveryView({...model,queue:null,date:'2026-10-15',selected:new Set(),page:0,data:null});
 for(const name of all.slice(2,3)){assert.ok(!shipments.includes(name));assert.ok(!delivery.includes(name));}
 const url='https://yonetim.omnigoglobal.com/?view=shipments&company=1&city=all&month=2026-10&provider=';
 assert.equal(shipmentShareSelection(url+'PTT AVM').provider,'PTT AVM');
 assert.equal(shipmentShareSelection(url+'n11').provider,'n11');
 assert.deepEqual(orderAlertGroups([{id:1,provider:'pttavm'},{id:2,provider:'n11'}]).map(g=>g.provider),['pttavm','n11']);
});
