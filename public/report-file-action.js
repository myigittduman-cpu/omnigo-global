import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {windowsShareFilename} from './share-filename.js';
const mimeTypes={pdf:'application/pdf',xlsx:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',jpeg:'image/jpeg',jpg:'image/jpeg'};
export function reportFile(blob,filename,environment=globalThis){
 return new environment.File([blob],filename,{type:mimeTypes[filename.split('.').pop().toLowerCase()]||blob.type});
}
export function canShareReport(file,environment=globalThis){
 return typeof environment.navigator?.share==='function'&&Boolean(environment.navigator.canShare?.({files:[file]}));
}
export function windowsFileSharing(environment=globalThis){
 return Boolean(environment.__OMNIGO_DESKTOP__||/Windows/i.test(environment.navigator?.userAgentData?.platform||environment.navigator?.userAgent||''));
}
export function reportSupportError(environment,action){
 const android=environment.navigator?.userAgent?.match(/OmnigoAndroid\/(\d+\.\d+\.\d+)/)?.[1];
 if(android)return new Error(i18nText`Android ${android} sürümünde ${i18nT(action)} bağlantısı hazır değil. Güncellemeler bölümünden Android 1.0.11 veya üzerini yükleyip uygulamayı yeniden açın. Uygulamayı silmeniz gerekmez.`);
 return new Error(action==='paylaşım'?i18nT('Bu tarayıcı dosya paylaşımını desteklemiyor. Safari veya Chrome’un güncel sürümünden açın.'):i18nT('Bu tarayıcı kayıt konumu seçimini desteklemiyor. Dosyayı güncel Omnigo uygulamasından veya destekleyen bir tarayıcıdan kaydedin.'));
}
export async function encodeReport(blob,environment=globalThis){
 return new Promise((resolve,reject)=>{const reader=new environment.FileReader();reader.onerror=()=>reject(new Error(i18nT('Rapor dosyası okunamadı.')));reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.readAsDataURL(blob);});
}
// Older Windows apps accept these PDFs only with their application prefix and a 240-byte name.
function compatibleWindowsReportName(filename){
 const published=/^([\p{L}\p{N} _-]+)(-rapor-\d{4}-(?:0[1-9]|1[0-2])\.pdf)$/u;
 const profitability=/^([\p{L}\p{N} _-]+)( \d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2} (?:[\p{L}\p{N} _-]+ )?K[âa]rl[ıi]l[ıi]k Analizi\.pdf)$/u;
 const cityProducts=/^([\p{L}\p{N} _-]+)( (?:0[1-9]|[12]\d|3[01])\.(?:0[1-9]|1[0-2])\.\d{4} (?:Şehir Dışı Ürünler|Sehir Disi Urunler|Out-of-City Products|Produkte für auswärtige Lieferungen|Produkte fur auswartige Lieferungen)\.pdf)$/u;
 const match=published.exec(filename)||profitability.exec(filename)||cityProducts.exec(filename);
 if(!match)return '';
 const prefix='Omnigo-',suffix=match[2],encoder=new TextEncoder();
 let name='',bytes=encoder.encode(prefix+suffix).length;
 for(const character of match[1].replace(/^Omnigo-/,'').trim()){
  bytes+=encoder.encode(character).length;
  if(bytes>240)break;
  name+=character;
 }
 return name?prefix+name.trimEnd()+suffix:'';
}
export async function invokeReportBridge(action,filename,encoded,current,environment){
 try{return await environment[action](filename,encoded);}
 catch(error){
  if(!environment.__OMNIGO_DESKTOP__||error?.message!=='Rapor dosyasının adı geçersiz.')throw error;
  const compatible=compatibleWindowsReportName(filename);
  if(compatible&&compatible!==filename){
   if(!current())return 'stale';
   return environment[action](compatible,encoded);
  }
  // The payout document keeps its agreed company filename even on older Windows apps.
  const payout=/ \d{2}\.\d{2}\.\d{2}-\d{2}\.\d{2}\.\d{2} (?:Ödeme|Odeme) Tablosu\.pdf$/u;
  if(filename.startsWith('Omnigo-')||!payout.test(filename))throw error;
  if(!current())return 'stale';
  throw new Error(i18nT('Ödeme tablosunun dosya adını korumak için İş Ortağım veya Yönetici Portalı Windows uygulamasını 0.2.63 veya üzeri sürüme güncelleyin.'));
 }
}
// A slow export can outlive the browser user gesture. Never substitute a download.
export function requestFileAction({filename,label,description,current,action},environment=globalThis){
 if(!current())return Promise.resolve('stale');
 const dialog=environment.document.createElement('dialog');dialog.className='report-file-action';
 const heading=environment.document.createElement('h2');heading.textContent=i18nT('Dosyanız hazır');
 const name=environment.document.createElement('p');name.textContent=filename;
 const help=environment.document.createElement('p');help.textContent=description;
 const status=environment.document.createElement('p');status.setAttribute('role','status');
 const submit=environment.document.createElement('button');submit.type='button';submit.className='btn primary';submit.textContent=label;
 const cancel=environment.document.createElement('button');cancel.type='button';cancel.className='btn';cancel.textContent=i18nT('Vazgeç');
 dialog.append(heading,name,help,submit,cancel,status);environment.document.body.append(dialog);
 return new Promise(resolve=>{
  let result='cancelled',busy=false;
  const finish=value=>{result=value;dialog.close();};
  submit.onclick=async()=>{if(busy)return;if(!current()){finish('stale');return;}busy=true;submit.disabled=true;
   try{finish(await action());}catch(error){if(error.name==='AbortError')finish('cancelled');else status.textContent=i18nT(error.message);}
   finally{busy=false;submit.disabled=false;}};
  cancel.onclick=()=>{if(!busy)finish('cancelled');};
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  dialog.addEventListener('close',()=>{dialog.remove();resolve(result);},{once:true});dialog.showModal();
 });
}
export async function shareReportFile(file,current=()=>true,environment=globalThis,{preferDesktopWebShare=false}={}){
 if(!current())return 'stale';
 const desktopWebShare=preferDesktopWebShare&&environment.__OMNIGO_DESKTOP__&&canShareReport(file,environment);
 if(!desktopWebShare&&typeof environment.__OMNIGO_SHARE_REPORT__==='function'){
  const encoded=await encodeReport(file,environment);if(!current())return 'stale';
  const filename=environment.__OMNIGO_DESKTOP__?windowsShareFilename(file.name):file.name;
  try{
   const result=await invokeReportBridge('__OMNIGO_SHARE_REPORT__',filename,encoded,current,environment);
   if(!['opened','shared','cancelled','stale'].includes(result))throw new Error(i18nT('Paylaşım ekranı açılamadı.'));
   return result;
  }catch(error){
   if(!environment.__OMNIGO_DESKTOP__||error?.message!=='Rapor dosyasının adı geçersiz.'||!canShareReport(file,environment))throw error;
   if(!current())return 'stale';
  }
 }
 if(!desktopWebShare&&!canShareReport(file,environment))throw reportSupportError(environment,'paylaşım');
 const targetName=windowsFileSharing(environment)?windowsShareFilename(file.name):file.name;
 const targetFile=targetName===file.name?file:new environment.File([file],targetName,{type:file.type,lastModified:file.lastModified});
 const share=()=>environment.navigator.share({files:[targetFile]}).then(()=> 'opened');
 try{return await share();}catch(error){
  if(error.name==='AbortError')return 'cancelled';
  if(!desktopWebShare&&['NotAllowedError','SecurityError'].includes(error.name))return requestFileAction({filename:file.name,label:i18nT('WhatsApp paylaşımını aç'),description:i18nT('Açılan paylaşım ekranında WhatsApp’ı, ardından göndereceğiniz kişiyi seçin.'),current,action:share},environment);
  throw new Error(i18nT('Dosya paylaşım ekranına aktarılamadı. Uygulamanın güncel olduğunu kontrol edip yeniden deneyin.'));
 }
}
