import {upload} from './api.js';
import {t} from './i18n.js';
import {toast} from './ui.js';
import {prepareReportSave,saveReportFile} from './report-download.js';
import {sameWorkspace} from './workspace-context.js';

export const archiveUpdateEvent='omnigo-file-center-saved';
const archiveUrl=(company,id='')=>'/file-center/archive'+(id?'/'+id:'')+'?'+new URLSearchParams({company});

export function fileCenterRecorder(state,token,category){
 if(state.demo||state.user?.role!=='admin'||!Number.isSafeInteger(Number(token.company))||Number(token.company)<1)return undefined;
 return file=>{
  if(!sameWorkspace(state,token))return;
  const form=new FormData();
  form.set('file',file.blob,file.filename);
  form.set('category',category);
  void (async()=>{
   try{
    const send=()=>upload(archiveUrl(token.company),form,{signal:AbortSignal.timeout(25000)});
    try{await send();}
    catch(error){
     if(!sameWorkspace(state,token)||(error.status&&error.status<500))throw error;
     await send();
    }
    window.dispatchEvent(new CustomEvent(archiveUpdateEvent,{detail:token}));
   }catch{
    if(sameWorkspace(state,token))toast(t('Dosya Merkezi dosyayı arşive ekleyemedi.'));
   }
  })();
 };
}

export async function downloadArchivedFile(file,company,current){
 const destination=await prepareReportSave(file.name);
 if(destination==='cancelled'||!current())return destination||'stale';
 const {blob}=await readArchivedFile(file,company);
 return saveReportFile(blob,file.name,current,globalThis,destination,{browserDownload:true});
}

export async function readArchivedFile(file,company){
 const response=await fetch('/api'+archiveUrl(company,file.id),{
  credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(30000),
 });
 if(!response.ok)throw new Error(t('Arşiv dosyası indirilemedi.'));
 const blob=await response.blob();
 if(blob.size!==file.size)throw new Error(t('Arşiv dosyası eksik geldi. Yeniden deneyin.'));
 return {blob,filename:file.name};
}
