import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {beginSaveFeedback,finishSaveFeedback} from '../public/save-feedback.js';

function fixture(){
 let decide=async()=>false;const prompts=[];
 const field={name:'name',type:'text',value:'İlk değer',labels:[]};
 const form={elements:[field],onsubmit:async()=>true,querySelector:()=>null,checkValidity:()=>true};
 const dialog={open:true,contains:item=>item===form,querySelectorAll:()=>[form],addEventListener(){},removeEventListener(){}};
 const document={getElementById:()=>dialog};
 const source=readFileSync(new URL('../public/dialog-drafts.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
 const api=Function('beginSaveFeedback','finishSaveFeedback','i18nT','confirmPendingChanges','MutationObserver','queueMicrotask','document',source+';return {watchDialogDrafts,canLeaveDialogDrafts,acceptDialogFieldDefaults};')(
  beginSaveFeedback,finishSaveFeedback,x=>x,async options=>{prompts.push(options);return decide(options);},class{observe(){}disconnect(){}},fn=>fn(),document);
 form.closest=()=>dialog;api.watchDialogDrafts(dialog,'Firma');
 return {...api,dialog,form,field,prompts,decide:fn=>decide=fn};
}
test('Ayar sekmesinde kaydetmeden devam değeri geri alır, sonraki değişikliği yine korur',async()=>{
 const f=fixture();f.field.value='Taslak';
 assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),false);assert.equal(f.field.value,'Taslak');
 f.decide(options=>options.discard());assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),true);assert.equal(f.field.value,'İlk değer');
 f.field.value='İkinci taslak';f.decide(async()=>false);assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),false);
});

test('Asenkron şifre önerisi diğer kullanıcı değişikliklerini kaydedilmiş saymaz',async()=>{
 const f=fixture();const password={name:'password',type:'password',value:'',labels:[]};f.form.elements.push(password);
 f.watchDialogDrafts(f.dialog,'Firma');f.field.value='Kullanıcının taslağı';password.value='Example.1*';
 f.acceptDialogFieldDefaults(f.form,['password']);
 assert.equal(await f.canLeaveDialogDrafts(),false);assert.equal(f.prompts.length,1);
 f.field.value='İlk değer';assert.equal(await f.canLeaveDialogDrafts(),true);
});
test('Sekmeden önce Kaydet ve devam başarılı değeri korur, hata çıkışı durdurur',async()=>{
 const f=fixture();f.field.value='Kaydedilecek';let writes=0;
 f.form.onsubmit=async()=>{writes++;return false;};f.decide(options=>options.save());
 await assert.rejects(f.canLeaveDialogDrafts({withinDialog:true}),/Kayıt tamamlanamadı/);assert.equal(f.field.value,'Kaydedilecek');assert.equal(writes,1);
 f.form.onsubmit=async()=>{writes++;return true;};assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),true);assert.equal(f.field.value,'Kaydedilecek');
 assert.equal(await f.canLeaveDialogDrafts({withinDialog:true}),true);assert.equal(writes,2);
});
