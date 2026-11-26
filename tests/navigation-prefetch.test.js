import test from 'node:test';
import assert from 'node:assert/strict';
import {createNavigationPrefetch} from '../public/navigation-prefetch.js';
import {clearPageSnapshots,pageSnapshot,stagePagePosition} from '../public/workspace-page-cache.js';
import {paginationNumbers,paginationButtons} from '../public/pagination-controls.js';
const state=()=>({user:{id:1,role:'admin'},company:1,contextRevision:0,view:'overview',month:'2026-11',data:{workspaceAccess:{version:1}}});
test('Ön yükleme bir salt okuma isteğiyle sınırlıdır, şirket değişince eski yanıt saklanmaz',async()=>{
 clearPageSnapshots();const s=state(),paths=[];let complete;
 const warm=createNavigationPrefetch({state:s,canOpen:()=>true,request:path=>{paths.push(path);return new Promise(resolve=>complete=resolve);}});
 const first=warm('orders');assert.equal(await warm('inventory'),false);s.company=2;s.contextRevision++;complete({items:[{id:1}]});assert.equal(await first,false);assert.equal(paths.length,1);assert.match(paths[0],/^\/admin\/orders\?company=1/);
 assert.equal(pageSnapshot(s,'managementOrders',['data']).available(),false);
});
test('Yetkisiz sayfa okunmaz; güncellemeden dönüşteki filtre ön yükleme tarafından tüketilmez',async()=>{
 clearPageSnapshots();const s=state();let reads=0;
 const warm=createNavigationPrefetch({state:s,canOpen:v=>v!=='returns',request:async()=>{reads++;return {items:[]};}});
 assert.equal(await warm('returns'),false);s.view='orders';stagePagePosition({...s,view:'managementOrders'},{query:'Korunan arama',page:2});s.view='overview';assert.equal(await warm('orders'),false);
 const restored={};assert.equal(pageSnapshot(s,'managementOrders',['data']).restore(restored),true);assert.equal(restored.query,'Korunan arama');assert.equal(restored.page,2);assert.equal(reads,0);
});
test('Sayfa numarası etkin konumu ve baş/son sayfayı korur, uzun listede sınırlı kalır',()=>{
 assert.deepEqual(paginationNumbers(0,1),[0]);assert.deepEqual(paginationNumbers(50,100),[0,48,49,50,51,52,99]);
 const markup=paginationButtons({page:2,count:4,attribute:'data-orders-page'});assert.match(markup,/data-orders-page="2"[^>]*aria-current="page"/);assert.equal((markup.match(/aria-current="page"/g)||[]).length,1);assert.throws(()=>paginationButtons({attribute:'onclick'}));
});
