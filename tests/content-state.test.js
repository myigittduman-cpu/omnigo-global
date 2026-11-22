import test from 'node:test';
import assert from 'node:assert/strict';
import {beginContentLoad} from '../public/content-state.js';

function region(minBlockSize,height){
 const attributes=new Map();
 return {
  attributes,style:{minBlockSize},height,childElementCount:1,innerHTML:'<table></table>',
  querySelector(){return null;},
  getBoundingClientRect(){return {height:this.height};},
  setAttribute(name,value){attributes.set(name,value);},
  removeAttribute(name){attributes.delete(name);},
 };
}

test('Üst üste yenilemeler ilk ölçüyü korur ve en güncel istek özgün ölçüyü geri yükler',()=>{
 const target=region('12rem',316.5);
 const finishFirst=beginContentLoad(target);
 assert.equal(target.style.minBlockSize,'317px');
 target.height=500;
 const finishSecond=beginContentLoad(target);
 finishFirst();
 assert.equal(target.style.minBlockSize,'317px');
 assert.equal(target.attributes.get('aria-busy'),'true');
 assert.equal(target.innerHTML,'<table></table>');
 finishSecond();
 assert.equal(target.style.minBlockSize,'12rem');
 assert.equal(target.attributes.has('aria-busy'),false);
});

test('Geç biten eski istek bir sonraki yenilemenin yüksekliğine ve durumuna dokunmaz',()=>{
 const target=region('',97);
 target.childElementCount=0;
 const finishOld=beginContentLoad(target,'İlk okuma');
 assert.equal(target.style.minBlockSize,'180px');
 const finishLatest=beginContentLoad(target,'Yeni okuma');
 assert.match(target.innerHTML,/Yeni okuma/);
 finishLatest();
 assert.equal(target.style.minBlockSize,'');
 target.height=242;
 const finishNext=beginContentLoad(target,'Son okuma');
 finishOld();
 assert.equal(target.attributes.get('aria-busy'),'true');
 assert.equal(target.style.minBlockSize,'242px');
 finishNext();
 assert.equal(target.style.minBlockSize,'');
});
