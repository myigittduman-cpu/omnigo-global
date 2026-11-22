import test from 'node:test';
import assert from 'node:assert/strict';
import {configureCustomerSwitch,requestCustomerSwitch,transitionCustomer} from '../public/customer-switch.js';
import {bindManagementCompany} from '../public/customer-management-selector.js';
import {bindPermissionStore} from '../public/customer-permission-store.js';
import {readFileSync} from 'node:fs';
import {selectCustomerWorkspace,workspaceToken,sameWorkspace} from '../public/workspace-context.js';

const company={id:2,name:'İkinci müşteri'};
test('Geçiş onayı reddedilirse kayda ve müşteri durumuna dokunulmaz',async()=>{
 const calls=[];assert.equal(await transitionCustomer(company,{ask:async()=>false,canLeave:async()=>calls.push('save'),switchWorkspace:async()=>calls.push('switch'),cover:async()=>calls.push('cover')}),false);assert.deepEqual(calls,[]);
});
test('Kaydedilmeyen değişiklik iptal edilirse dört saniyelik geçiş başlamaz',async()=>{
 const calls=[];assert.equal(await transitionCustomer(company,{ask:async()=>true,authorize:async()=>true,canLeave:async()=>false,switchWorkspace:async()=>calls.push('switch'),cover:async()=>calls.push('cover')}),false);assert.deepEqual(calls,[]);
});
test('Kaydetme tamamlanmadan yeni müşteri yüklenmez; geçiş örtüsü yüklemenin tamamını kapsar',async()=>{
 const calls=[];let finishSave;const saving=new Promise(resolve=>finishSave=resolve);
 const run=transitionCustomer(company,{ask:async()=>true,authorize:async()=>true,canLeave:async()=>{calls.push('saving');await saving;calls.push('saved');return true;},switchWorkspace:async()=>{calls.push('switch');return true;},cover:async(c,work)=>{assert.equal(c.id,2);calls.push('covered');const result=await work();calls.push('uncovered');return result;}});
 await Promise.resolve();await Promise.resolve();await Promise.resolve();assert.deepEqual(calls,['saving']);finishSave();assert.equal(await run,true);assert.deepEqual(calls,['saving','saved','covered','switch','uncovered']);
});
test('Onay açıkken çalışma alanı değişirse eski seçim yeni hesaba uygulanmaz',async()=>{
 let current=true,switched=false;assert.equal(await transitionCustomer(company,{current:()=>current,ask:async()=>{current=false;return true;},canLeave:async()=>true,switchWorkspace:async()=>switched=true}),false);assert.equal(switched,false);
});
test('Başarısız kayıt müşteriyi değiştiremez',async()=>{
 let switched=false;await assert.rejects(transitionCustomer(company,{ask:async()=>true,authorize:async()=>true,canLeave:async()=>{throw Error('Kaydedilemedi');},switchWorkspace:async()=>switched=true}),/Kaydedilemedi/);assert.equal(switched,false);
});
test('Ayarların ve yetkilerin müşteri seçicileri ortak çalışma alanı geçişine gider',async()=>{
 const calls=[],companies=[{id:1,name:'Birinci'},company],select={value:'2',isConnected:true},dialog={querySelector:()=>select};
 configureCustomerSwitch(async id=>{calls.push(id);return true;});
 try{
  bindManagementCompany(dialog,companies,1,{current:()=>true,onSelect:()=>assert.fail('Eski ayar penceresi yeniden açılmamalı')});await select.onchange();
  select.value='2';bindPermissionStore(dialog,{companies},1,{blocked:()=>false,onSelect:()=>assert.fail('Eski yetki penceresi yeniden açılmamalı')});await select.onchange();
  assert.deepEqual(calls,[2,2]);assert.equal(select.disabled,false);assert.equal(select.value,'1');
 }finally{configureCustomerSwitch(null);}
 assert.equal(requestCustomerSwitch(2),null);
});

test('Aynı müşteriyi ilk ayar seçiminden açmak çalışma alanı geçişini başlatmaz',async()=>{
 for(const file of ['app.js','demo/app.js']){
  const line=readFileSync(new URL('../public/'+file,import.meta.url),'utf8').split('\n').find(row=>row.startsWith('configureCustomerSwitch('));
  const state={company:'1'},calls=[],opened=[],companies=[{id:1,name:'Birinci'},company];
  Function('state','configureCustomerSwitch','changeWorkspace',line)(state,configureCustomerSwitch,(...args)=>{calls.push(args);return Promise.resolve(true);});
  try{
   const select={value:'1',isConnected:true};
   const selectorSource=readFileSync(new URL('../public/customer-management-selector.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace(/export /g,'');
   const bind=Function('requestCustomerSwitch','canLeaveDialogDrafts',selectorSource+';return bindManagementCompany;')(requestCustomerSwitch,async()=>true);
   bind({querySelector:()=>select},companies,null,{current:()=>true,onSelect:async id=>opened.push(id)});
   await select.onchange();assert.deepEqual(opened,[1]);assert.deepEqual(calls,[],'Aynı müşteride açılış örtüsü ve sayfa yüklemesi olmamalı');
   const source=readFileSync(new URL('../public/customer-permission-store.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace(/export /g,'');
   const choose=Function('html','esc','t','openDialog','requestCustomerSwitch','closeDialogThen',source+';return choosePermissionStore;')(
    (parts,...values)=>typeof parts==='string'?parts:parts.reduce((s,p,i)=>s+p+(values[i]??''),''),x=>x,x=>x,()=>({querySelector:()=>select}),requestCustomerSwitch,async(d,work)=>work());
   choose({companies},async id=>opened.push(id));select.value='1';await select.onchange();assert.deepEqual(opened,[1,1]);assert.deepEqual(calls,[]);
   select.value='2';await select.onchange();assert.deepEqual(calls,[[2,{view:'customers',interactive:true}]]);assert.deepEqual(opened,[1,1]);
  }finally{configureCustomerSwitch(null);}
 }
});

test('Gerçek uygulama denetleyicisi karşılama örtüsünü kapatmadan müşteriyi yükler',async()=>{
 const source=readFileSync(new URL('../public/app.js',import.meta.url),'utf8'),start=source.indexOf('async function changeWorkspace('),end=source.indexOf('\nasync function navigate(',start);
 const state={user:{id:10,role:'admin'},company:1,view:'inventory',data:{},companies:[{id:1,name:'Birinci'},company]},beforeProfiles=[{mode:'horoz'},{mode:'horoz',marketplaceManagement:true}],copy=structuredClone(beforeProfiles);let coverOpen=false,sharedOpen=true,loads=0;
 const shared={close(){sharedOpen=false;}},cover={close(){coverOpen=false;}};
 const deps={state,workspaceToken,sameWorkspace,selectCustomerWorkspace,app:{innerHTML:'old customer',querySelector:()=>null},document:{querySelector:selector=>selector==='#dialog[open]'?(sharedOpen?shared:null):(sharedOpen?shared:(coverOpen?cover:null))},canLeaveDialogDrafts:async()=>true,setActionContext(){},i18nHtml:value=>value,esc:x=>x,load:async()=>{loads++;},resetPageScroll(){},showEntry(){assert.fail('Yükleme başarılı olmalı');},toast(){},setCsrf(){},loginScreen(){},transitionCustomer:(c,options)=>transitionCustomer(c,{...options,ask:async()=>true,authorize:async()=>true,cover:async(c,work)=>{coverOpen=true;const result=await work();assert.equal(coverOpen,true,'Ortak pencereyi kapatma karşılama örtüsünü kapatmamalı');coverOpen=false;return result;}})};
 const change=Function(...Object.keys(deps),'let workspaceTransition=false,loadVersion=0;'+source.slice(start,end)+';return changeWorkspace;')(...Object.values(deps));
 assert.equal(await change(2,{view:'customers',interactive:true}),true);assert.equal(state.company,2);assert.equal(state.view,'customers');assert.equal(loads,1);assert.equal(sharedOpen,false);assert.deepEqual(beforeProfiles,copy);
 // Global selector normally has no open settings dialog: cover is the only top layer.
 assert.equal(await change(1,{view:'customers',interactive:true}),true);assert.equal(state.company,1);assert.equal(state.view,'customers');assert.equal(loads,2);
});

test('Şifre iptal edilir veya doğrulama reddedilirse taslak korumasına ve müşteriye dokunulmaz',async()=>{
 const calls=[];assert.equal(await transitionCustomer(company,{ask:async()=>true,authorize:async()=>false,canLeave:async()=>calls.push('draft'),switchWorkspace:async()=>calls.push('switch'),cover:async()=>calls.push('cover')}),false);assert.deepEqual(calls,[]);
});
