import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {beginSaveFeedback,finishSaveFeedback} from '../public/save-feedback.js';

function fixture(){
 const listeners={},prompts=[];let decide=async()=>false;
 const window={addEventListener:(name,fn)=>listeners[name]=fn,removeEventListener:name=>delete listeners[name]};
 const FormData=class{constructor(form){this.values=form.elements.filter(field=>!field.disabled&&(field.type!=='checkbox'||field.checked)).map(field=>[field.name,field.value]);}[Symbol.iterator](){return this.values[Symbol.iterator]();}};
 const source=readFileSync(new URL('../public/settings-drafts.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export function ','function ');
 const create=Function('beginSaveFeedback','finishSaveFeedback','t','confirmPendingChanges','window','FormData',source+';return createSettingsDraftGuard;')(beginSaveFeedback,finishSaveFeedback,x=>x,async options=>{prompts.push(options);return decide(options);},window,FormData);
 const guard=create(),dialog={open:true};guard.attach(dialog);
 function form(type='checkbox'){
  const field={name:'choice',type,value:type==='checkbox'?'on':'tr',checked:false,disabled:false},button={disabled:false},events={};
  const form={elements:[field],isConnected:true,querySelector:()=>button,reportValidity:()=>true,addEventListener:(name,fn)=>events[name]=fn};
  return {form,field,button,change(value){if(type==='checkbox')field.checked=value;else field.value=value;events.change();},submit:()=>form.onsubmit({preventDefault(){}})};
 }
 return {guard,dialog,form,prompts,listeners,decide:fn=>decide=fn};
}

test('Oturum ve dil değişikliği Kaydet düğmesini açar; eski değere dönmek ve başarılı kayıt kapatır',async()=>{
 const f=fixture();for(const type of ['checkbox','select-one']){
  const x=f.form(type),writes=[];f.guard.track(x.form,{label:type,save:async values=>{writes.push(values);return true;}});
  assert(x.button.disabled);await x.submit();assert.equal(writes.length,0);
  x.change(type==='checkbox'?true:'en');assert(!x.button.disabled);
  await x.submit();assert(x.button.disabled);assert.equal(writes.length,1);assert.equal(writes[0].choice,type==='checkbox'?'on':'en');assert(!x.field.disabled);
  x.change(type==='checkbox'?false:'tr');assert(!x.button.disabled);x.change(type==='checkbox'?true:'en');assert(x.button.disabled);
 }
});
test('Kaydetmeden çıkışta vazgeç taslağı korur; kaydetmeden devam hiçbir veri yazmaz',async()=>{
 const f=fixture(),x=f.form();let writes=0;f.guard.track(x.form,{label:'Oturum',save:async()=>{writes++;return true;}});x.change(true);
 assert.equal(await f.guard.canLeave(),false);assert.equal(x.field.checked,true);assert.equal(writes,0);
 let blocked=false;f.listeners.beforeunload({preventDefault(){blocked=true;}});assert(blocked);
 f.decide(options=>options.discard());assert.equal(await f.guard.canLeave(),true);assert.equal(writes,0);
 x.change(false);f.decide(async()=>false);assert.equal(await f.guard.canLeave(),false,'Yeni değişiklikten sonra koruma sürmeli');
});
test('Başarısız kayıt taslağı korur, yinelenen istek ve kayıt sırasında çıkış engellenir',async()=>{
 const f=fixture(),x=f.form();let resolve,writes=0;
 f.guard.track(x.form,{label:'Görünüm',save:()=>{writes++;return new Promise(r=>resolve=r);}});x.change(true);
 const pending=x.submit();assert(x.button.disabled);assert(x.field.disabled);assert.equal(await x.submit(),false);assert.equal(await f.guard.canLeave(),false);assert.equal(writes,1);
 resolve(false);assert.equal(await pending,false);assert(!x.button.disabled);assert(!x.field.disabled);assert.equal(await f.guard.canLeave(),false);
});
test('Kaydet ve devam yalnız değişen formları kaydeder; eski sekme taslağı izlenmez',async()=>{
 const f=fixture(),x=f.form(),y=f.form('select-one');let writes=0;
 f.guard.track(x.form,{label:'Eski sekme',save:async()=>{throw Error('Eski forma yazılmamalı');}});x.change(true);x.form.isConnected=false;
 f.guard.track(y.form,{label:'Dil',save:async()=>{writes++;return true;}});y.change('de');f.decide(options=>options.save());
 assert.equal(await f.guard.canLeave(),true);assert.equal(writes,1);assert(y.button.disabled);f.guard.dispose();assert.equal(f.listeners.beforeunload,undefined);
});
