import assert from 'node:assert/strict';
import test from 'node:test';
import {cityProducts} from '../public/demo/workspace-data.js';

const read=fields=>cityProducts(new URLSearchParams({group:'order',city:'all',...fields}));
const keys=rows=>rows.map(row=>row.orderKey);

test('Demo ürünleri önce tüm siparişlerde sıralar; ekran sayfaları ve tam çıktı eşleşir',()=>{
 const dates=rows=>rows.map(row=>row.orderedAt);
 const names=(rows,key)=>rows.map(row=>row.deliveries[0][key]);
 const collator=new Intl.Collator('tr',{sensitivity:'base'});
 for(const sort of ['default','newest','oldest','city','recipient']){
  const full=read({sort,export:'1'}),first=read({sort,page:0}),second=read({sort,page:1});
  assert.equal(full.sort,sort);
  assert.ok(first.hasMore);
  assert.deepEqual(keys([...first.items,...second.items]),keys(full.items));
  if(sort==='newest')assert.deepEqual(dates(full.items),dates(full.items).sort().reverse());
  if(sort==='oldest')assert.deepEqual(dates(full.items),dates(full.items).sort());
  if(['city','recipient'].includes(sort)){
   const values=names(full.items,sort==='city'?'city':'recipientName');
   assert.deepEqual(values,[...values].sort(collator.compare));
  }
  const local=read({sort,city:'Ankara',export:'1'});
  assert.deepEqual(keys(local.items),keys(full.items.filter(row=>row.deliveries[0].city==='Ankara')));
 }
 assert.deepEqual(read({}).items,read({sort:'default'}).items);
 assert.throws(()=>read({sort:'bogus'}));
});
