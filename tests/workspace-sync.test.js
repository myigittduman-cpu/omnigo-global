import test from 'node:test';
import assert from 'node:assert/strict';
import {startWorkspaceSync} from '../public/workspace-sync.js';
test('İki panel: küçük sürüm sorgusu, değişiklikte yenileme, form koruması ve hata beklemesi',async()=>{
 const keys=['document','window','navigator','fetch','setTimeout','clearTimeout'];const saved=Object.fromEntries(keys.map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)]));
 let tick,delay,revision='1',blocked=false,fail=false,refreshes=0,requests=0;
 const state={user:{id:1},company:1,view:'overview',month:'2026-10'};
 const mocks={document:{hidden:false,querySelector:()=>blocked?{}:null,addEventListener(){},activeElement:null},window:{addEventListener(){}},navigator:{onLine:true},fetch:async()=>{requests++;if(fail)throw Error('offline');return {ok:true,json:async()=>({revision})};},setTimeout:(fn,ms)=>{tick=fn;delay=ms;return 1;},clearTimeout(){}};
 for(const [k,value] of Object.entries(mocks))Object.defineProperty(globalThis,k,{configurable:true,value});
 try{
  const stop=startWorkspaceSync({state,isSignedIn:()=>true,refresh:async()=>{refreshes++;}});
  assert.equal(delay,1500);await tick();assert.equal(refreshes,1);await tick();assert.equal(refreshes,1);
  revision='2';blocked=true;await tick();assert.equal(requests,3);assert.equal(refreshes,1);blocked=false;await tick();assert.equal(refreshes,2);
  state.company=2;await tick();assert.equal(refreshes,3);fail=true;await tick();assert.equal(delay,3000);fail=false;await tick();assert.equal(delay,1500);
  stop();const before=requests;await tick();assert.equal(requests,before);
 }finally{for(const [k,d] of Object.entries(saved)){if(d)Object.defineProperty(globalThis,k,d);else delete globalThis[k];}}
});
