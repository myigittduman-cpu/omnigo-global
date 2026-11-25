import test from 'node:test';
import assert from 'node:assert/strict';
import {scrollTableStart,preserveTablePosition} from '../public/table-page-scroll.js';

test('Penceredeki tablo sabit menünün altına alınır; yatay konuma dokunulmaz',()=>{
 const calls=[],body={},header={getBoundingClientRect:()=>({bottom:120})};
 const table={parentElement:body,getBoundingClientRect:()=>({top:350})};
 const root={isConnected:true,ownerDocument:{body},querySelector:()=>table,closest:()=>({querySelector:()=>header})};
 const viewport={scrollY:600,getComputedStyle:()=>({position:'sticky'}),scrollTo:value=>calls.push(value)};
 scrollTableStart(root,'.table',viewport);
 assert.deepEqual(calls,[{top:818,behavior:'instant'}]);
});

test('Pencere içindeki sayfalama yalnız ilgili açılır ekranın dikey konumunu değiştirir',()=>{
 const calls=[],body={},head={getBoundingClientRect:()=>({bottom:180})};
 const container={parentElement:body,scrollTop:400,scrollHeight:1100,clientHeight:400,clientTop:1,getBoundingClientRect:()=>({top:100}),querySelector:()=>head,contains:()=>true,scrollTo:value=>calls.push(value)};
 const horizontal={parentElement:container,scrollHeight:200,clientHeight:200};
 const table={parentElement:horizontal,closest:()=>container,getBoundingClientRect:()=>({top:280})};
 const root={isConnected:true,ownerDocument:{body},querySelector:()=>table};
 const viewport={getComputedStyle:el=>el===head?{position:'sticky'}:{overflowY:el===container?'auto':'hidden'},scrollTo:()=>assert.fail('Arka sayfa kaymamalı')};
 scrollTableStart(root,'table',viewport);
 assert.deepEqual(calls,[{top:488,behavior:'instant'}]);
});

test('İç tablo ve dış sayfa birlikte başa alınır; yatay kaydırma korunur',()=>{
 const calls=[],body={},header={getBoundingClientRect:()=>({bottom:100})};
 const viewport={scrollY:700,getComputedStyle:node=>node===header?{position:'sticky'}:{overflowY:'auto'},scrollTo:value=>calls.push(['page',value])};
 const wrapper={parentElement:body,scrollTop:300,scrollLeft:175,clientTop:1,scrollHeight:900,clientHeight:300,getBoundingClientRect:()=>({top:220}),contains:()=>false,scrollTo(value){calls.push(['inner',value]);this.scrollTop=value.top;}};
 const table={parentElement:wrapper,getBoundingClientRect:()=>({top:221-wrapper.scrollTop})};
 const root={isConnected:true,ownerDocument:{body},querySelector:()=>table,closest:()=>({querySelector:()=>header})};
 scrollTableStart(root,'table',viewport);
 assert.deepEqual(calls,[['inner',{top:0,behavior:'instant'}],['page',{top:808,behavior:'instant'}]]);
 assert.equal(wrapper.scrollLeft,175);
});

test('Seçici doğrudan kaydırmalı tablo alanını gösterdiğinde iç kaydırma sıfırlanır',()=>{
 const body={},calls=[];
 const table={parentElement:body,scrollHeight:900,clientHeight:200,scrollTop:340,getBoundingClientRect:()=>({top:200}),scrollTo(value){this.scrollTop=value.top;calls.push(['table',value]);}};
 const root={isConnected:true,ownerDocument:{body},querySelector:()=>table};
 const viewport={scrollY:600,getComputedStyle:()=>({overflowY:'auto'}),scrollTo:value=>calls.push(['page',value])};
 scrollTableStart(root,'.wrapper',viewport);
 assert.equal(table.scrollTop,0);
 assert.deepEqual(calls.at(-1),['page',{top:788,behavior:'instant'}]);
});

test('Artık açık olmayan veya tablo içermeyen ekran kaydırılmaz',()=>{
 const viewport={scrollTo:()=>assert.fail('Kaydırma yapılmamalı')};
 scrollTableStart({isConnected:false},'table',viewport);
 scrollTableStart({isConnected:true,querySelector:()=>null},'table',viewport);
});

test('Ardışık sayfalama kısa tabloların altına ek kaydırma alanı açmaz',()=>{
 const body={},page={scrollHeight:1550};
 const document={body,scrollingElement:page,createElement(){assert.fail('Sayfa sonuna boş eleman eklenmemeli.');}};
 const viewport={innerHeight:800,scrollY:500,getComputedStyle:()=>({}),scrollTo({top}){this.scrollY=Math.min(top,page.scrollHeight-this.innerHeight);}};
 const table={parentElement:body,getBoundingClientRect:()=>({top:588-viewport.scrollY})};
 const root={isConnected:true,ownerDocument:document,querySelector:selector=>selector==='table'?table:null};
 for(const height of [1550,1240,950,1550]){
  page.scrollHeight=height;
  scrollTableStart(root,'table',viewport);
  assert.equal(viewport.scrollY,Math.min(576,height-800));
  assert.equal(page.scrollHeight,height);
 }
});

test('Canlı yeniden çizim mevcut dikey ve yatay konumu korur',()=>{
 const root={style:{},scrollTop:20,scrollLeft:90,parentElement:null,querySelectorAll:()=>[]};
 const viewport={scrollY:600,scrollX:0,scrollTo(){assert.fail('Konum zaten aynı.');}};
 const restore=preserveTablePosition(root,viewport);
 assert.equal(root.style.overflowAnchor,'none');
 root.scrollTop=0;root.scrollLeft=0;restore();
 assert.equal(root.scrollTop,20);assert.equal(root.scrollLeft,90);
});

test('Daha kısa sekme boyandığında konum yeni içerik sınırında kalır',()=>{
 const page={scrollHeight:900};
 const document={scrollingElement:page,createElement(){assert.fail('Boyama sonrasında boş alan eklenmemeli.');}};
 const root={ownerDocument:document,style:{},scrollTop:0,scrollLeft:0,querySelectorAll:()=>[]};
 const viewport={innerHeight:900,scrollX:0,scrollY:7,scrollTo({top}){this.scrollY=Math.min(top,page.scrollHeight-this.innerHeight);}};
 const restore=preserveTablePosition(root,viewport);
 viewport.scrollY=0;
 restore();
 assert.equal(viewport.scrollY,0);
 assert.equal(page.scrollHeight,900);
});

test('Pencereye sığdırılan tabloda sayfalama yalnız tablo içini başa alır',()=>{
 const calls=[],frame={style:{getPropertyValue:name=>name==='--table-panel-height'?'300px':''},scrollTo:value=>calls.push(value)};
 const table={closest:()=>frame};
 const root={isConnected:true,querySelector:()=>table};
 const viewport={scrollTo:()=>assert.fail('Dış sayfa yer değiştirmemeli')};
 scrollTableStart(root,'table',viewport);
 assert.deepEqual(calls,[{top:0,behavior:'instant'}]);
});
