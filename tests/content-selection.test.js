import test from 'node:test';
import assert from 'node:assert/strict';
import {bindPortalContentProtection,editableContentTarget,withApplicationCopy} from '../public/login-content.js';
import {copyableTableFor,hasTableTextSelection} from '../public/table-text-selection.js';

const target=field=>({nodeType:1,closest:selector=>selector==='input,textarea,[contenteditable]'?field:null});
function rootFixture(){
 const handlers=new Map();
 const root={addEventListener:(type,handler)=>handlers.set(type,handler),removeEventListener:type=>handlers.delete(type)};
 const fire=(type,field,keys={})=>{let blocked=false;handlers.get(type)?.({target:target(field),key:'',...keys,preventDefault:()=>{blocked=true;}});return blocked;};
 return {root,handlers,fire};
}
test('Başlık, logo, salt okunur hücre ve düğmelerde normal seçim/kopyalama engellenir',()=>{
 const fixture=rootFixture();bindPortalContentProtection(fixture.root);
 for(const field of [null,{tagName:'INPUT',type:'text',readOnly:true},{tagName:'TEXTAREA',disabled:true},{tagName:'INPUT',type:'checkbox'},{tagName:'DIV',isContentEditable:false}]){
  for(const type of ['selectstart','copy','cut','dragstart'])assert.equal(fixture.fire(type,field),true);
  for(const key of ['a','C','x'])for(const modifier of ['ctrlKey','metaKey'])assert.equal(fixture.fire('keydown',field,{[modifier]:true,key}),true);
 }
});
test('Yazılabilir alanlarda seçim, kopyalama ve yapıştırma/geri davranışı korunur',()=>{
 const fixture=rootFixture();bindPortalContentProtection(fixture.root);
 for(const field of [{tagName:'INPUT',type:'text'},{tagName:'INPUT',type:'password'},{tagName:'TEXTAREA'},{tagName:'TD',isContentEditable:true}]){
  for(const type of ['selectstart','copy','cut','dragstart'])assert.equal(fixture.fire(type,field),false);
  for(const key of ['a','c','x','v','Backspace','ArrowLeft','Enter'])assert.equal(fixture.fire('keydown',field,{ctrlKey:true,key}),false);
 }
 assert.equal(fixture.fire('keydown',null,{ctrlKey:true,key:'v'}),false);
 assert.equal(editableContentTarget({nodeType:3,parentElement:target({tagName:'TD',isContentEditable:true})}),true);
});
test('Özel Kopyala düğmesi eski tarayıcı yolunda çalışır; izin dışarı sızmaz',()=>{
 const fixture=rootFixture();bindPortalContentProtection(fixture.root);
 assert.equal(withApplicationCopy(()=>fixture.fire('copy',null)),false);
 assert.equal(withApplicationCopy(()=>fixture.fire('cut',null)),true);
 assert.equal(fixture.fire('copy',null),true);
 assert.throws(()=>withApplicationCopy(()=>{throw new Error('test');}));
 assert.equal(fixture.fire('copy',null),true);
});
test('Sayfa yenilenmelerinde dinleyiciler çoğalmaz; temizleme yeniden bağlanabilir',()=>{
 const fixture=rootFixture(),dispose=bindPortalContentProtection(fixture.root);
 assert.equal(bindPortalContentProtection(fixture.root),dispose);assert.equal(fixture.handlers.size,5);
 dispose();assert.equal(fixture.handlers.size,0);
 bindPortalContentProtection(fixture.root);assert.equal(fixture.handlers.size,5);
});
test('Menü düzenleyicisinde sürükleme çalışır; diğer içerik koruması korunur',()=>{
 const fixture=rootFixture();bindPortalContentProtection(fixture.root);
 const item={nodeType:1,closest:selector=>selector==='.navigation-order-dialog [data-order-id][draggable="true"]'?item:null};
 assert.equal(fixture.fire('dragstart',null,{target:item}),false);
 assert.equal(fixture.fire('copy',null,{target:item}),true);
 assert.equal(fixture.fire('dragstart',null),true);
});

test('Yerel seçim yalnız yetkili tablonun içinde kaldığında kopyalanabilir',()=>{
 const inside={hasAttribute:()=>false,closest:selector=>selector.includes('[data-inventory]')?{}:null};
 const outside={hasAttribute:()=>false,closest:()=>null};
 const cell=table=>({nodeType:1,closest:selector=>selector==='table'?table:null});
 const selection={isCollapsed:false,rangeCount:1,anchorNode:cell(inside),getRangeAt(){return this.range;},range:{startContainer:cell(inside),endContainer:cell(inside)}};
 const root={contains:table=>table===inside,ownerDocument:{getSelection:()=>selection}};
 assert.equal(copyableTableFor(cell(outside)),null);
 assert.equal(hasTableTextSelection(root),true);
 selection.range.endContainer=cell(outside);
 assert.equal(hasTableTextSelection(root),false);
});
