const earlyStyles=['/transition-policy.css','/city-products.css','/customer-setup.css','/document-print.css','/shipment-document-preview.css','/mobile-permission.css','/chat.css','/sales-period-metrics.css','/light-theme.css','/parliament-theme.css'];
/** Warm presentation assets only, before a user can open their first dialog. */
export function preparePortalStyles(doc=globalThis.document){
 if(!doc?.head)return Promise.resolve();
 for(const href of earlyStyles)if(![...doc.querySelectorAll('link[rel=stylesheet]')].some(link=>new URL(link.href,doc.baseURI).pathname===href)){
  const link=doc.createElement('link');link.rel='stylesheet';link.href=href;doc.head.append(link);
 }
 const pending=[...doc.querySelectorAll('link[rel=stylesheet]')].filter(link=>!link.sheet).map(link=>new Promise(resolve=>{
  const finish=()=>{clearTimeout(timer);link.removeEventListener('load',finish);link.removeEventListener('error',finish);resolve();};
  const timer=setTimeout(finish,2500);link.addEventListener('load',finish,{once:true});link.addEventListener('error',finish,{once:true});
 }));
 return Promise.all(pending);
}
