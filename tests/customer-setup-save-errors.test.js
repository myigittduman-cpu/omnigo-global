import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {connectionForm} from '../public/customer-setup-view.js';
import {setupProviders} from '../public/customer-api-help.js';

const source=path=>readFileSync(new URL(path,import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');

async function fixture({failure=null,storeName='Test mağazası'}={}){
 const makeForm=names=>{
  const fields=names.map(name=>({name,type:name==='apiSecret'||name==='apiKey'?'password':'text',value:'',labels:[]}));
  const error={textContent:''},button={disabled:false};
  const form={elements:fields,error,button,resetCount:0,checkValidity:()=>true,
   reset(){this.resetCount++;for(const field of fields)field.value='';},
   querySelector(selector){if(selector==='[type=submit]')return button;if(selector==='button[type=submit]:disabled')return button.disabled?button:null;if(selector==='[data-provider-error]'||selector==='.form-error,[role=alert]')return error;return null;}
  };
  for(const field of fields)form.elements[field.name]=field;
  return form;
 };
 const store=makeForm(['storeName']),credentials=makeForm(['supplierId','apiKey','apiSecret']);
 store.elements.storeName.value=storeName;
 const status={textContent:''},message={textContent:''},copy={};
 const controls={'[data-connection-status]':status,'[data-provider-message]':message,'[data-setup-store]':store,'[data-setup-credentials]':credentials,'[data-copy-template]':copy};
 const card={dataset:{setupProvider:'trendyol'},querySelector:selector=>controls[selector],querySelectorAll:selector=>selector==='[data-template]'?[{},{}]:[]};
 const root={querySelector:()=>card,querySelectorAll:()=>[card]};
 const dialog={open:true,contains:form=>form===store||form===credentials,querySelectorAll:()=>[store,credentials],addEventListener(){},removeEventListener(){}};
 const calls=[],prompts=[];let nextFailure=failure,changes=0,editorResets=0;
 const api=async(...args)=>{
  calls.push(args);if(args[1]==='POST'){if(nextFailure)throw nextFailure;return {message:'Bağlantı kontrolü sıraya alındı.'};}
  return {configured:false,version:0};
 };
 const FormData=class{constructor(form){this.form=form;}*[Symbol.iterator](){for(const field of this.form.elements)yield [field.name,field.value];}};
 const mount=Function('api','bindCredentialEditor','setupProviders','connectionForm','i18nT','FormData',source('../public/customer-setup-connections.js')+';return mountSetupConnections;')(
  api,()=>({reset(){editorResets++;}}),setupProviders,connectionForm,x=>x,FormData
 );
 await mount(root,{company:{id:1},markets:[{provider:'trendyol',selected:true,storeName,version:0}],current:()=>true,onChange:()=>changes++});
 const drafts=Function('beginSaveFeedback','finishSaveFeedback','i18nT','confirmPendingChanges','MutationObserver','queueMicrotask','document',source('../public/dialog-drafts.js')+';return {watchDialogDrafts,canLeaveDialogDrafts};')(
  ()=>({}),()=>{},x=>x,async options=>{prompts.push(options);return options.save();},class{observe(){}disconnect(){}},fn=>fn(),{getElementById:()=>dialog}
 );
 drafts.watchDialogDrafts(dialog,'Pazaryeri API bağlantısı');
 const values={supplierId:'12345',apiKey:'test-api-key',apiSecret:'test-api-secret'};
 const enterValues=()=>{for(const [name,value] of Object.entries(values))credentials.elements[name].value=value;};
 return {...drafts,credentials,store,message,calls,prompts,values,enterValues,setFailure:error=>nextFailure=error,changes:()=>changes,editorResets:()=>editorResets};
}

test('Her bağlantı formu hatayı kendi alanında, başarıyı ayrı durum alanında gösterir',()=>{
 for(const provider of setupProviders){
  const html=connectionForm(provider,{storeName:'Test',version:0},{configured:false},{name:'Test'});
  const forms=[...html.matchAll(/<form\b[^>]*>([\s\S]*?)<\/form>/g)];
  assert.equal(forms.length,2);
  for(const [,body] of forms)assert.match(body,/<p[^>]*data-provider-error[^>]*role="alert"/);
  assert.match(html,/<\/form><p data-provider-message role="status">/);
 }
});

for(const [status,reason] of [[409,'Bağlantı değişti. Sayfayı yenileyin.'],[423,'Yönetim alanı kilitli. Omnigo Admin şifresiyle doğrulayın.'],[400,'API bağlantı alanlarını kontrol edin.']]){
 test(`API ${status} hatası Kaydet ve devam örtüsünde gerçek nedeniyle görünür; kaynak alanlar korunur`,async()=>{
  const f=await fixture({failure:Object.assign(new Error(reason),{status})});f.enterValues();
  await assert.rejects(f.canLeaveDialogDrafts({withinDialog:true}),error=>error.message===reason);
  assert.equal(f.credentials.error.textContent,reason);assert.equal(f.message.textContent,'');
  assert.deepEqual(Object.fromEntries([...f.credentials.elements].map(field=>[field.name,field.value])),f.values);
  assert.equal(f.credentials.resetCount,0);assert.equal(f.changes(),0);assert.equal(f.editorResets(),0);assert.equal(f.credentials.button.disabled,false);
  const writes=f.calls.filter(call=>call[1]==='POST');assert.equal(writes.length,1);
  assert.equal(writes[0][0],'/integrations/trendyol?company=1');assert.equal(writes[0][3],undefined,'İşlem onayı veya yönetim erişimi atlanmamalı');
  assert.equal(f.prompts.length,1);
 });
}

test('Başarısız kayıttan sonraki başarılı deneme eski hatayı temizler; başarı metni yeni hata sayılmaz',async()=>{
 const f=await fixture({failure:new Error('Bağlantı kontrolü sürüyor.')});f.enterValues();
 await assert.rejects(f.canLeaveDialogDrafts({withinDialog:true}),/Bağlantı kontrolü sürüyor/);
 f.setFailure(null);assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),true);
 assert.equal(f.credentials.error.textContent,'');assert.match(f.message.textContent,/API bilgileri kaydedildi/);
 assert.equal(f.credentials.resetCount,1);assert.equal(f.changes(),1);assert.equal(f.editorResets(),1);
 assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),true);assert.equal(f.prompts.length,2,'Başarılı kayıt tekrar kaydetmeye zorlanmamalı');
 assert.equal(f.calls.filter(call=>call[1]==='POST').length,2);
});

test('Henüz mağaza adı kaydedilmemişse ağ çağrısı yapmadan açık neden gösterilir',async()=>{
 const f=await fixture({storeName:''});f.enterValues();
 await assert.rejects(f.canLeaveDialogDrafts({withinDialog:true}),/Önce mağaza adını kaydedin/);
 assert.equal(f.credentials.error.textContent,'Önce mağaza adını kaydedin.');assert.equal(f.credentials.resetCount,0);
 assert.equal(f.calls.filter(call=>call[1]==='POST').length,0);
});

test('Mağaza adı formundaki hata da kaydetme korumasına taşınır ve düzenlenen ad korunur',async()=>{
 const f=await fixture({failure:new Error('Mağaza bilgileri başka bir yetkili tarafından değiştirildi.')});
 f.store.elements.storeName.value='Güncellenen mağaza';
 await assert.rejects(f.canLeaveDialogDrafts({withinDialog:true}),/Mağaza bilgileri başka bir yetkili tarafından değiştirildi/);
 assert.equal(f.store.error.textContent,'Mağaza bilgileri başka bir yetkili tarafından değiştirildi.');
 assert.equal(f.store.elements.storeName.value,'Güncellenen mağaza');assert.equal(f.store.button.disabled,false);assert.equal(f.changes(),0);
 const writes=f.calls.filter(call=>call[1]==='POST');assert.equal(writes.length,1);assert.equal(writes[0][0],'/management/marketplaces/trendyol?company=1');
});
