import {company,providers,products,today,stamp,shipmentRows} from './data.js';
import {matchesText,matchesDate,paginate} from './record-filters.js';
import {cityProductCities} from '../turkey-cities.js';

const pages=['overview','channels','orders','inventory','shipments','deliveries','cityProducts','returns','analytics','payments','tasks','reports','support'];
export const access={pages:Object.fromEntries(pages.map(page=>[page,'shown'])),grants:Object.fromEntries(pages.map(page=>[page,{view:true,export:['analytics','reports','shipments','cityProducts'].includes(page)}])),inventory:{mode:'both'},cities:['Ankara','Eskişehir','Kayseri','Kırıkkale','Kırşehir','Nevşehir']};
export const preferences={theme:'classic-navy',fontSize:'standard',fontFamily:'system',density:'comfortable',reducedMotion:false,language:'tr'};
const providerKeys={n11:'n11',Pazarama:'pazarama','Koçtaş':'koctas',idefix:'idefix'};
const categories=[['washing-machines','Çamaşır Makineleri'],['dishwashers','Bulaşık Makineleri'],['refrigerators','Buzdolapları'],['dryers','Kurutma Makineleri'],['ovens','Fırınlar'],['vacuum-cleaners','Elektrikli Süpürgeler'],['water-dispensers','Su Sebilleri'],['hobs','Ocaklar']].map(([value,label])=>({value,label}));
const catalog=products(['n11']).map((product,index)=>({...product,key:'demo-product-'+index,sku:'DEMO-'+String(index+1).padStart(3,'0'),barcode:'',version:1,quantity:3+index,storeQuantity:3+index,marketplaceQuantity:5+index,totalQuantity:8+2*index,pending:false,critical:false,eligibility:{active:true,stocked:true},sources:providers.map(provider=>({provider,sku:'DEMO-'+String(index+1).padStart(3,'0'),quantity:5+index,saleable:true,saleState:'active',observedAt:stamp(),freshness:'fresh'})),sales:providers.map(provider=>({provider,sold:8+index,cancelled:1,returned:0,net:7+index,averageInvoiceCents:260000+index*90000})),links:[]}));
export function inventory(query){
 const category=query.get('category'),provider=query.get('provider'),filter=query.get('filter');
 const items=catalog.filter((item,index)=>matchesText([item.title,item.sku],query.get('query'))&&(!category||category==='all'||categories[index].value===category)&&(!provider||provider==='all'||item.sources.some(source=>source.provider===provider))&&(!filter||['stocked','all'].includes(filter)));
 return {company,...paginate(items,query,query.get('pageSize')==='50'?50:10),settings:{mode:'both'},providers,categories,pendingProducts:[],issues:0,sync:{lastSuccess:stamp(),error:null},activation:{marketplaceActive:true,canStart:false},initialImport:{complete:true},catalog:[]};
}
export function orders(query){
 let items=shipmentRows(today().slice(0,7),'n11').map((row,index)=>({id:row.id,provider:providerKeys[providers[index%4]],externalId:'DEMO-'+row.id,orderNumbers:['DEMO-'+row.id],customerNames:[row.customerName],recipientNames:[row.recipientName],phones:[row.phone],cities:[row.province],createdAt:row.orderedAt,state:'STAGING',statusLabel:'Hazırlanıyor',grossCents:row.invoiceCents,cancelCents:0,returnCents:0,netCents:row.invoiceCents,amountCents:row.invoiceCents,quantity:row.quantity,products:[{title:row.productName,quantity:row.quantity,sku:'DEMO-'+(index%8+1)}]}));
 const provider=query.get('provider');
 items=items.filter(item=>(!provider||provider==='all'||item.provider===provider||item.provider===providerKeys[provider])&&matchesText(item,query.get('query'))&&matchesDate(item.createdAt,query));
 return {...paginate(items,query,20),providers,sources:[]};
}
export function orderDetail(id){
 const row=orders(new URLSearchParams()).items.find(item=>item.id===Number(id));if(!row)return null;
 const source=shipmentRows(today().slice(0,7),'n11').find(item=>item.id===Number(id));
 const provider=providers.find(name=>providerKeys[name]===row.provider);
 return {...row,available:true,provider,products:row.products,packages:[{...source,provider,orderNumber:row.orderNumbers[0],complete:true,products:row.products}],summary:{grossCents:row.grossCents,cancelCents:0,returnCents:0,netCents:row.grossCents},discounts:null};
}
export function returns(query){
 const row={id:'DEMO-IADE-001',provider:'n11',orderNumber:'DEMO-1001',customerName:'Örnek Alıcı 02',claimId:'ORNEK-TALEP-001',createdAt:stamp(),product:'Bulaşık makinesi 6 programlı',quantity:1,amountCents:350000,group:'pending',statusLabel:'Talep inceleniyor',reason:'Tanıtım amaçlı örnek talep'};
 const include=(!query.get('provider')||query.get('provider')==='all'||query.get('provider')===row.provider)&&(!query.get('status')||query.get('status')==='all'||query.get('status')==='pending')&&matchesText(row,query.get('search'));
 return {items:include?[row]:[],total:include?1:0,page:0,hasMore:false,sources:[]};
}
export function deliveries(query){
 const all=shipmentRows(today().slice(0,7),'n11').map(row=>({id:row.id,provider:'n11',orderNumber:'DEMO-'+row.id,externalId:'DEMO-'+row.id,active:true,issues:[],queue:null,city:row.province,buyerName:row.customerName,recipientName:row.recipientName,phone:row.phone,address:row.deliveryAddress,createdAt:row.orderedAt,status:'Hazırlanıyor',deliveryStatus:'Sevk bekliyor',products:[{title:row.productName,quantity:row.quantity,sku:catalog[(row.id-1000)%catalog.length].sku}],details:{recipientName:row.recipientName,district:row.district},fulfillmentChecks:[]}));
 const city=query.get('city')||'all',mode=query.get('mode')||'all',needle=(query.get('query')||'').toLocaleLowerCase('tr-TR');
 const items=all.filter(row=>(city==='all'||row.city===city)&&mode==='all'&&(!needle||JSON.stringify(row).toLocaleLowerCase('tr-TR').includes(needle)));
 const quantity=rows=>rows.reduce((sum,row)=>sum+row.products[0].quantity,0);
 return {company,items,total:items.length,page:0,hasMore:false,revision:'demo-1',sync:{},queues:{cancelled:0,delivered:0},cities:[{name:'all',count:all.length,productCount:quantity(all)},...access.cities.map(name=>({name,count:all.filter(row=>row.city===name).length,productCount:quantity(all.filter(row=>row.city===name))})),{name:'Diğer / Kontrol',count:0,productCount:0}],summary:{orders:all.length,quantity:quantity(all),cancelled:0,delivered:0}};
}
for(const item of catalog){item.marketplaceStocks=item.sources.map(source=>({...source,saleActive:true,health:'current'}));item.markets=item.sales.map(market=>({...market,gross:market.sold}));item.listedOn=[...providers];item.storeSales=2;}
function sortCityOrders(items,sort){
 const names=new Intl.Collator('tr',{sensitivity:'base'});
 const compare=(a,b)=>{
  let label=0,date=0;
  if(sort==='default')date=b.orderedAt.slice(0,10).localeCompare(a.orderedAt.slice(0,10));
  if(sort==='default'||sort==='city')label=names.compare(a.deliveries[0].city,b.deliveries[0].city);
  if(sort==='recipient')label=names.compare(a.deliveries[0].recipientName,b.deliveries[0].recipientName);
  const newest=b.orderedAt.localeCompare(a.orderedAt);
  return date||label||(sort==='oldest'?-newest:newest)||a.orderKey.localeCompare(b.orderKey);
 };
 // The demo timestamps are generated in Europe/Istanbul local time.
 return items.sort(compare);
}
export function cityProducts(query){
 const cities=cityProductCities(access.cities);
 const grouped=query.get('group')==='order';
 const sort=query.get('sort')??'default';
 const city=query.get('city')||(grouped?'all':cities[0]);
 if(!(grouped&&city==='all')&&!cities.includes(city))throw new Error('Bu il için erişim yok.');
 const orders=deliveries(new URLSearchParams({city,mode:'all'})).items;
 const items=orders.filter(order=>cities.includes(order.city)).flatMap(order=>{
  const delivery={id:String(order.id),recipientName:order.recipientName,phone:order.phone,
   address:order.address,city:order.city,district:order.details.district};
  const products=order.products.map((product,index)=>({id:`${order.id}-${index}`,sku:product.sku,
   productName:product.title,quantity:product.quantity}));
  const orderKey=order.provider+'-'+order.orderNumber;
  if(grouped)return [{id:orderKey,orderKey,orderedAt:order.createdAt,deliveries:[{...delivery,products}],
   quantity:products.reduce((total,product)=>total+product.quantity,0)}];
  return products.map(product=>({...delivery,orderedAt:order.createdAt,...product}));
 });
 if(grouped){
  if(!['default','newest','oldest','city','recipient'].includes(sort))throw new Error('Geçersiz sıralama seçimi.');
  sortCityOrders(items,sort);
  if(query.get('export')==='1'){
   if(!access.grants.cityProducts.export)throw new Error('Bu işlem için yetkiniz yok.');
   return {cities,city,sort,items,page:0,pageSize:items.length,hasMore:false,total:items.length};
  }
 }
 return {cities,city,...(grouped?{sort}:{}),...paginate(items,query,10)};
}

export function stockHistory(query){
 const item={created:stamp(),kind:'set',actor_name:'Örnek operasyon ekibi',delta:3,quantity_before:0,quantity_after:3,note:'Tanıtım amaçlı örnek stok sayımı',title:catalog[0].title,sku:catalog[0].sku};
 const include=matchesDate(item.created,query)&&(!query.get('productKey')||query.get('productKey')===catalog[0].key);
 return {items:include?[item]:[],page:0,hasMore:false,nextCursor:null};
}
