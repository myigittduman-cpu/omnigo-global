import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../public/customer-switch-access.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export ','');
const authorize=api=>Function('api',source+';return authorizeCustomerSwitch;')(api);
test('Müşteri geçişi hedef şirket için sunucudan salt okunur doğrulama alır',async()=>{
 const calls=[];assert.equal(await authorize(async path=>{calls.push(path);return {allowed:true,companyId:2};})({id:2}),true);assert.deepEqual(calls,['/admin/workspace-access?company=2']);
});
test('Sunucu geçişe izin vermezse eski müşteri korunur',async()=>{
 assert.equal(await authorize(async()=>({allowed:false,companyId:2}))({id:2}),false);
});
test('Geçersiz veya değişmiş müşteri bağlamı ve yanlış şirket yanıtı geçiş açamaz',async()=>{
 let calls=0;const check=authorize(async()=>{calls++;return {allowed:true,companyId:1};});assert.equal(await check({id:0}),false);assert.equal(await check({id:2},()=>false),false);assert.equal(calls,0);assert.equal(await check({id:2}),false);
});
test('Bağlantı hatası yetkili geçiş gibi kabul edilmez',async()=>{
 await assert.rejects(authorize(async()=>{throw Error('Sunucuya ulaşılamıyor');})({id:2}),/Sunucuya/);
});
