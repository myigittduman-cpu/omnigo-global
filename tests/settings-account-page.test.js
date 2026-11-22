import test from 'node:test';
import assert from 'node:assert/strict';
import {takeSettingsReturn,pendingSettingsReturn} from '../public/settings-account-page.js';

const now=1800000000000;
const record={userId:7,companyId:2,view:'inventory',month:'2026-10',savedAt:now};
function storage(value){
 let raw=JSON.stringify(value);
 return {getItem:()=>raw,removeItem:()=>{raw=null;}};
}
test('Hesap sayfasından dönüş, aynı hesabın seçili müşterisini ve sekmesini bir kez açar',()=>{
 const saved=storage(record),user={id:7,role:'admin'},companies=[{id:2}];
 assert.equal(pendingSettingsReturn(user,saved,now)?.view,'inventory');
 assert.equal(pendingSettingsReturn({id:8,role:'admin'},saved,now),null);
 assert.deepEqual(takeSettingsReturn(user,companies,saved,now),{companyId:2,view:'inventory',month:'2026-10'});
 assert.equal(takeSettingsReturn(user,companies,saved,now),null);
});
test('Başka hesap veya müşteriye ait dönüş bilgisi uygulanmaz',()=>{
 assert.equal(takeSettingsReturn({id:8,role:'admin'},[{id:2}],storage(record),now),null);
 assert.equal(takeSettingsReturn({id:7,role:'admin'},[{id:3}],storage(record),now),null);
 assert.equal(takeSettingsReturn({id:7,role:'customer',companyId:3},[{id:2},{id:3}],storage(record),now),null);
 assert.equal(takeSettingsReturn({id:7,role:'customer',companyId:2},[{id:2}],storage(record),now)?.view,'inventory');
});
test('Eski, gelecekteki veya geçersiz sekmeli dönüş kaydı kullanılmaz',()=>{
 for(const value of [{...record,savedAt:now-21600001},{...record,savedAt:now+1},{...record,view:'unknown'}])
  assert.equal(takeSettingsReturn({id:7,role:'admin'},[{id:2}],storage(value),now),null);
});
