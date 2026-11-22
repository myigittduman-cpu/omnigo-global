import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {canShareReport,encodeReport,invokeReportBridge,reportFile,requestFileAction,reportSupportError} from './report-file-action.js';
const MAX_BYTES=32*1024*1024;
export async function prepareReportSave(filename,environment=globalThis){
 if(environment.__OMNIGO_DESKTOP__){
  const version=String(environment.__OMNIGO_DESKTOP_VERSION__||'0.0.0').split('.').map(Number);
  if(version[0]===0&&(version[1]<2||(version[1]===2&&version[2]<24)))throw new Error(i18nT('Kayıt konumu seçebilmek için giriş ekranındaki “Güncellemeler” seçeneğiyle Windows uygulamasını 0.2.24 veya üzeri sürüme güncelleyin.'));
  return null;
 }
 if(typeof environment.showSaveFilePicker!=='function')return null;
 const extension=filename.split('.').pop().toLowerCase(),types={xlsx:{description:i18nT('Excel çalışma kitabı'),accept:{'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':['.xlsx']}},pdf:{description:i18nT('PDF belgesi'),accept:{'application/pdf':['.pdf']}},jpeg:{description:i18nT('JPEG görseli'),accept:{'image/jpeg':['.jpeg','.jpg']}},jpg:{description:i18nT('JPEG görseli'),accept:{'image/jpeg':['.jpg','.jpeg']}}},type=types[extension];
 if(!type)throw new Error(i18nT('Dosya biçimi desteklenmiyor.'));
 try{return await environment.showSaveFilePicker({suggestedName:filename,startIn:'desktop',types:[type],excludeAcceptAllOption:true});}
 catch(error){if(error.name==='AbortError')return 'cancelled';if(['SecurityError','NotAllowedError'].includes(error.name))return null;throw new Error(i18nT('Kaydetme penceresi açılamadı. İndirme düğmesine yeniden basın.'));}
}
export async function saveReportFile(blob,filename,isCurrent=()=>true,environment=globalThis,destination=null,{browserDownload=false}={}){
 if(!isCurrent())return 'stale';
 if(destination==='cancelled')return 'cancelled';
 if(!blob.size||blob.size>MAX_BYTES)throw new Error(i18nT('Rapor çok büyük. Daha kısa bir tarih aralığı seçin.'));
 if(destination){
  const stream=await destination.createWritable();
  try{
   if(!isCurrent()){await stream.abort();return 'stale';}
   await stream.write(blob);
   if(!isCurrent()){await stream.abort();return 'stale';}
   await stream.close();return 'saved';
  }catch(error){await stream.abort().catch(()=>{});throw new Error(i18nT('Dosya kaydedilemedi. Dosyayı kapatıp yeniden deneyin.'));}
 }
 if(typeof environment.__OMNIGO_SAVE_REPORT__==='function'){
  if(environment.__OMNIGO_DESKTOP__)await prepareReportSave(filename,environment);
  const contents=await encodeReport(blob,environment);
  if(!isCurrent())return 'stale';
  const result=await invokeReportBridge('__OMNIGO_SAVE_REPORT__',filename,contents,isCurrent,environment);
  if(!['saved','downloaded','cancelled','stale'].includes(result))throw new Error(i18nT('Dosyanın kaydedildiği doğrulanamadı.'));
  return result;
 }
 if(environment.__OMNIGO_DESKTOP__ || environment.isTauri || environment.__TAURI_INTERNALS__){
  throw new Error(i18nT('Windows uygulamasını giriş ekranındaki “Güncellemeler” seçeneğinden güncelleyip yeniden açın. Bu sürümde dosya kaydetme bağlantısı hazır değil.'));
 }
 if(browserDownload&&!/OmnigoAndroid\//.test(environment.navigator?.userAgent||'')){
  const link=environment.document.createElement('a');
  const url=environment.URL.createObjectURL(blob);
  link.href=url;link.download=filename;link.hidden=true;
  try{environment.document.body.append(link);link.click();return 'started';}
  finally{link.remove();environment.setTimeout(()=>environment.URL.revokeObjectURL(url),60000);}
 }
 if(typeof environment.showSaveFilePicker==='function'){
  const save=async()=>{const handle=await prepareReportSave(filename,environment);if(handle==='cancelled')return 'cancelled';if(!handle)throw new Error(i18nT('Kaydetme ekranını açmak için düğmeye yeniden dokunun.'));return saveReportFile(blob,filename,isCurrent,environment,handle);};
  const handle=await prepareReportSave(filename,environment);
  if(handle)return saveReportFile(blob,filename,isCurrent,environment,handle);
  return requestFileAction({filename,label:i18nT('Kaydetme konumunu seç'),description:i18nT('Dosyanın adını ve kaydedileceği klasörü seçin.'),current:isCurrent,action:save},environment);
 }
 const file=reportFile(blob,filename,environment);
 if(!canShareReport(file,environment))throw reportSupportError(environment,i18nT('kayıt yeri seçimi'));
 const save=()=>environment.navigator.share({files:[file]}).then(()=> 'save-sheet');
 try{return await save();}catch(error){
  if(error.name==='AbortError')return 'cancelled';
  if(!['NotAllowedError','SecurityError'].includes(error.name))throw new Error(i18nT('Dosya kaydetme ekranı açılamadı.'));
  return requestFileAction({filename,label:i18nT('Kaydetme ekranını aç'),description:i18nT('Açılan cihaz menüsünde “Dosyalara Kaydet” seçeneğiyle klasörü seçin.'),current:isCurrent,action:save},environment);
 }
}
export function downloadStatus(result){
 return {
  saved:i18nT('Rapor seçtiğiniz klasöre kaydedildi.'),
  'save-sheet':i18nT('Cihaz menüsünde “Dosyalara Kaydet” seçeneğiyle kayıt konumunu seçebilirsiniz.'),
  downloaded:i18nT('Dosya İndirilenler klasörüne kaydedildi.'),
  cancelled:'Kaydetme iptal edildi.',
  started:i18nT('İndirme başlatıldı. Dosyayı tarayıcınızın indirilenler bölümünden açabilirsiniz.'),
  legacy:i18nT('İndirme başlatıldı. Dosya görünmüyorsa giriş ekranındaki “Güncellemeler” seçeneğiyle uygulamayı güncelleyin.'),
  stale:''
 }[result]||i18nT('Dosyanın kaydedildiği doğrulanamadı.');
}
