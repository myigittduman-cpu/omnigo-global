import {rawTurnoverValue,cancelledTurnoverValue,turnoverValue,turnoverMoney,turnoverTotal,turnoverDays,turnoverNote} from './sales-turnover.js';
/** Aggregate the same SKU across selected marketplaces; unknown quantities never rank. */
export function rankBestsellers(products,limit=10){
 const groups=new Map();
 const adjusted=products?.some(product=>product.turnoverDisplayBasis==='after_cancellations');
 for(const product of products||[]){
  if(!Number.isFinite(product.netQuantity)||product.netQuantity<=0)continue;
  const key=String(product.sku||product.title||'').trim().toLocaleLowerCase('tr-TR');if(!key)continue;
  let row=groups.get(key);
  if(!row){row={title:product.title,sku:product.sku||'',imageUrl:product.imageUrl||'',channels:new Set(),netQuantity:0,netCents:0,turnoverCents:0,salesTurnoverCents:0,cancelledTurnoverCents:0,refundsVerified:true};groups.set(key,row);}
  if(!row.imageUrl&&product.imageUrl)row.imageUrl=product.imageUrl;
  const after=Object.hasOwn(product,'salesTurnoverCents')?product.salesTurnoverCents:turnoverValue(product);
  row.channels.add(product.channel);row.netQuantity+=product.netQuantity;row.netCents+=Number(product.netCents)||0;for(const [field,value] of [['turnoverCents',rawTurnoverValue(product)],['cancelledTurnoverCents',cancelledTurnoverValue(product)],['salesTurnoverCents',after]])row[field]=row[field]===null||!Number.isSafeInteger(value)?null:row[field]+value;if(product.refundsVerified===false)row.refundsVerified=false;
 }
 return [...groups.values()].sort((a,b)=>b.netQuantity-a.netQuantity||(adjusted?(b.turnoverCents??-1)-(a.turnoverCents??-1):b.netCents-a.netCents)||a.title.localeCompare(b.title,'tr')).slice(0,limit).map((row,index)=>({...row,rank:index+1,channels:[...row.channels]}));
}
