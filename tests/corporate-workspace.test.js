import {test} from 'node:test';
import assert from 'node:assert/strict';
import {pageSnapshot,clearPageSnapshots,clearMenuSnapshot} from '../public/workspace-page-cache.js';
import {rankBestsellers} from '../public/report-bestsellers.js';
test('Page snapshots cannot cross an account, company, context or access revision',()=>{
 const state={user:{id:1,role:'admin'},company:1,contextRevision:1,month:'2026-10',data:{workspaceAccess:{version:1}}};
 const snapshot=pageSnapshot(state,'orders',['data']);snapshot.save({data:{items:[{id:100}]},password:'never store'});
 const model={};assert.equal(snapshot.restore(model),true);assert.deepEqual(model,{data:{items:[{id:100}]}});model.data.items[0].id=200;assert.equal(snapshot.restore(model),true);assert.equal(model.data.items[0].id,100);
 for(const next of [{...state,company:2},{...state,user:{id:2,role:'admin'}},{...state,contextRevision:2},{...state,data:{workspaceAccess:{version:2}}}])assert.equal(pageSnapshot(next,'orders',['data']).restore({}),false);
 clearPageSnapshots();assert.equal(snapshot.restore({}),false);
});
test('Menu reentry drops the previous document tab only in the selected workspace',()=>{
 const state={user:{id:1,role:'client'},company:1,month:'2026-11'};
 const invoice=pageSnapshot(state,'shipmentInvoices',['queue','page']);
 invoice.save({queue:'completed',page:2});
 const otherStore=pageSnapshot({...state,company:2},'shipmentInvoices',['queue']);
 const otherMenu=pageSnapshot(state,'shipmentDeliveryDocuments',['queue']);
 otherStore.save({queue:'completed'});otherMenu.save({queue:'completed'});
 const current={};assert.equal(invoice.restore(current),true);assert.equal(current.queue,'completed');
 clearMenuSnapshot(state,'shipmentInvoices');
 const reentry={queue:'pending',page:0};
 assert.equal(invoice.restore(reentry),false);assert.deepEqual(reentry,{queue:'pending',page:0});
 assert.equal(otherStore.available(),true);assert.equal(otherMenu.available(),true);
 clearPageSnapshots();
});
test('Bestsellers combine selected marketplaces by SKU and exclude unverified counts',()=>{
 const ranked=rankBestsellers([{title:'Ürün A',sku:'A',channel:'n11',netQuantity:4,netCents:400},{title:'Ürün A',sku:'A',channel:'Hepsiburada',netQuantity:3,netCents:300},{title:'Ürün B',sku:'B',channel:'n11',netQuantity:6,netCents:600},{title:'Bilinmeyen',sku:'X',netQuantity:null,netCents:999999}]);
 assert.equal(ranked.length,2);assert.equal(ranked[0].sku,'A');assert.equal(ranked[0].netQuantity,7);assert.equal(ranked[0].netCents,700);assert.deepEqual(ranked[0].channels,['n11','Hepsiburada']);assert.equal(ranked[1].rank,2);
});

const {selectPageDeliveries}=await import('../public/delivery-dispatch-selection.js');
test('Select all includes both waiting and dispatched rows and clears both',()=>{const items=[{id:1,dispatchState:'waiting',selectionKey:'1:waiting'},{id:1,dispatchState:'shipped',selectionKey:'1:shipped'},{id:2}];assert.deepEqual([...selectPageDeliveries(items,true)],['1:waiting','1:shipped',2]);assert.equal(selectPageDeliveries(items,false).size,0);});
