import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

function fixture({request,current=()=>true,configured=true}={}){
 const inputs=['apiKey','apiSecret'].map(name=>({name,type:'password',value:'',readOnly:false,focus(){}}));
 const button=()=>({disabled:false,hidden:false,textContent:''});
 const controls=Object.fromEntries(['show','edit','cancel','status'].map(key=>[key,button()]));
 const bar={querySelector:selector=>controls[selector.match(/data-credential-(\w+)/)[1]]};
 const submit=button(),listeners={},dialog={addEventListener:(name,fn)=>listeners[name]=fn};
 const form={isConnected:true,querySelectorAll:()=>inputs,querySelector:()=>submit,prepend(){},closest:()=>dialog,addEventListener:(name,fn)=>listeners[name]=fn};
 const calls=[],status={configured,version:1};
 const api=async(...args)=>{calls.push(args);return request?request(...args):{company:1,provider:'n11',version:3,fields:{apiKey:'saved-key',apiSecret:'saved-secret'}};};
 const code=readFileSync(new URL('../public/credential-editor.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export function ','function ');
 const visibility=new Map();
 const attachCredentialVisibility=(input,onToggle)=>{const toggle={onToggle,visible:false,busy:false,update(visible,busy){this.visible=visible;this.busy=busy;}};visibility.set(input.name,toggle);return toggle;};
 const bind=Function('api','t','html','document','attachCredentialVisibility',code+';return bindCredentialEditor;')(api,x=>x,strings=>strings.join(''),{createElement:()=>bar},attachCredentialVisibility);
 const editor=bind(form,{provider:'n11',company:1,status,current});
 const attemptSubmit=()=>{let blocked=false;listeners.submit({preventDefault(){blocked=true;},stopImmediatePropagation(){}});return blocked;};
 return {editor,inputs,submit,controls,calls,status,visibility,attemptSubmit,close:()=>listeners.close()};
}
test('Kayıtlı API bilgileri açıkça gösterilene kadar indirilmez; düzenlemeden kaydedilemez',async()=>{
 const f=fixture();assert.equal(f.calls.length,0);assert(f.submit.disabled);assert(f.attemptSubmit());
 await f.controls.show.onclick();assert.equal(f.calls.length,1);assert.equal(f.calls[0][1],'POST');
 assert(f.inputs.every(x=>x.readOnly&&x.type==='text'));assert(f.submit.disabled);assert(f.attemptSubmit());
 assert.equal(f.status.version,3);
 await f.controls.edit.onclick();assert(f.inputs.every(x=>!x.readOnly));assert(!f.submit.disabled);assert(!f.attemptSubmit());
 f.inputs[0].value='unsaved';f.controls.cancel.onclick();
 assert.equal(f.inputs[0].value,'saved-key');assert(f.inputs.every(x=>x.readOnly&&x.type==='password'));assert(f.submit.disabled);
 assert.equal(f.calls.length,1,'Göster, düzenle ve vazgeç API kaydı yapmamalı');
 f.close();assert(f.inputs.every(x=>x.value===''));
});
test('API cevabı beklenirken müşteri değişirse bilgiler gösterilmez',async()=>{
 let active=true,resolve;
 const f=fixture({current:()=>active,request:()=>new Promise(r=>resolve=r)});
 const pending=f.controls.show.onclick();active=false;
 resolve({company:1,provider:'n11',version:1,fields:{apiKey:'hidden',apiSecret:'hidden'}});await pending;
 assert(f.inputs.every(x=>x.value===''));assert(f.attemptSubmit());
});
test('Yanlış şirkete ait API cevabı forma yazılmaz',async()=>{
 const f=fixture({request:async()=>({company:2,provider:'n11',version:1,fields:{apiKey:'other'}})});
 await f.controls.show.onclick();assert(f.inputs.every(x=>x.value===''));assert(f.submit.disabled);assert(f.controls.status.textContent);
});
test('Yeni bağlantı formu düzenlenebilir; ilk kayıttan sonra salt okunur olur',()=>{
 const f=fixture({configured:false});assert(!f.submit.disabled);assert(!f.attemptSubmit());
 f.editor.reset();assert(f.submit.disabled);assert(f.attemptSubmit());
});

test('Alan gözü kayıtlı şifreyi salt okunur gösterir; düzenlenen değeri değiştirmeden gizler',async()=>{
 const f=fixture(),eye=f.visibility.get('apiSecret');
 await eye.onToggle();assert.equal(f.inputs[1].type,'text');assert.equal(f.inputs[0].type,'password');assert(f.inputs[1].readOnly);assert(f.submit.disabled);
 await f.controls.edit.onclick();f.inputs[1].value='unsaved-secret';await eye.onToggle();
 assert.equal(f.inputs[1].value,'unsaved-secret');assert.equal(f.inputs[1].type,'password');assert.equal(f.calls.length,1);
 await eye.onToggle();f.controls.cancel.onclick();assert.equal(f.inputs[1].type,'password');assert.equal(eye.visible,false);
 f.close();assert.equal(f.inputs[1].value,'');
});

test('Yeni API alanının gözü kayıtlı bilgi istemeden yazılan değeri gösterir',async()=>{
 const f=fixture({configured:false});f.inputs[1].value='new-secret';await f.visibility.get('apiSecret').onToggle();
 assert.equal(f.inputs[1].type,'text');assert.equal(f.inputs[1].value,'new-secret');assert.equal(f.calls.length,0);assert(!f.submit.disabled);
});

test('Alan gözü yanıt beklerken müşteri değişirse şifreyi göstermez',async()=>{
 let active=true,resolve;
 const f=fixture({current:()=>active,request:()=>new Promise(r=>resolve=r)});
 const pending=f.visibility.get('apiSecret').onToggle();active=false;
 resolve({company:1,provider:'n11',version:1,fields:{apiSecret:'other-secret'}});await pending;
 assert.equal(f.inputs[1].value,'');assert.equal(f.inputs[1].type,'password');
});
