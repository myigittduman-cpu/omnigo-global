import test from 'node:test';
import assert from 'node:assert/strict';
import {bindLiveSearch} from '../public/live-search.js';

const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function fixture(){
 const listeners=new Map(),document={activeElement:null};
 let input,form,busy=false,current=true,submitted=[];
 const root={ownerDocument:document,querySelector:()=>form,
  addEventListener(type,fn){const list=listeners.get(type)||[];list.push(fn);listeners.set(type,list);},
  removeEventListener(type,fn){listeners.set(type,(listeners.get(type)||[]).filter(x=>x!==fn));}};
 const emit=(type,target=input)=>{for(const fn of listeners.get(type)||[])fn({target});};
 function render(){
  form={matches:selector=>selector==='[data-filters]',requestSubmit(){emit('submit',form);submitted.push(input.value);},elements:{namedItem:()=>input}};
  input={name:'query',form,value:'',disabled:busy,selectionStart:0,selectionEnd:0,selectionDirection:'none',attributes:{list:'old-searches'},
   setAttribute(key,value){this.attributes[key]=value;},removeAttribute(key){delete this.attributes[key];},
   focus(){document.activeElement=this;},setSelectionRange(start,end,direction){this.selectionStart=start;this.selectionEnd=end;this.selectionDirection=direction;}};
 }
 render();
 const search=bindLiveSearch(root,{formSelector:'[data-filters]',fieldName:'query',current:()=>current,isBusy:()=>busy,delay:10});
 return {search,emit,render,submitted,get input(){return input;},set busy(value){busy=value;},set current(value){current=value;}};
}
test('Typing debounces reads and preserves focus and selection across rendering',async()=>{
 const f=fixture();f.input.focus();f.input.value='Profilo';f.input.selectionStart=3;f.input.selectionEnd=3;f.emit('input');
 f.search.paint(f.render);assert.equal(f.input.value,'Profilo');assert.equal(f.input.selectionStart,3);
 assert.equal(f.input.autocomplete,'off');assert.equal(f.input.attributes.list,undefined);
 f.input.value='Profilo BD';f.emit('input');await wait(30);assert.deepEqual(f.submitted,['Profilo BD']);f.search.dispose();
});
test('Latest draft waits for in-flight read then searches once, including clearing',async()=>{
 const f=fixture();f.busy=true;f.input.value='old';f.emit('input');await wait(20);
 f.input.value='latest';f.emit('input');f.search.paint(f.render);
 assert.equal(f.input.disabled,false);assert.equal(f.input.value,'latest');assert.equal(f.submitted.length,0);
 f.busy=false;await wait(140);assert.deepEqual(f.submitted,['latest']);
 f.input.value='';f.emit('input');await wait(30);assert.deepEqual(f.submitted,['latest','']);f.search.dispose();
});
test('Workspace/page change cancels queued reads',async()=>{
 const f=fixture();f.busy=true;f.input.value='another company';f.emit('input');await wait(20);f.current=false;f.busy=false;
 await wait(130);assert.deepEqual(f.submitted,[]);f.search.dispose();
});
test('Does not submit during IME composition or from another form',async()=>{
 const f=fixture();f.emit('compositionstart');f.input.value='İstanbul';f.emit('input');await wait(30);assert.equal(f.submitted.length,0);
 f.emit('compositionend');await wait(30);assert.deepEqual(f.submitted,['İstanbul']);
 f.emit('input',{name:'query',form:{matches:()=>false}});await wait(30);assert.equal(f.submitted.length,1);f.search.dispose();
});
test('Manual submit and disposal cancel pending automatic submission',async()=>{
 const f=fixture();f.input.value='TV';f.emit('input');f.input.form.requestSubmit();await wait(30);assert.deepEqual(f.submitted,['TV']);
 f.input.value='cancel';f.emit('input');f.search.dispose();await wait(30);assert.deepEqual(f.submitted,['TV']);
});
