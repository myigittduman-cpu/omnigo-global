import test from 'node:test';
import assert from 'node:assert/strict';
import {profitabilityDocumentRows,profitabilityDocumentTable} from '../public/profitability-document.js';

const company={id:7,name:'Deneme Mağazası'},filters={from:'2026-11-01',to:'2026-11-20',channel:'n11'};
const order=(num,status,afterDeductionsCents=null)=>({
  orderNumber:'No-'+num,orderedAt:'2026-11-18T10:00:00Z',channel:filters.channel,status,afterDeductionsCents,
  products:[{sku:'SKU-'+num,quantity:2}],orderPricing:{storePriceCents:249900,buyerPaidCents:229900},
});
const entries=[order(1,'reconciled',0),order(2,'cancelled'),order(3,'financials_pending'),order(4,'returned'),
  order(5,'sales_pending'),order(6,'statement_stale',212459)];
const response=(page,rows=entries)=>({...filters,page,pageSize:2,total:rows.length,rows:rows.slice((page-1)*2,page*2)});
const options={company,filters,current:()=>true};

test('Belgede bilinen komisyon, kesintiler, maliyet ve indirimler ekranla aynı kaynaklardan gelir',()=>{
 const item={...order(7,'financials_pending'),commissionCents:46634,commissionDiscountCents:0,purchaseCents:927100,
   orderPricing:{storePriceCents:931942,buyerPaidCents:910000,sellerDiscountCents:1000,platformDiscountCents:3000},
   statementItems:[{type:'Commission',taxCents:-7772},{type:'ProcessingFeeExpense',grossCents:-1308}]};
 const {head,rows:[values]}=profitabilityDocumentTable([item],'n11');
 const cell=label=>values[head.indexOf(label)];
 assert.match(cell('Komisyon'),/466,34[\s\S]*0,00/);
 assert.match(cell('Satış tutarı (TL)'),/9\.319,42[\s\S]*10,00[\s\S]*30,00/);
 assert.match(cell('Diğer kesintiler'),/Komisyon KDV’si 77,72[\s\S]*Hizmet bedeli 13,08/);
 assert.equal(cell('Alış maliyeti'),9271);assert.equal(cell('Hesaba geçecek tutar'),'Ekstre bekleniyor');
});

test('Kârlılık belgesi seçili mağazanın ve pazaryerinin bütün sayfalarını doğrular',async()=>{
  const pages=[];
  const rows=await profitabilityDocumentRows({...options,read:async path=>{
    const query=new URL(path,'https://portal.invalid').searchParams;
    for(const [key,value] of Object.entries({...filters,company:7}))assert.equal(query.get(key),String(value));
    const page=Number(query.get('page'));pages.push(page);return response(page);
  }});
  assert.deepEqual(pages,[1,2,3,1]);
  assert.deepEqual(rows.map(row=>row.orderNumber),['No-1','No-3','No-6']);
  const table=profitabilityDocumentTable(rows,'n11');
  assert.equal(table.head.at(-1),'Hesaba geçecek tutar');
  assert.equal(table.rows[0].at(-1),0);
  assert.equal(table.rows[1].at(-1),'Ekstre bekleniyor');
  assert.match(table.rows[2].at(-1),/Son ekstre/);
});

test('Değişen sayfa, kayıt, filtre veya mağaza kapsamını reddeder',async()=>{
  for(const alter of [
    data=>({...data,total:data.total+1}),
    data=>({...data,rows:[entries[0],data.rows[1]]}),
    data=>({...data,channel:'Farklı'}),
    data=>({...data,rows:[{...data.rows[0],channel:'Farklı'},data.rows[1]]}),
    data=>({...data,page:1}),
  ]){
    await assert.rejects(()=>profitabilityDocumentRows({...options,read:async path=>{
      const page=Number(new URL(path,'https://portal.invalid').searchParams.get('page'));
      return page===2?alter(response(page)):response(page);
    }}),/kapsamı değişti/);
  }
  let valid=true;
  await assert.rejects(()=>profitabilityDocumentRows({...options,current:()=>valid,read:async()=>{valid=false;return response(1);}}),/kapsamı değişti/);
  await assert.rejects(()=>profitabilityDocumentRows({...options,company:null,read:()=>assert.fail()}),/kapsamı değişti/);
});

test('İlk sayfadaki finansal sonuç değişirse veya sadece iptal/iade varsa dosya hazırlanmaz',async()=>{
  let reads=0;
  await assert.rejects(()=>profitabilityDocumentRows({...options,read:async path=>{
    const page=Number(new URL(path,'https://portal.invalid').searchParams.get('page'));
    if(page===1&&reads++>0)return response(1,[{...entries[0],status:'cancelled'},...entries.slice(1)]);
    return response(page);
  }}),/kapsamı değişti/);
  await assert.rejects(()=>profitabilityDocumentRows({...options,read:async()=>response(1,[entries[1],entries[3]])}),/satış bulunamadı/);
});
