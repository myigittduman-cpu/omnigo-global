import test from 'node:test';
import assert from 'node:assert/strict';
import {bindAttentionCenter} from '../public/attention-center.js';
import {clearPageSnapshots} from '../public/workspace-page-cache.js';

test('Operasyon listesi canlı kontrolde sayfayı ve mevcut DOM satırlarını korur; kullanıcı sayfaladığında tablo başına gider',async()=>{
 const originals={window:globalThis.window,document:globalThis.document,fetch:globalThis.fetch};clearPageSnapshots();
 let writes=0,markup='',page=0,changed=false,host;
 const scrolls=[],body={},view={scrollX:0,scrollY:600,getComputedStyle:()=>({}),dispatchEvent(){},scrollTo(value){scrolls.push(value);this.scrollY=value.top;}};
 const result={style:{},parentElement:body,scrollTop:0,scrollLeft:0,setAttribute(){},removeAttribute(){},querySelectorAll:()=>[],getBoundingClientRect:()=>({height:500}),get innerHTML(){return markup;},set innerHTML(value){markup=value;writes++;}};
 const filters={elements:{type:{value:'all'},status:{value:'open'}}};
 const table={parentElement:body,getBoundingClientRect:()=>({top:200})};
 host={isConnected:true,ownerDocument:{body},querySelector:selector=>selector==='form'?filters:selector==='[data-attention-result]'?result:selector==='.attention-table'?table:null,querySelectorAll:()=>[],closest:selector=>selector==='[role=tabpanel]'?{hidden:false}:null};
 const badge={hidden:true,textContent:'',setAttribute(){}};
 const root={querySelector:()=>host,querySelectorAll:()=>[badge]},state={user:{id:1,role:'admin'},company:1,view:'overview'};
 globalThis.window=view;globalThis.document={hidden:false,querySelector:()=>null};
 globalThis.fetch=async url=>{const params=new URL(url,'http://local').searchParams;page=Number(params.get('page'));return {ok:true,json:async()=>({page,pageCount:4,total:40,counts:{open:40,in_progress:0},notes:[],checkedAt:Date.now(),items:[{key:'a'.repeat(64),type:'shipments',title:'Kayıt '+page,detail:changed?'Yeni kaynak bilgisi':'Kaynak bilgisi',source:{id:1}}]})};};
 try{
  bindAttentionCenter(root,state);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(badge.textContent,'40');assert.equal(badge.hidden,false);
  const initialWrites=writes;await host.attentionRefresh();assert.equal(writes,initialWrites,'Aynı içerik yeni checkedAt yüzünden yeniden çizilmemeli.');assert.equal(view.scrollY,600);
  changed=true;await host.attentionRefresh();assert.match(markup,/Yeni kaynak bilgisi/);assert.equal(view.scrollY,600,'Değişen kaynak kaydırmayı sıfırlamamalı.');
  const button={dataset:{attentionPage:'2'},hasAttribute:name=>name==='data-attention-page'};
  host.onclick({target:{closest:()=>button}});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(page,2);assert.match(markup,/Kayıt 2/);assert.deepEqual(scrolls.at(-1),{top:788,behavior:'instant'});
  const afterPageWrites=writes,position=view.scrollY;await host.attentionRefresh();assert.equal(page,2);assert.equal(writes,afterPageWrites);assert.equal(view.scrollY,position,'Periyodik kontrol üçüncü sayfayı veya konumu sıfırlamamalı.');
 }finally{host.attentionDispose?.();Object.assign(globalThis,originals);clearPageSnapshots();}
});
