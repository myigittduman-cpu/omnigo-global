import assert from 'node:assert/strict';
import test from 'node:test';
import {bindPermissionTabs} from '../public/customer-permission-tabs.js';

const settle=()=>new Promise(resolve=>setImmediate(resolve));

function settings(){
 const buttons=[],panels=[];
 const element=()=>({
  dataset:{},attributes:{},scrollTop:0,append(){},prepend(){},before(){},after(){},focus(){},
  setAttribute(name,value){this.attributes[name]=value;},removeAttribute(name){delete this.attributes[name];},
  querySelectorAll(){return [];},querySelector(){return element();},
 });
 const body=element(),form=element();
 form.isConnected=true;form.closest=()=>body;
 globalThis.document={body,activeElement:form,createElement(tag){
  const node=element();
  if(tag==='button')buttons.push(node);
  if(tag==='section')panels.push(node);
  return node;
 }};
 return {form,buttons,panels,selected:()=>buttons.find(item=>item.attributes['aria-selected']==='true')?.dataset.permissionTab};
}

test('Mağaza ayarları hesaplarla açılır, erişim kontrolü ve hedefli Yetkiler girişi korunur',async()=>{
 const original=globalThis.document;
 try{
  let ui=settings(),grant;
  const opened=[];
  bindPermissionTabs(ui.form,{canSelect:()=>new Promise(resolve=>{grant=resolve;}),onSelect:key=>opened.push(key)});
  assert.deepEqual(ui.buttons.slice(0,3).map(item=>item.dataset.permissionTab),['accounts','sections','defaults']);
  assert.equal(ui.selected(),'accounts');
  assert.deepEqual(opened,[]);
  grant(true);await settle();
  assert.deepEqual(opened,['accounts']);
  assert.equal(ui.panels.find(p=>!p.hidden).dataset.permissionPanel,'accounts');
  ui.buttons[1].onclick();grant(true);await settle();
  assert.equal(ui.selected(),'sections');
  ui.buttons[2].onclick();grant(true);await settle();
  assert.equal(ui.selected(),'defaults');

  ui=settings();
  bindPermissionTabs(ui.form,{canSelect:async()=>false,onSelect:()=>assert.fail('Onaysız hesap dizini açıldı.')});
  await settle();assert.equal(ui.selected(),'sections');
  assert.equal(ui.form.hidden,false);

  ui=settings();
  bindPermissionTabs(ui.form,{canSelect:()=>assert.fail('Hedefli açılış hesaplar erişimini istedi.'),onSelect:()=>assert.fail()},'sections');
  assert.equal(ui.selected(),'sections');
  assert.equal(ui.panels.find(p=>!p.hidden).dataset.permissionPanel,'sections');
 }finally{if(original===undefined)delete globalThis.document;else globalThis.document=original;}
});
