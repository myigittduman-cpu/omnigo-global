import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createContext,runInContext} from 'node:vm';
import test from 'node:test';

const [formatCode,readerCode]=await Promise.all(['panel-format.js','panel-reader.js'].map(
  file=>readFile(new URL('./browser/'+file,import.meta.url),'utf8'),
));
const urls={
  pttavm:'https://merchant.pttavm.com/accounting/payoff/future-payments',
  pttavmIdentity:'https://merchant.pttavm.com/',
  idefix:'https://satici.idefix.com/finance/future-estimated-payments',
  koctas:'https://satisortagim.koctas.com.tr/pazaryerisatici/odeme-ve-finans/odemeler/gelecek-odemelerim',
};
const start=Date.now();
class Clock extends Date {
  constructor(...args) { super(...(args.length?args:['2026-11-15T10:00:00+03:00'])); }
  static now() { return Date.parse('2026-11-15T10:00:00+03:00')+Date.now()-start; }
}
const node=(innerText,queries={})=>({
  innerText,getClientRects:()=>[{}],
  querySelectorAll:selector=>typeof queries[selector]==='function'?queries[selector]():queries[selector]??[],
});
const plain=value=>JSON.parse(JSON.stringify(value));
function reader(provider,selectors,main=null,ids={}) {
  const document={...node('',selectors),querySelector:name=>name==='main'?main:null,
    getElementById:id=>ids[id]??null};
  const context=createContext({location:new URL(urls[provider]),document,Date:Clock,Intl,setTimeout,
    KeyboardEvent:class { constructor(name,options){this.name=name;this.key=options.key;} },
  });
  runInContext(formatCode,context);
  runInContext(readerCode,context);
  return context;
}

test('PTT AVM yalnız tam ödeme listesindeki tarih ve TL tutarını döndürür',async()=>{
  const cards=[
    node('Tahmini Hesaplandı\n₺216.875,49\nÖdeme Günü:\n19 Kasım 2026'),
    node('Tahmini Hesaplandı\n₺64.814,97\nÖdeme Günü:\n26 Kasım 2026'),
  ];
  const main=node('Toplam 2 ödemenizden 2 tanesini görüntülüyorsunuz.',{
    '[class*="_payoff-item_"]':cards,
  });
  const context=reader('pttavm',{h3:[node('Hakediş Ödemeleri')]},main);
  const result=await context.omnigoReadPayouts('pttavm','Vizyona');
  assert.equal(result.ok,true);
  assert.deepEqual(plain(result.rows),[{date:'2026-11-19',amountCents:21687549},{date:'2026-11-26',amountCents:6481497}]);
  assert.equal((await context.omnigoReadPayouts('koctas','Vizyona')).ok,false);
});

test('PTT AVM tekil VİZYONA hesabını gerektirir',async()=>{
  const context=reader('pttavmIdentity',{'[class*="_store-card-body_"]':[node('Vizyona',{p:[node('Vizyona')]})]});
  assert.equal((await context.omnigoReadPayouts('pttavmIdentity','Vizyona')).ok,true);
  assert.equal((await context.omnigoReadPayouts('pttavmIdentity','Başka mağaza')).ok,false);
});

test('Koçtaş devreden bakiye dahil nihai tahmini okur ve sıfırı ayrı tutar',async()=>{
  const header={...node('Ödeme Tarihi Tahmini Ödeme (Devreden Bakiye Dahil)'),
    rows:[{cells:['Ödeme Tarihi','Toplam','Tahmini Ödeme (Devreden Bakiye Dahil)'].map(text=>node(text))}]};
  const body={rows:[
    ['Devreden Bakiye','0,00 TL','0.00 TL'],['16/11/2026','60.000,00 TL','61.994,69 TL'],['30/11/2026','0,00 TL','0,00 TL'],
  ].map(cells=>({...node(cells.join(' ')),cells:cells.map(text=>node(text))}))};
  const context=reader('koctas',{h4:[node('Gelecek Ödemelerim')],button:[node('Vizyona')],
    table:[{...node(''),tHead:header,tBodies:[body]}]});
  assert.deepEqual(plain((await context.omnigoReadPayouts('koctas','Vizyona')).rows),[
    {date:'2026-11-16',amountCents:6199469},{date:'2026-11-30',amountCents:0},
  ]);
  assert.equal((await context.omnigoReadPayouts('koctas','Başka mağaza')).ok,false);
});

test('idefix ödeme tarihlerini tek tek okur ve panel toplamıyla doğrular',async()=>{
  const payments={'23/11/2026':'13.598,30 TL','30/11/2026':'242.325,60 TL'};
  let selected='',expanded=false,total='255.923,90 TL';
  const field={...node(''),focus(){},dispatchEvent(){expanded=true;},getAttribute(name){
    return name==='aria-expanded'?String(expanded):'menu';
  }};
  const button=()=>({...node(''),getAttribute:()=> 'Remove '+selected,click(){selected='';expanded=true;}});
  const form={...node('',{'[role="combobox"]':[field],'button[aria-label^="Remove "]':()=>selected?[button()]:[]}),
    get innerText(){return 'Toplam Gelecek Tahmini Ödeme: '+total;}};
  const amountLabel={...node('Tutar Bilgisi'),parentElement:{parentElement:form,
    get innerText(){return 'Tutar Bilgisi\n'+(payments[selected]??'');}}};
  const opts=()=>Object.keys(payments).filter(label=>label!==selected).map(label=>({...node(label),click(){
    selected=label;expanded=true;
  }}));
  const menu=node('',{'[role="option"]':opts});
  Object.assign(menu,{getClientRects:()=>expanded?[{}]:[]});
  const context=reader('idefix',{
    h3:[{...node('Gelecek Tahmini Ödemeler'),isConnected:true}],label:[amountLabel],
    'a[href^="https://www.idefix.com/satici/"]':[{...node(''),pathname:'/satici/vizyona-5174'}],
    '[role="option"]':opts,
  },null,{menu});
  assert.deepEqual(plain((await context.omnigoReadPayouts('idefix','5174')).rows),[
    {date:'2026-11-23',amountCents:1359830},{date:'2026-11-30',amountCents:24232560},
  ]);
  total='255.923,91 TL';
  assert.equal((await context.omnigoReadPayouts('idefix','5174')).ok,false);
});

test('Para ve tarih yanlış biçimleri, yinelenen tarihler ve ondalık hataları reddedilir',()=>{
  const context=reader('pttavm',{},null);
  const parser=context.omnigoPayoutFormat;
  assert.equal(parser.cents('1.234.567,08 TL'),123456708);
  assert.equal(parser.cents('−19,90 TL'),-1990);
  assert.equal(parser.day('7 Aralık 2026'),'2026-12-07');
  assert.throws(()=>parser.cents('1.234,5 TL'));
  assert.throws(()=>parser.day('31.11.2026'));
  assert.throws(()=>parser.finalize([{date:'2026-11-16'},{date:'2026-11-16'}]));
});
