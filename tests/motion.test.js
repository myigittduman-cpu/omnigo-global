import test from 'node:test';
import assert from 'node:assert/strict';
import {createPageMotion} from '../public/motion.js';

test('Yalnız gerçek sayfa geçişi yumuşar; arka plan yenilemesi animasyonu tekrarlamaz',()=>{
 const calls=[];
 const element={animate:()=>{calls.push('open');return {cancel:()=>calls.push('cancel'),finished:Promise.resolve()};}};
 const enter=createPageMotion();
 enter(element,'1:2:1:channels');enter(element,'1:2:1:channels');
 assert.deepEqual(calls,['open']);
 enter(element,'1:2:1:payments');
 assert.deepEqual(calls,['open','cancel','open']);
 enter(element,'1:2:2:payments');
 assert.deepEqual(calls,['open','cancel','open','cancel','open']);
});

test('Hareketi azalt tercihi ve animasyon desteği olmayan cihazlar geçişi engellemez',()=>{
 const original=globalThis.matchMedia;
 try{
  globalThis.matchMedia=()=>({matches:true});
  const enter=createPageMotion();
  enter({animate:()=>{throw new Error('Animasyon başlamamalı.');}},'channels');
  enter({},'payments');
 }finally{if(original)globalThis.matchMedia=original;else delete globalThis.matchMedia;}
});
