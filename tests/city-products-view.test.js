import assert from 'node:assert/strict';
import test from 'node:test';
import {cityProductSortField} from '../public/city-products-filters.js';
import {cityProductsDocument} from '../public/city-products-document.js';
import {documentDownloadDate} from '../public/document-filename.js';
import {cityProductChooser,cityProductRows,cityProductsContent,cityProductsMessage,cityProductsPager} from '../public/city-products-view.js';

const contact={id:'dest1',recipientName:'Birinci Alıcı',address:'Birinci Adres',phone:'111',city:'Kayseri',district:'Talas'};
const product={id:'line1',sku:'ABC',productName:'Ürün <A>',quantity:2};
const other={id:'line2',sku:'DEF',productName:'Ürün B',quantity:1};
const order={id:'order1',orderedAt:'2026-11-17T12:34:00+03:00',quantity:2,deliveries:[{...contact,products:[product]}]};
const rows=items=>cityProductRows({items});

test('PDF ve Excel aynı mağaza, il, tarih ve Şehir Dışı Ürünler adını kullanır',()=>{
 for(const [id,name] of [[1,'VİZYONA'],[2,'TERZİOĞLU SHOP']]){
  const state={company:id,user:{role:'client',companyId:id},data:{company:{id,name},deliveryCities:['Kayseri']}};
  for(const city of ['all','Kayseri']){
   const doc=cityProductsDocument(state,city,[{...order,orderKey:'test-order'}]);
   const stem=[id===1?'Vizyona':'Terzioğlu Shop','–',documentDownloadDate(doc.createdAt),doc.title].join(' ');
   assert.equal(doc.pdfName,stem+'.pdf');
   assert.equal(doc.excelName,stem+'.xlsx');
  }
 }
});

test('Tek SKU bir sipariş satırında toplu adedi ve ürün açıklamasını gösterir',()=>{
 const result=rows([order]);
 assert.equal((result.match(/<tr /g)||[]).length,1);
 assert.match(result,/class="city-products-quantity">2<\/td>/);
 assert.match(result,/data-shipment-product-name="line1"/);
 assert.match(result,/Ürün &lt;A&gt;/);
 assert.doesNotMatch(result,/class="city-product-count"/);
});

test('Farklı SKU miktarları aynı sipariş satırında kendi ürününe bağlanır',()=>{
 const result=rows([{...order,quantity:3,deliveries:[{...contact,products:[product,other]}]}]);
 assert.equal((result.match(/<tr /g)||[]).length,1);
 assert.equal((result.match(/Birinci Adres/g)||[]).length,1);
 assert.match(result,/data-product-id="line1"[\s\S]*?2 adet[\s\S]*?data-product-id="line2"[\s\S]*?1 adet/);
 assert.match(result,/class="city-products-quantity">3<\/td>/);
});

test('Teslimat adresleri farklıysa her adres yalnız ilgili ürünleriyle eşleşir',()=>{
 const second={...contact,id:'dest2',recipientName:'İkinci Alıcı',address:'İkinci Adres',products:[other]};
 const result=rows([{...order,quantity:3,deliveries:[...order.deliveries,second]}]);
 const sections=result.split('class="city-product-destination"');
 assert.equal((result.match(/<tr /g)||[]).length,1);
 assert.equal(sections.length,3);
 assert.match(sections[1],/Birinci Adres[\s\S]*?data-product-id="line1"/);
 assert.doesNotMatch(sections[1],/İkinci Adres|line2/);
 assert.match(sections[2],/İkinci Adres[\s\S]*?data-product-id="line2"/);
 assert.doesNotMatch(sections[2],/Birinci Adres|line1/);
});

test('Önceki yanıt biçimi okunur ve sayfalama yeni yanıtla siparişi sayar',()=>{
 const legacy={...contact,...product,orderedAt:order.orderedAt};
 assert.match(rows([legacy]),/class="city-products-quantity">2<\/td>/);
 const page={page:0,pageSize:10,total:11};
 assert.match(cityProductsPager({...page,items:[legacy]}),/11 kayıt/);
 assert.match(cityProductsPager({...page,items:[order]}),/11 sipariş/);
});

test('İl ve sıralama seçimleri mağaza kapsamını kullanır',()=>{
 const choices=cityProductChooser(['Kayseri','Ankara'],'Kayseri');
 assert.match(choices,/<select data-products-city-picker/);
 assert.match(choices,/value="Kayseri" selected/);
 assert.ok(choices.indexOf('Ankara')<choices.indexOf('Kayseri'));
 assert.doesNotMatch(choices,/İzmir/);
 assert.match(cityProductSortField(),/value="newest"[\s\S]*value="city"/);
 assert.match(cityProductsMessage({cities:['Ankara'],city:'all',items:[]}),/Teslimat kapsamındaki iller/);
});

test('Mağaza ve yönetici hesaplarında çıktı araçları etkin işlem iznini takip eder',()=>{
 for(const role of ['client','admin'])for(const allowed of [true,false]){
  const state={user:{role},data:{deliveryCities:['Kayseri'],workspaceAccess:{pages:{cityProducts:'shown'},grants:{cityProducts:{view:true,export:allowed}}}}};
  const page=cityProductsContent(state);
  assert.equal(page.includes('data-products-outputs hidden'),!allowed);
  assert.match(page,/data-products-output="pdf"[\s\S]*data-products-output="print"[\s\S]*data-products-output="whatsapp"[\s\S]*data-products-output="excel"/);
 }
});
