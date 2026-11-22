import test from 'node:test';
import assert from 'node:assert/strict';
import {applyPermissionsToUsers} from '../public/permission-user-application.js';
const catalog={orders:{label:'Siparişler'},inventory:{label:'Stok Yönetimi'}},users=[{id:7},{id:8},{id:9}];
const defaults={orders:{view:true,edit:false},inventory:{view:true,edit:false}};
const configuration=()=>({version:15,pages:{orders:'shown',inventory:'shown'},cities:['Ankara'],defaults:structuredClone(defaults),users:{7:{orders:{view:true,edit:true},inventory:{view:true,edit:true}},8:{orders:{view:false,edit:false},inventory:{view:true,edit:false}}}});
test('Seçilen kullanıcıya kopyalar; diğer kullanıcı, mağaza ayarları ve kaynak taslak değişmez',()=>{
 const input=configuration(),before=structuredClone(input),next=applyPermissionsToUsers(input,{users,selectedIds:['8','8'],sourceUserId:'7',catalog});
 assert.deepEqual(next.users[8],input.users[7]);assert.deepEqual(next.users[7],input.users[7]);assert.equal(next.users[9],undefined);assert.deepEqual(next.pages,input.pages);assert.deepEqual(next.cities,input.cities);assert.equal(next.version,15);assert.deepEqual(input,before);
 next.users[8].orders.edit=false;assert.equal(input.users[7].orders.edit,true);assert.equal(next.users[7].orders.edit,true);
});
test('Tek bölüm seçildiğinde hedefin diğer özel yetkileri korunur; kalıtılan kaynak genel yetkidir',()=>{
 const input=configuration(),next=applyPermissionsToUsers(input,{users,selectedIds:['7'],sourceUserId:'9',page:'orders',catalog});
 assert.deepEqual(next.users[7].orders,input.defaults.orders);assert.deepEqual(next.users[7].inventory,input.users[7].inventory);
});
test('Kendine ve varsayılanı kullanan kullanıcıya güvenle kopyalar',()=>{
 const input=configuration(),next=applyPermissionsToUsers(input,{users,selectedIds:['7','9'],sourceUserId:'7',catalog});
 assert.deepEqual(next.users[7],input.users[7]);assert.deepEqual(next.users[9],input.users[7]);assert.notEqual(next.users[7],next.users[9]);
});
test('Seçim yoksa kullanıcı yetkisi üretmez; genel yetkiler seçili hesaplara uygulanabilir',()=>{
 const input=configuration();assert.deepEqual(applyPermissionsToUsers(input,{users,selectedIds:[],catalog}),input);
 assert.deepEqual(applyPermissionsToUsers(input,{users,selectedIds:['8'],catalog}).users[8],input.defaults);
});
test('Mağaza dışındaki hedef, kaynak veya tanımsız bölüm reddedilir',()=>{
 for(const values of [{selectedIds:['88']},{selectedIds:['8'],sourceUserId:'88'},{selectedIds:['8'],page:'admin'}])assert.throws(()=>applyPermissionsToUsers(configuration(),{users,catalog,...values}));
});
