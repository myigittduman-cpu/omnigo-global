import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../public/dialog-view.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
function fixture(){
 const parent={scrollTop:218},focus={isConnected:true,focus(){}},handlers=new Map();let restored=0,disposed=0,closed=0;
 const dialog={open:true,childNodes:[parent],className:'settings-dialog',style:{},onclick(){},getAttribute:()=>null,getBoundingClientRect:()=>({width:1000,height:700}),querySelector:()=>parent,addEventListener:(name,fn)=>handlers.set(name,fn),removeEventListener:name=>handlers.delete(name),removeAttribute(){this.style={};},replaceChildren(...nodes){this.childNodes=nodes;},close(){closed++;this.open=false;handlers.get('close')?.();}};
 const preserve=Function('suspendDialogDrafts','revealContent','document',source+';return preserveDialogView;')(()=>({restore:()=>restored++,dispose:()=>disposed++}),()=>{}, {activeElement:focus});
 return {dialog,parent,preserve,stats:()=>({restored,disposed,closed})};
}
test('back restores the same parent nodes and scroll without closing or refetching',()=>{
 const f=fixture(),back=f.preserve(f.dialog);f.dialog.childNodes=[{}];f.dialog.className='account-directory-editor';assert.equal(back(),true);assert.equal(f.dialog.childNodes[0],f.parent);assert.equal(f.parent.scrollTop,218);assert.equal(f.dialog.className,'settings-dialog');assert.deepEqual(f.stats(),{restored:1,disposed:0,closed:0});assert.equal(back(),false);
});
test('an actual X close disposes the detached parent and cannot reopen it',()=>{
 const f=fixture(),back=f.preserve(f.dialog);f.dialog.close();assert.equal(back(),false);assert.deepEqual(f.stats(),{restored:0,disposed:1,closed:1});
});
