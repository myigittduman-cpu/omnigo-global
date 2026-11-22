import test from 'node:test';
import assert from 'node:assert/strict';
import {workspaceMenuLayout} from '../public/workspace-menu-layout.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';

test('Görünen menüler 1–36 bağlantıda iki sırayı aşmaz ve hücreler çakışmaz',()=>{
 for(let count=1;count<=36;count++){
  const layout=workspaceMenuLayout(count),occupied=new Set();
  assert.equal(layout.slots.length,count);
  for(const slot of layout.slots){
   assert(slot.row>=1&&slot.row+slot.rowSpan<=3);
   assert(slot.column>=1&&slot.column+slot.span<=layout.units+1);
   for(let row=slot.row;row<slot.row+slot.rowSpan;row++)for(let col=slot.column;col<slot.column+slot.span;col++){
    const key=`${row}:${col}`;assert(!occupied.has(key));occupied.add(key);
   }
  }
 }
});
test('Üç menünün sonuncusu ortalı; dört menü her hesapta yönetici ile aynı satır sırasını izler',()=>{
 assert.deepEqual(workspaceMenuLayout(3).slots.map(({row,column})=>[row,column]),[[1,1],[1,3],[2,2]]);
 assert.deepEqual(workspaceMenuLayout(4).slots.map(({row,column})=>[row,column]),[[1,1],[1,3],[2,1],[2,3]]);
 assert.equal(workspaceMenuLayout(1).slots[0].rowSpan,2);
});
test('Her rol ve izin alt kümesi yalnız görünen menülerden düzen üretir',()=>{
 const pages=['orders','inventory','shipments','deliveries','returns'];
 const names=Object.fromEntries(pages.map(key=>[key,key]));
 for(const navigationLayout of [undefined,'logistics','store_manager'])for(let mask=0;mask<32;mask++){
  const visible=pages.filter((_,index)=>mask&(1<<index));
  const access={navigationLayout,pages:Object.fromEntries(pages.map(key=>[key,'shown'])),grants:Object.fromEntries(pages.map(key=>[key,{view:visible.includes(key),viewCancelledRecords:true}]))};
  const html=workspaceNavigation(names,{user:{role:'client'},data:{workspaceAccess:access}});
  const adminMenu=workspaceNavigation(Object.fromEntries(visible.map(id=>[id,id])),{user:{role:'admin'},data:{workspaceAccess:access}});
  for(const [menu,orderGroup] of [[html,'operations'],[adminMenu,'management']]){
   assert.deepEqual([...menu.matchAll(/data-nav="([^"]+)"/g)].map(match=>match[1]).sort(),visible.slice().sort());
   for(const [section] of menu.matchAll(/<section class="workspace-directory-group"[\s\S]*?<\/section>/g)){
    const slots=[...section.matchAll(/data-nav="([^"]+)" data-menu-row="(\d+)" data-menu-row-span="(\d+)" data-menu-column="(\d+)" data-menu-span="(\d+)"/g)];
    assert.ok(slots.length);
    assert(section.includes(`data-menu-count="${slots.length}"`));assert(section.includes('data-menu-layout="two-row"'));
    assert.deepEqual(slots.map(([,id,row,rowSpan,column,span])=>({row:+row,rowSpan:+rowSpan,column:+column,span:+span})),workspaceMenuLayout(slots.length).slots);
    if(slots.some(([,id])=>id==='orders'))assert(section.includes(`data-menu-group="${orderGroup}"`));
   }
  }
 }
});

 test('Menü yerleşimi satır içi stil gerektirmez; sıkı CSP altında dış stil kuralları kullanır',()=>{
 const html=workspaceNavigation({overview:'Genel bakış',inbox:'Pazaryeri Soruları',inventory:'Stok Yönetimi'},{user:{role:'admin'},data:{}});
 assert(!html.includes('style='));assert(html.includes('data-menu-columns='));assert(html.includes('data-menu-row='));
 });
