// Deliberately synthetic records. This module has no server, credential or storage dependency.
export const providers=['n11','Pazarama','Koçtaş','idefix'];
export const company={id:900001,name:'Örnek Ev & Yaşam',hasLogo:false};
export const user={id:900001,company_id:company.id,role:'client',name:'Demo Ziyaretçisi',email:'demo@example.invalid'};
export const today=()=>new Date().toLocaleDateString('sv-SE',{timeZone:'Europe/Istanbul'});
export const shift=(day,offset)=>new Date(Date.parse(day+'T12:00:00Z')+offset*86400000).toISOString().slice(0,10);
export const stamp=()=>today()+'T10:00:00+03:00';
export function dates(from,to){
 if(!/^\d{4}-\d{2}-\d{2}$/.test(from||'')||!/^\d{4}-\d{2}-\d{2}$/.test(to||'')||from>to)throw new Error('Geçerli bir tarih aralığı seçin.');
 const length=Math.round((Date.parse(to)-Date.parse(from))/86400000)+1;
 if(!Number.isFinite(length)||length>366)throw new Error('Demoda en fazla bir yıllık aralık seçebilirsiniz.');
 return Array.from({length},(_,index)=>shift(from,index));
}
export function daily(channel,day){
 const i=providers.indexOf(channel)+1,dayNumber=Math.floor(Date.parse(day)/86400000);
 const orders=4+(dayNumber+i*7)%13,grossCents=orders*(480000+i*80000),cancelCents=dayNumber%9===0?480000:0,returnCents=0;
 return {date:day,orders,quantity:orders+2,grossCents,cancelCents,cancelOrders:cancelCents?1:0,cancelQty:cancelCents?1:0,returnCents,netCents:grossCents-cancelCents,amountCents:grossCents-cancelCents,partial:false};
}
export function totals(rows){
 const value=Object.fromEntries(['grossCents','cancelCents','cancelOrders','cancelQty','returnCents','netCents','orders','quantity'].map(key=>[key,rows.reduce((sum,row)=>sum+(row[key]||0),0)]));
 return {...value,panelSalesCents:value.grossCents,averageCents:value.orders?Math.round(value.netCents/value.orders):0};
}
export function period(channel,from,to){const rows=dates(from,to).map(day=>daily(channel,day)),sum=totals(rows);return {...sum,available:true,amountCents:sum.netCents,quantity:sum.quantity,orders:sum.orders};}
function paymentsForMonth(month){
 return providers.flatMap((channel,i)=>[7,16,24].map((day,n)=>{
  const date=month+'-'+String(day).padStart(2,'0');
  return {id:100+i*3+n,channel,expectedDate:date,calendarDate:date,inCalendar:true,
   status:n===0?'paid':'pending',verification:n===2?'estimated':'confirmed',
   expectedCents:950000+i*180000+n*350000,actualCents:n===0?950000+i*180000:null,paidDate:n===0?date:'',
   confirmation:'Örnek banka teyidi',verificationSource:'Örnek ödeme kaydı',source:'Demo verisi',updated:stamp()};
 }));
}
function upcomingPayments(date){
 const month=date.slice(0,7);
 const [year,index]=month.split('-').map(Number);
 const nextMonth=new Date(Date.UTC(year,index,1)).toISOString().slice(0,7);
 const periodItems=[...paymentsForMonth(month),...paymentsForMonth(nextMonth)]
  .filter(item=>item.status==='pending'&&item.expectedDate>=date)
  .map(item=>({channel:item.channel,date:item.expectedDate,amountCents:item.expectedCents,verification:item.verification,source:'manual'}));
 return {today:date,periodItems};
}
export function paymentReport(from,to){
 const items=paymentsForMonth(from.slice(0,7));
 return {from,to,channel:'all',marketplaces:providers,upcoming:upcomingPayments(today()),items,
  summary:{estimatedCents:items.filter(x=>x.verification==='estimated').reduce((s,x)=>s+x.expectedCents,0),confirmedCents:items.filter(x=>x.status!=='paid'&&x.verification==='confirmed').reduce((s,x)=>s+x.expectedCents,0),receivedCents:items.reduce((s,x)=>s+(x.actualCents||0),0)}};
}
export function products(channels){
 const titles=['Beyaz çamaşır makinesi 9 kg','Bulaşık makinesi 6 programlı','Çift kapılı buzdolabı','Isı pompalı kurutma makinesi','Ankastre fırın','Elektrikli süpürge','Su sebili','Cam ankastre ocak'];
 return channels.flatMap((channel,i)=>titles.map((title,j)=>({channel,sku:'DEMO-'+(i+1)+'-'+(j+1),title,quantity:8+j,netQuantity:8+j,grossCents:(8+j)*(260000+j*90000),netCents:(8+j)*(260000+j*90000),cancelCents:0,returnCents:0,refundsVerified:true,imageUrl:''})));
}
export function analytics(from,to,channel='all'){
 const channels=channel==='all'?providers:providers.filter(value=>value===channel),days=dates(from,to),length=days.length;
 const previousFrom=shift(from,-length),previousTo=shift(from,-1);
 const series=channels.map(value=>({channel:value,available:true,asOf:stamp(),days:days.map(day=>daily(value,day))}));
 return {company,from,to,previousFrom,previousTo,generatedAt:stamp(),availableChannels:channels.length,selectedChannels:channels.length,comparisonAvailable:true,refundsVerified:true,notes:['Bu rapordaki tüm bilgiler tanıtım amaçlı örnek verilerdir.'],series,totals:totals(series.flatMap(s=>s.days)),previousTotals:totals(channels.flatMap(value=>dates(previousFrom,previousTo).map(day=>daily(value,day)))),products:products(channels),payments:paymentReport(from,to)};
}
export function dashboard(month=today().slice(0,7)){
 const date=today(),from=month+'-01',last=new Date(Date.UTC(Number(month.slice(0,4)),Number(month.slice(5)),0)).toISOString().slice(0,10),to=month===date.slice(0,7)?date:last;
 const report=analytics(from,to),{items:payments,upcoming}=paymentReport(from,last);
 const currentMonthTurnoverCents=providers.reduce((sum,channel)=>sum+period(channel,date.slice(0,7)+'-01',date).netCents,0);
 return {company,channels:providers.map((name,index)=>({id:index+1,name,status:'active',storeName:'Örnek Ev & Yaşam',connection:'connected',lastSyncAt:stamp(),health:{status:'healthy',message:'Demo: örnek bağlantı durumu, gerçek pazaryeri bağlantısı değildir.'}})),sales:providers.map(channel=>({...totals(dates(from,to).map(day=>daily(channel,day))),id:providers.indexOf(channel)+1,month,channel,integration:false,updated:stamp()})),payments,tasks:[{id:1,channel:'Tüm pazaryerleri',title:'Ürün içeriklerinin güncellenmesi',description:'Ürün başlıkları, görselleri ve açıklamaları için örnek çalışma planı.',owner:'Örnek operasyon ekibi',date:from,status:'done',visibility:'client'},{id:2,channel:'Pazarama',title:'Aylık satış değerlendirmesi',description:'Satış dağılımı ve ürün performansının değerlendirilmesi.',owner:'Örnek raporlama ekibi',date:month+'-12',status:'running',visibility:'client'},{id:3,channel:'n11',title:'Yeni dönem mağaza planı',description:'Sonraki dönemde ele alınacak mağaza çalışmalarının hazırlanması.',owner:'Örnek operasyon ekibi',date:month+'-24',status:'planned',visibility:'client'}],reports:[{id:900,title:"Örnek aylık çalışma raporu",month,content:"Bu rapor yalnızca tanıtım için oluşturulmuş örnektir.\n\nTamamlanan çalışmalar: Ürün içerikleri kontrol edildi.\nDevam eden çalışmalar: Dönem değerlendirmesi hazırlanıyor.\nSonraki adım: Yeni dönem mağaza planını birlikte incelemek.",visibility:"client"}],summary:{...report.totals,expectedCents:payments.filter(p=>p.status!=='paid').reduce((s,p)=>s+p.expectedCents,0),paidCents:payments.reduce((s,p)=>s+(p.actualCents||0),0),refundsVerified:true},upcoming,marketplaceSales:{currentMonthTurnoverCents,channels:providers.map(channel=>({channel,available:true,refundsVerified:true,asOf:stamp(),days:dates(shift(date,-29),date).map(day=>daily(channel,day)),periods:{today:{...period(channel,date,date),previous:period(channel,shift(date,-1),shift(date,-1))},seven:{...period(channel,shift(date,-6),date),previous:period(channel,shift(date,-13),shift(date,-7))},thirty:{...period(channel,shift(date,-29),date),previous:period(channel,shift(date,-59),shift(date,-30))},month:period(channel,from,to)}}))}};
}
export function shipmentRows(month,provider){
 const cities=['Ankara','Eskişehir','Kayseri','Kırıkkale','Kırşehir','Nevşehir'],statuses=['shipped','delivered','completed','invoiced',''];
 return Array.from({length:18},(_,index)=>{const day=Math.min(month===today().slice(0,7)?Number(today().slice(-2)):28,28)-Math.floor(index/2),city=cities[index%cities.length],name='Örnek Alıcı '+String(index+1).padStart(2,'0'),address='Örnek Mahallesi, Demo Caddesi No: '+(index+1)+' (tanıtım adresi)';return {id:1000+index,version:1,provider,orderedAt:month+'-'+String(Math.max(1,day)).padStart(2,'0')+'T10:30:00+03:00',customerName:name,recipientName:name,phone:'Örnek telefon',deliveryAddress:address,province:city,district:'Örnek ilçe',productName:products([provider])[index%8].title,invoiceCents:2499000+index*10000,quantity:index%4===0?2:1,invoiceName:name,invoiceAddress:address,recipientEmail:'ornek'+(index+1)+'@example.invalid',customerType:'Bireysel',taxNumber:'',taxOffice:'',rowStatus:statuses[index%5],shipped:statuses[index%5]==='shipped',cancelled:false,invoiced:statuses[index%5]==='invoiced',documents:{}};});
}
export const tickets=[{id:1,subject:'Örnek talep: dönem raporu',category:'question',status:'answered',updated:stamp(),hasOlder:false,messages:[{id:1,sender_role:'client',created:stamp(),body:'Örnek mesaj: Bu ayın satış raporunu nereden inceleyebilirim?'},{id:2,sender_role:'admin',created:stamp(),body:'Örnek yanıt: Analiz ve raporlar bölümünden tarih ve pazaryeri seçerek raporu görüntüleyebilirsiniz.'}]},{id:2,subject:'Örnek talep: sevkiyat kaydı',category:'issue',status:'open',updated:stamp(),hasOlder:false,messages:[{id:3,sender_role:'client',created:stamp(),body:'Örnek mesaj: Sevkiyat kaydıyla ilgili bilgi rica ediyorum.'}]}];
