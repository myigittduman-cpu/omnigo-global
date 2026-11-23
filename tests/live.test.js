import test from 'node:test';
import assert from 'node:assert/strict';
import {startLiveRefresh,watchWebVersion} from '../public/live.js';

test('Yenileme: açık form korunur, hatada bekleme artar, sürüm uyarısı kullanıcı onayını bekler',async()=>{
 const saved=Object.fromEntries(['document','window','navigator','setTimeout','setInterval','clearTimeout','clearInterval','requestAnimationFrame','fetch','location','sessionStorage'].map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)]));
 let scheduled,delay,refreshCount=0,dialog=false,paymentDetails=false,fail=false,version='1.0.0',reloadCount=0;
 const intervals=new Map(),storage=new Map(),button={focus(){}},title={},message={},check={},progress={firstElementChild:{style:{}},classList:{add(){},remove(){}},removeAttribute(){},setAttribute(){}};
 const notice={id:'webUpdateDialog',dataset:{},open:false,addEventListener(){},showModal(){this.open=true;},querySelector:selector=>({'h2':title,'[role=status]':message,'button':button,'[role=progressbar]':progress,'.web-update-check':check})[selector]};
 const status={textContent:''};
 const element=()=>({children:[],setAttribute(){},append(...nodes){this.children.push(...nodes);}});
 const mocks={
  document:{hidden:false,documentElement:{dataset:{}},getElementById:id=>id==='webUpdateDialog'?notice:status,querySelector:selector=>dialog&&(!paymentDetails||!selector.includes(':not(.payment-day-dialog,.received-payments-dialog)'))?{}:null,querySelectorAll:selector=>selector==='dialog[open]'&&dialog?[{id:'draft'}]:[],addEventListener(){},removeEventListener(){},createElement:element},
  window:{addEventListener(){}},navigator:{onLine:true},
  requestAnimationFrame:callback=>queueMicrotask(callback),
  setTimeout:(fn,ms)=>{scheduled=fn;delay=ms;return 1;},setInterval:(fn,ms)=>{intervals.set(ms,fn);return ms;},clearTimeout(){},clearInterval:ms=>intervals.delete(ms),
  sessionStorage:{getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value)},
  fetch:async()=>({ok:true,json:async()=>({version})}),location:{reload(){reloadCount++;}}
 };
 for(const [key,value]of Object.entries(mocks))Object.defineProperty(globalThis,key,{configurable:true,writable:true,value});
 try{
  startLiveRefresh({isSignedIn:()=>true,refresh:async()=>{refreshCount++;if(fail)throw new Error('offline');},notify(){}});
  assert.equal(delay,30000);dialog=true;await scheduled();assert.equal(refreshCount,0);
  paymentDetails=true;await scheduled();assert.equal(refreshCount,1,'Açık hakediş ayrıntıları veriyi almaya devam eder');
  paymentDetails=false;
  dialog=false;await scheduled();assert.equal(refreshCount,2);assert.equal(status.textContent,'');assert.equal(status.hidden,true);
  fail=true;await scheduled();assert.equal(delay,60000);assert.match(status.textContent,/güncel olmayabilir/);assert.equal(status.hidden,false);
  await watchWebVersion();assert.equal(notice.open,false);
  dialog=true;version='1.0.1';await intervals.get(60000)();assert.equal(notice.open,false,'Açık taslak güncelleme penceresiyle kesilmez');
  dialog=false;await intervals.get(60000)();assert.equal(notice.open,true);assert.equal(reloadCount,0);
  dialog=true;await button.onclick();assert.equal(reloadCount,0,'Onay anında açılan işlem de korunur');
  assert.equal(notice.dataset.phase,'error');assert.match(message.textContent,/Açık işleminizi/);
  dialog=false;await button.onclick();assert.equal(reloadCount,1);
  assert.equal(mocks.document.documentElement.dataset.omnigoWebUpdating,'true');
  assert.equal(JSON.parse(storage.get('omnigo.web-update.v1')).version,'1.0.1');
 }finally{for(const [key,descriptor]of Object.entries(saved)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}
});
