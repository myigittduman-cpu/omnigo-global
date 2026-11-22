import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createValueDraftGuard} from '../public/value-draft-guard.js';
import {pageActionUrl} from '../public/page-action-url.js';

function pageBuilder({items=[{slug:'duyuru',title:'Duyuru',content:'Örnek',companyIds:[],role:'both',menuGroup:'management',layout:'standard',scope:'all'}],failSave=false,saveBarrier}={}){
 const buttons=new Map(),control=key=>{if(!buttons.has(key))buttons.set(key,{});return buttons.get(key);};
 const form={values:structuredClone(items[0]??{}),elements:Object.fromEntries(['role','menuGroup','layout','scope'].map(name=>[name,{}])),querySelector:selector=>control('form:'+selector),querySelectorAll:()=>[]};
 const body={innerHTML:'',querySelectorAll:()=>[],querySelector:selector=>selector==='[data-page-editor]'?(body.innerHTML.includes('<form data-page-editor>')?form:null):control(selector)};
 const dialog={querySelector:()=>body,setAttribute(){}};
 const model={draft:{items:structuredClone(items),version:3},published:{items:structuredClone(items),version:2},previous:{items:[],version:1}};
 const calls=[],messages=[];
 const api=async(url,method='GET',data)=>{
  calls.push({url,method,data:structuredClone(data)});
  if(method==='GET')return structuredClone(model);
  if(method==='PUT'){
   if(saveBarrier)await saveBarrier;
   if(failSave)throw Error('Taslak kaydedilemedi.');
   assert.equal(data.version,model.draft.version);
   model.draft={items:structuredClone(data.items),version:model.draft.version+1};
   return structuredClone(model.draft);
  }
  if(url.endsWith('/publish')){
   assert.equal(data.version,model.draft.version);
   model.published={items:structuredClone(model.draft.items),version:model.published.version+1};
   return {published:structuredClone(model.published)};
  }
  throw Error('Beklenmeyen istek: '+url);
 };
 const source=fs.readFileSync(new URL('../public/application-page-builder.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export async function','async function').replaceAll('export function','function');
 class TestFormData{constructor(form){this.entries=Object.entries(form.values);}[Symbol.iterator](){return this.entries[Symbol.iterator]();}}
 const context=vm.createContext({api,openDialog:()=>dialog,esc:value=>String(value??''),toast:message=>messages.push(message),FormData:TestFormData,createValueDraftGuard,pageActionUrl});
 vm.runInContext(source,context);
 return {open:()=>context.openApplicationPageBuilder([]),remove:()=>control('form:[data-delete]').onclick(),publish:()=>control('[data-publish]').onclick(),create:()=>control('[data-new-page]').onclick(),preview:()=>control('form:[data-preview]').onclick(),back:()=>control('[data-back-editor]').onclick(),form,calls,model,messages};
}

test('son sayfa silinince boş taslak kaydedilir ve yeni sürümle boş yayın oluşturulur',async()=>{
 const builder=pageBuilder();await builder.open();builder.remove();await builder.publish();
 assert.deepEqual(builder.calls.map(({method})=>method),['GET','PUT','POST']);
 assert.equal(builder.calls[1].data.items.length,0);
 assert.equal(builder.calls[2].data.version,4);
 assert.equal(builder.model.draft.items.length,0);
 assert.equal(builder.model.published.items.length,0);
});

test('boş taslak kaydı başarısızsa eski taslak yayımlanmaz',async()=>{
 const builder=pageBuilder({failSave:true});await builder.open();builder.remove();await builder.publish();
 assert.deepEqual(builder.calls.map(({method})=>method),['GET','PUT']);
 assert.equal(builder.model.published.items.length,1);
 assert.ok(builder.messages.includes('Taslak kaydedilemedi.'));
});

test('başlangıçta boş olan taslak da yayımdan önce kaydedilir',async()=>{
 const builder=pageBuilder({items:[]});await builder.open();await builder.publish();
 assert.deepEqual(builder.calls.map(({method})=>method),['GET','PUT','POST']);
 assert.equal(builder.model.published.items.length,0);
});

test('normal sayfa yayımlama akışı da kaydedilen taslak sürümünü kullanır',async()=>{
 const builder=pageBuilder();await builder.open();await builder.publish();
 assert.deepEqual(builder.calls.map(({method})=>method),['GET','PUT','POST']);
 assert.equal(builder.calls[1].data.items.length,1);
 assert.equal(builder.calls[2].data.version,4);
 assert.equal(builder.model.published.items[0].slug,'duyuru');
});

test('yayımlama sürerken ikinci tıklama yeni kayıt veya yayın başlatmaz',async()=>{
 let release;const saveBarrier=new Promise(resolve=>release=resolve),builder=pageBuilder({saveBarrier});
 await builder.open();const first=builder.publish(),second=builder.publish();
 assert.deepEqual(builder.calls.map(({method})=>method),['GET','PUT']);
 release();await Promise.all([first,second]);
 assert.deepEqual(builder.calls.map(({method})=>method),['GET','PUT','POST']);
});

test('yeni sayfaya geçerken mevcut formdaki düzenleme yerel taslakta korunur',async()=>{
 const builder=pageBuilder();await builder.open();builder.form.values.title='Düzenlenen başlık';builder.create();
 assert.equal(builder.calls.length,1,'Kullanıcı kaydetmeden API yazması yapılmaz.');
 await builder.publish();
 assert.equal(builder.calls[1].data.items[0].title,'Düzenlenen başlık');
 assert.equal(builder.calls[1].data.items.length,2);
});

test('önizlemeden düzenleyiciye dönüş kaydedilmemiş içeriği korur',async()=>{
 const builder=pageBuilder();await builder.open();builder.form.values.title='Önizlenen başlık';builder.preview();builder.back();await builder.publish();
 assert.equal(builder.calls[1].data.items[0].title,'Önizlenen başlık');
});
