import test from 'node:test';
import assert from 'node:assert/strict';
import {observePageHeights,syncTablePageHeights} from '../public/table-page-height.js';

const sizeProperty='--pagination-frame-height';

function events(){
 const handlers=new Map();
 return {
  addEventListener(type,fn){handlers.set(type,fn);},
  removeEventListener(type,fn){if(handlers.get(type)===fn)handlers.delete(type);},
  emit(type,target){handlers.get(type)?.({target});},
 };
}

// Elements match only the selectors used by the particular control in these fixtures.
function element(parent,...selectors){
 const attributes=new Map(),properties=new Map();
 return {
  parent,isConnected:true,height:0,
  matches(selector){return selector.split(',').some(part=>selectors.includes(part.trim()));},
  closest(selector){return this.matches(selector)?this:this.parent?.closest(selector)??null;},
  contains(child){for(let node=child;node;node=node.parent)if(node===this)return true;return false;},
  style:{setProperty:(key,value)=>properties.set(key,value),removeProperty:key=>properties.delete(key)},
  setAttribute:(key,value)=>attributes.set(key,value),removeAttribute:key=>attributes.delete(key),hasAttribute:key=>attributes.has(key),
  getBoundingClientRect(){return {height:this.height};},
  get reservedSize(){return properties.get(sizeProperty);},
 };
}

function screen(){
 const view={...events(),innerWidth:1200,innerHeight:820},doc={defaultView:view};
 const root={...element(null),...events(),ownerDocument:doc};
 return {root,doc,view,controller:observePageHeights(root)};
}

function orderList(parent,initialHeight){
 const scope=element(parent,'[data-orders]'),button=element(scope,'button[data-orders-page]');
 let frame;
 function replaceFrame(height){
  if(frame)frame.isConnected=false;
  frame=height===null?null:element(scope);
  if(frame)frame.height=height;
  return frame;
 }
 scope.querySelector=selector=>selector==='.orders-table-wrap'?frame:null;
 return {scope,button,frame:replaceFrame(initialHeight),replaceFrame};
}

test('Sayfalama ilk görünür yüksekliği korur; tekrarlar ve yenilenen tablo bu ölçüyü büyütmez',()=>{
 const {root,doc,controller}=screen(),list=orderList(root,412.5);
 assert.equal(list.frame.reservedSize,undefined);
 root.emit('click',list.button);
 assert.equal(list.frame.reservedSize,'412.5px');
 list.frame.height=800;
 root.emit('click',list.button);
 assert.equal(list.frame.reservedSize,'412.5px');
 // A loading/error view may temporarily remove the table before the retry succeeds.
 list.replaceFrame(null);syncTablePageHeights(doc);
 const lastPage=list.replaceFrame(86);syncTablePageHeights(doc);
 assert.equal(lastPage.reservedSize,'412.5px');
 root.emit('click',list.button);controller.sync();
 assert.equal(lastPage.reservedSize,'412.5px');
 controller.disconnect();
 assert.equal(lastPage.reservedSize,undefined);
 root.emit('click',list.button);
 assert.equal(lastPage.reservedSize,undefined);
});

test('Pazaryeri sorularında kısa son sayfaya önceki sayfanın yüksekliği taşınmaz',()=>{
 const {root,controller}=screen(),scope=element(root,'[data-marketplace-questions]');
 const button=element(scope,'button[data-inbox-page]');
 const full=element(scope),last=element(scope);
 full.height=1100;last.height=180;
 let frame=full;
 scope.querySelector=selector=>selector==='.inbox-list-scroll'?frame:null;
 root.emit('click',button);
 frame=last;full.isConnected=false;
 controller.sync();
 assert.equal(full.reservedSize,undefined);
 assert.equal(last.reservedSize,undefined);
 controller.disconnect();
});

test('Filtre ve form sıfırlamaları yalnızca ilgili listeyi etkiler; devre dışı veya görünmeyen liste kilitlenmez',()=>{
 const {root,controller}=screen(),first=orderList(root,0),second=orderList(root,180);
 root.emit('click',first.button);
 assert.equal(first.frame.reservedSize,undefined);
 first.frame.height=322;
 root.emit('click',element(first.scope,'button[data-orders-page]',':disabled'));
 assert.equal(first.frame.reservedSize,undefined);
 root.emit('click',first.button);root.emit('click',second.button);
 root.emit('change',element(first.frame,'select'));
 assert.equal(first.frame.reservedSize,'322px');
 root.emit('change',element(first.scope,'select'));
 assert.equal(first.frame.reservedSize,undefined);
 assert.equal(second.frame.reservedSize,'180px');
 first.frame.height=201;
 root.emit('click',first.button);
 assert.equal(first.frame.reservedSize,'201px');
 root.emit('submit',element(second.scope,'form'));
 assert.equal(second.frame.reservedSize,undefined);
 first.scope.isConnected=false;controller.sync();
 assert.equal(first.frame.reservedSize,undefined);
 controller.disconnect();
});

test('Mobil adres çubuğunun yüksekliği ana listeyi etkilemez; pencereyi kapatma ve genişlik değişimi ölçüyü kaldırır',()=>{
 const {root,view,controller}=screen(),page=orderList(root,570);
 const dialog=element(page.scope,'dialog'),inner=orderList(dialog,250);
 root.emit('click',page.button);root.emit('click',inner.button);
 root.emit('change',element(inner.scope,'select'));
 assert.equal(page.frame.reservedSize,'570px');
 assert.equal(inner.frame.reservedSize,undefined);
 root.emit('click',inner.button);
 view.innerHeight=740;view.emit('resize');
 assert.equal(inner.frame.reservedSize,undefined);
 assert.equal(page.frame.reservedSize,'570px');
 root.emit('click',inner.button);root.emit('close',dialog);
 assert.equal(inner.frame.reservedSize,undefined);
 assert.equal(page.frame.reservedSize,'570px');
 view.innerWidth=745;view.emit('resize');
 assert.equal(page.frame.reservedSize,undefined);
 controller.disconnect();
});
