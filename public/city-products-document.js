import {brandedDocumentFilename} from './document-filename.js';

import {workspaceExportCompany} from './workspace-export-company.js';
import {cityProductCities,workspaceCities} from './turkey-cities.js';
import {t,text} from './i18n.js';

export const cityProductsColumns=['Sipariş Tarihi','Teslim Alacak Kişi','Tel No','Adres','İl','İlçe','Ürün SKU','Ürün Adı','Adet'];
const clean=value=>String(value??'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'').trim();

export function cityProductsDocument(state,city,orders){
 const company=workspaceExportCompany(state,state.company),allowed=new Set(cityProductCities(workspaceCities(state)));
 if(!company?.id||!(company.name||company.title)||Number(company.id)!==Number(state.company))throw new Error(t('Rapor firması belirlenemedi. Sayfayı yenileyin.'));
 if(!Array.isArray(orders)||!orders.length)throw new Error(t('Çıktı için en az bir sipariş seçin.'));
 const rows=[],ids=new Set();let quantity=0;
 for(const [orderIndex,order] of orders.entries()){
  if(!order.orderKey||ids.has(order.orderKey)||!order.deliveries?.length)throw new Error(t('Sipariş bilgileri değişti. Listeyi yenileyin.'));
  ids.add(order.orderKey);
  let orderQuantity=0,first=true;
  for(const delivery of order.deliveries){
   if(!allowed.has(delivery.city)||(city!=='all'&&city!==delivery.city))throw new Error(t('Teslimat ili seçili kapsamla eşleşmiyor. Listeyi yenileyin.'));
   if(!delivery.products?.length)throw new Error(t('Siparişin ürün bilgisi eksik. Listeyi yenileyin.'));
   for(const [index,product] of delivery.products.entries()){
    const count=Number(product.quantity);
    if(!Number.isSafeInteger(count)||count<1)throw new Error(t('Ürün adedi doğrulanamadı. Listeyi yenileyin.'));
    rows.push({orderIndex,first,orderedAt:first?order.orderedAt:null,recipient:index?'':clean(delivery.recipientName)||'—',
     phone:index?'':clean(delivery.phone)||'—',address:index?'':clean(delivery.address)||'—',city:index?'':delivery.city,
     district:index?'':clean(delivery.district)||'—',sku:clean(product.sku)||'—',name:clean(product.productName)||'—',quantity:count});
    first=false;orderQuantity+=count;
   }
  }
  if(orderQuantity!==Number(order.quantity))throw new Error(t('Sipariş ile ürün adetleri eşleşmiyor. Listeyi yenileyin.'));
  quantity+=orderQuantity;
 }
 const label=city==='all'?t('Tüm İller'):city,title=t('Şehir Dışı Ürünler'),createdAt=new Date();
 const filename=type=>brandedDocumentFilename(company,title,type,createdAt);
 return {rows,quantity,orderCount:orders.length,company:{...company},city:label,title,createdAt,
  summary:text`${orders.length} sipariş`+' · '+text`${quantity} adet`,
  excelName:filename('xlsx'),pdfName:filename('pdf')};
}
