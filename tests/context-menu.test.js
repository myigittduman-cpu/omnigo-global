import test from 'node:test';
import assert from 'node:assert/strict';
import {globSync,readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const publicDir=new URL('../public/',import.meta.url);
const source=name=>readFileSync(new URL(name,publicDir),'utf8');

test('Her portal belgesi sağ tık korumasını etkileşimli içerikten önce yükler',()=>{
 const files=globSync('**/*.html',{cwd:fileURLToPath(publicDir)}).map(name=>name.replaceAll('\\','/'));
 for(const name of ['index.html','admin-index.html','demo/index.html','offline.html'])assert.ok(files.includes(name),name);
 for(const name of files){
  const markup=source(name);
  assert.equal(markup.match(/<script\b[^>]*>/i)?.[0],'<script src="/context-menu.js">',name);
  assert.match(markup.split('<script src="/context-menu.js">')[0],/^(?![\s\S]*<(body|main|form)\b)[\s\S]*$/i,name);
 }
});

test('Sağ tık ve menü tuşu tüm hedeflerde engellenir; metin ve pano olayları korunur',()=>{
 const handlers=new Map();
 const document={addEventListener(type,fn,options){assert.equal(options.capture,true);handlers.set(type,fn);}};
 runInNewContext(source('context-menu.js'),{document});
 const blocked=(type,details={})=>{
  let prevented=false;
  handlers.get(type)?.({...details,preventDefault(){prevented=true;}});
  return prevented;
 };
 assert.deepEqual([...handlers.keys()],['contextmenu','keydown']);
 for(const target of ['table','input','textarea','button','a','img','dialog']){
  assert.equal(blocked('contextmenu',{target}),true);
 }
 assert.equal(blocked('keydown',{key:'ContextMenu'}),true);
 assert.equal(blocked('keydown',{key:'F10',shiftKey:true}),true);
 for(const key of ['F10','a','c','x','v','ArrowLeft','Enter'])assert.equal(blocked('keydown',{key,ctrlKey:true}),false);
 for(const type of ['selectstart','copy','paste','cut','click','pointerdown'])assert.equal(blocked(type),false);
});

test('Tablo metinleri fareyle seçilemez; yalnız düzenlenebilir alanlar seçilebilir kalır',()=>{
 const css=source('login-visual.css');
 assert.match(css,/body table :is\(th,td\)\{-webkit-user-select:none;user-select:none;-webkit-touch-callout:none\}/);
 assert.match(css,/input:not\(\[readonly\]\):not\(:disabled\)[\s\S]*user-select:text/);
});

test('Çevrimdışı ekranın sağ tık davranışı herkese açık dosyalarla çalışır',async()=>{
 const callbacks=new Map(),saved=[];
 const self={addEventListener:(type,fn)=>callbacks.set(type,fn),skipWaiting:async()=>{},location:new URL('https://example.test')};
 const caches={open:async()=>({addAll:async items=>saved.push(...items)}),match:async key=>key};
 runInNewContext(source('sw.js'),{self,caches,URL,importScripts(){},fetch:()=>Promise.reject(new Error('Çevrimdışı'))});
 let operation;
 callbacks.get('install')({waitUntil:value=>operation=value});
 await operation;
 assert.deepEqual(saved.sort(),['/context-menu.js','/install.css','/offline.html']);
 const fallback=async key=>{
  let promise;
  callbacks.get('fetch')({request:{method:'GET',url:new URL(key,self.location).href},respondWith:value=>promise=value});
  return promise;
 };
 assert.equal(await fallback('/context-menu.js'),'/context-menu.js');
 assert.equal(await fallback('/api/city-products'),undefined);
});
