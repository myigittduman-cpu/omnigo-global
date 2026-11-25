import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source=readFileSync(new URL('../public/customer-actions.js',import.meta.url),'utf8')
 .replace(/^import .*;\r?\n/gm,'').replace(/export /g,'');
const cards=['#customerSetup','#customerPermissions','#manageCustomer','#customerMarketplaces'];

function fixture(overrides={}){
 const state={user:{id:7,role:'admin'},company:1,companies:[{id:1,name:'Birinci'},{id:2,name:'İkinci'}],...overrides};
 const callbacks=new Map(),calls=[];
 const root={querySelector(selector){return cards.includes(selector)?{
  addEventListener(type,callback){assert.equal(type,'click');callbacks.set(selector,callback);}
 }:null;}};
 const onSave=async()=>{},onCreate=()=>{},onSwitch=()=>{},onAccountUpdated=()=>{};
 const deps={
  customerSetup:(...args)=>calls.push({kind:'setup',args}),
  customerPermissions:(...args)=>calls.push({kind:'permissions',args}),
  customerManagement:(...args)=>calls.push({kind:'management',args}),
  customerMarketplaces:(...args)=>calls.push({kind:'marketplaces',args}),
 };
 const bind=Function(...Object.keys(deps),source+';return bindCustomerActions;')(...Object.values(deps));
 bind(root,state,{onSave,onCreate,onSwitch,onAccountUpdated});
 return {state,calls,onSave,onAccountUpdated,async click(selector){await callbacks.get(selector)?.({preventDefault(){}});}};
}

test('Dört müşteri kartı seçili müşteriyi doğrudan açar ve gerçek kayıt geri çağrısını korur',async()=>{
 const f=fixture({company:'2'});
 for(const selector of cards)await f.click(selector);
 assert.deepEqual(f.calls.map(call=>call.kind),['setup','permissions','management','marketplaces']);
 for(const call of f.calls){assert.equal(call.args[0],f.state);assert.equal(call.args[1],f.onSave);}
 const [{companyId,onManageAccounts}]=f.calls[0].args.slice(2);
 assert.equal(companyId,2);
 assert.deepEqual(f.calls[1].args.slice(2),[{companyId:2,onAccountUpdated:f.onAccountUpdated}]);
 assert.deepEqual(f.calls[2].args.slice(2),['profile',2]);
 assert.deepEqual(f.calls[3].args.slice(2),[2]);
 assert.equal(f.state.company,'2','Kart açılışı çalışma alanını değiştirmemeli');
 const setupReturn=()=>{};
 await onManageAccounts(setupReturn);
 assert.deepEqual(f.calls[4],{kind:'permissions',args:[f.state,f.onSave,{companyId:2,onAccountUpdated:f.onAccountUpdated,setupReturn}]});
});

test('Kartlar bağlandıktan sonraki müşteri değişimi tıklama anındaki müşteriyle açılır',async()=>{
 const f=fixture();f.state.company=2;
 for(const selector of cards)await f.click(selector);
 assert.equal(f.calls.length,4);
 assert.equal(f.calls[0].args[2].companyId,2);
 assert.deepEqual(f.calls.slice(1).map(call=>call.args.slice(2)),[[{companyId:2,onAccountUpdated:f.onAccountUpdated}],['profile',2],[2]]);
});

test('Geçersiz veya yetkili müşteri listesinde bulunmayan bağlamda dört korumalı kart açılmaz',async()=>{
 for(const company of [null,undefined,'',0,-1,999,'yanlış',1.5]){
  const f=fixture({company});
  for(const selector of cards)await f.click(selector);
  assert.deepEqual(f.calls,[],`Geçersiz müşteri bağlamı: ${String(company)}`);
 }
 const empty=fixture({companies:[]});for(const selector of cards)await empty.click(selector);assert.deepEqual(empty.calls,[]);
});

test('Müşteri hesabı ve tıklamadan önce yetkisi düşen hesap yönetim kartlarını açamaz',async()=>{
 for(const initialClient of [true,false]){
  const f=fixture({user:{id:9,role:initialClient?'client':'admin'}});
  if(!initialClient)f.state.user.role='client';
  for(const selector of cards)await f.click(selector);
  assert.deepEqual(f.calls,[],'Yönetim kartları yalnız Omnigo yöneticisi için açılmalı');
 }
});
