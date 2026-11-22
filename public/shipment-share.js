import {turkeyCities} from './turkey-cities.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {esc,openDialog} from './ui.js';
import {documentShareControls,bindDocumentShare} from './document-share.js';
import {exportCompanyName} from './company-export-brand.js';
export function shipmentShareSelection(url=location.href){
 const query=new URL(url).searchParams;if(query.get('view')!=='shipments')return null;
 const company=Number(query.get('company')),provider=query.get('provider'),city=query.get('city'),month=query.get('month');
 if(!marketplaceVisible(provider))return null;
 if(!Number.isSafeInteger(company)||company<1||!['all','Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş'].includes(provider)||!['all',...turkeyCities].includes(city)||!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)||month<'2026-06')return null;
 return {company,provider,city,month};
}
export function bindShipmentShare({model,company,current,endpoint,onFileReady}){
 async function click(button){
  if(!button.hasAttribute('data-shipment-share'))return false;
  if(!model.canShare)return true;
  const title=`${model.city==='all'?i18nT('Tüm şehirler'):model.city} · ${model.provider==='all'?i18nT('Tüm pazaryerleri'):model.provider} · ${model.month}`;
  const dialog=openDialog(i18nText`${exportCompanyName(company?.())} · Şehir dışı sevkiyat listesini paylaş`,i18nHtml`<div class="shipment-document-options" data-shipment-share-content><p>${esc(title)}</p>${documentShareControls()}</div>`);
  const content=dialog.querySelector('[data-shipment-share-content]'),alive=()=>current()&&dialog.open&&content.isConnected;
  const exportUrl=endpoint('export'),selection={query:model.query,filters:{...model.filters}};let snapshotPromise;
  bindDocumentShare(content,{current:alive,sharePdfOnOpen:true,createFile:async type=>{
   const snapshot=await(snapshotPromise??=api(exportUrl,'POST',selection).catch(error=>{snapshotPromise=null;throw error;}));if(!alive())throw new Error(i18nT('Sayfa değişti. Paylaşımı yeniden açın.'));
   if(!snapshot.items.length)throw new Error(i18nT('Paylaşılacak kayıt bulunamadı.'));
   const {createShipmentPdf,createShipmentWorkbook,shipmentExportFilename}=await import('./shipment-export.js');
   const filename=shipmentExportFilename(snapshot,type);
   const file={blob:await(type==='xlsx'?createShipmentWorkbook(snapshot):createShipmentPdf(snapshot)),filename};
   if(alive())onFileReady?.(file);
   return file;
  }});
  return true;
 }
 return {click};
}
