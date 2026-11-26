import test from 'node:test';
import assert from 'node:assert/strict';
import {customerCan,customerPageVisible,salesWorkspacePreset} from '../public/customer-access.js';
import {permissionSections} from '../public/customer-permissions-view.js';
import {canUseMarketplaceStockPilot} from '../public/marketplace-stock-pilot.js';
import {shipmentCapabilities} from '../public/shipment-permissions.js';
import {acknowledgeCategory} from '../public/notification-visit.js';
import {applyDeliveryCities,deliveryCityFilter} from '../public/delivery-city-filter.js';
import {turkeyCities} from '../public/turkey-cities.js';
test('Yetki ve görünürlük müşteri varsayılanlarını korur; özel izinler ayrıdır',()=>{
 const state={user:{role:'client'},data:{}};assert.equal(customerCan(state,'shipments','edit'),false);assert.equal(customerCan(state,'channels'),true);
 state.data.workspaceAccess={pages:{shipments:'shown'},grants:{shipments:{view:true,edit:true,status:false}}};
 assert.equal(shipmentCapabilities(state).canEdit,true);assert.equal(shipmentCapabilities(state).canConfirm,false);
 state.data.workspaceAccess.pages.shipments='hidden';assert.equal(customerPageVisible(state,'shipments'),false);assert.equal(customerCan(state,'shipments','edit'),false);
});
test('Kategori ziyareti kesitini okur; sonra gelen bildirimi topluca tüketmez',async()=>{
 const state={notificationData:null};let calls=0,sent;
 await acknowledgeCategory({state,category:'connection',current:()=>true,load:async()=>{state.notificationData={through:{operation:++calls},counts:{unread:28}};},send:async(category,through)=>{sent={category,through};},update:()=>{}});
 assert.deepEqual(sent,{category:'connection',through:{operation:1}});assert.equal(calls,2);assert.equal(state.notificationBusy,false);
});
test('Kategori alınamazsa okunmuş isteği gönderilmez; çalışma alanı değişince eski sonuç işlenmez',async()=>{
 let sent=0;const state={};await acknowledgeCategory({state,category:'connection',current:()=>true,load:async()=>{state.notificationError='Bağlantı yok';},send:async()=>sent++,update:()=>{}});assert.equal(sent,0);assert.equal(state.notificationBusy,false);
 await acknowledgeCategory({state:{notificationData:{through:{order:3},counts:{unread:2}}},category:'marketplace',current:()=>false,load:async()=>{},send:async()=>sent++,update:()=>{}});assert.equal(sent,0);
});

test('Bir bölüme girilmesi yalnız o bölümün sayısını düşürür; diğer bölümler okunmamış kalır',async()=>{
 const unread={marketplaces:4,stocks:3,users:2},state={},requests=[];
 const load=async()=>{state.notificationData={counts:{unread:unread.marketplaces},totalUnread:Object.values(unread).reduce((a,b)=>a+b,0),categoryCounts:structuredClone(unread),through:{order:4,operation:8,user:2}};};
 await acknowledgeCategory({state,category:'marketplaces',current:()=>true,load,send:async(category,through)=>{requests.push({category,through});unread[category]=0;},update:()=>{}});
 assert.equal(requests.length,1);assert.equal(requests[0].category,'marketplaces');assert.deepEqual(unread,{marketplaces:0,stocks:3,users:2});assert.equal(state.notificationData.totalUnread,5);
});
test('Kutu veri gelirken kapatılırsa okunmuş sayılmaz ve sonraki açılış kilitlenmez',async()=>{
 const state={};let visible=true,sent=0;
 await acknowledgeCategory({state,category:'stocks',current:()=>true,visible:()=>visible,load:async()=>{visible=false;state.notificationData={through:{operation:8},counts:{unread:2}};},send:async()=>sent++,update:()=>{}});
 assert.equal(sent,0);assert.equal(state.notificationBusy,false);
});

test('Yönetim tabloları ayarlarda bilgi olarak görünür, müşteri yetkisi oluşturmaz',()=>{
 const catalog={fileCenter:{label:'Dosya Merkezi',adminOnly:true,fixedAudience:true},
  marketplaceStocks:{label:'Pazaryeri Stokları',adminOnly:true,fixedAudience:true,audienceLabel:'Yalnız Omnigo · Pilot kapsamı'}};
 const markup=permissionSections(catalog,{pages:{}});
 assert.match(markup,/Dosya Merkezi[\s\S]+Pazaryeri Stokları[\s\S]+Pilot kapsamı/);
 assert.equal((markup.match(/<select disabled/g)||[]).length,2);
 assert.doesNotMatch(markup,/data-section/);
 const pilot={company:2,companies:[{id:2,marketplaceStockPilot:true},{id:3}]};
 assert.equal(canUseMarketplaceStockPilot({...pilot,user:{role:'client'}}),false);
 assert.equal(canUseMarketplaceStockPilot({...pilot,user:{role:'admin'}}),true);
 assert.equal(canUseMarketplaceStockPilot({...pilot,company:3,user:{role:'admin'}}),false);
});
