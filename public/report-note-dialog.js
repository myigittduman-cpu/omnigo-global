import {customerCan} from './customer-access.js';
import {documentOutputActions} from './document-output-actions.js';
import {openDocumentPrint} from './document-print.js';
import {html,t} from './i18n.js';
import {downloadStatus,prepareReportSave,saveReportFile} from './report-download.js';
import {createNoteFile,noteFilename} from './report-note-export.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';
import {esc,openDialog} from './ui.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';

function downloadText(note){
 const blob=new Blob([[note.title,note.month,'',note.content].join('\n')],{type:'text/plain;charset=utf-8'});
 const url=URL.createObjectURL(blob);
 const link=document.createElement('a');
 link.href=url;link.download=`Omnigo-rapor-${note.month}.txt`;
 link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}

export function openReportNote(state,id){
 const note=state.data?.reports?.find(entry=>Number(entry.id)===Number(id));
 if(!note||state.view!=='reports'||!customerCan(state,'reports'))return;
 const token=workspaceToken(state);
 const company=state.companies.find(entry=>Number(entry.id)===Number(token.company));
 const current=()=>Boolean(company)&&sameWorkspace(state,token)&&state.view==='reports'&&customerCan(state,'reports','export')
  &&state.data?.reports?.some(entry=>entry.id===note.id&&entry.content===note.content&&entry.title===note.title&&entry.month===note.month);
 const controls=current()?html`<div class="report-downloads">${documentOutputActions({
  pdf:{attr:'data-note-download',value:'pdf'},share:{attr:'data-note-share'},print:{attr:'data-note-print'},
 })}<button type="button" class="btn" data-note-download="xlsx">Excel indir</button>
 <button type="button" class="btn" data-note-download="text">Metin olarak indir</button></div><p class="report-export-status" role="status"></p>`:'';
 const dialog=openDialog(note.title,html`<span class="eyebrow">${esc(note.month)}</span><article class="report-text">${esc(note.content)}</article>${controls}`);
 const body=dialog.querySelector('.dialog-body');
 const alive=()=>current()&&dialog.open&&body.isConnected;
 let busy=false;
 body.addEventListener('click',async event=>{
  const button=event.target.closest('button');
  if(!button||busy||!alive())return;
  if(button.hasAttribute('data-note-share')){
   openPreparedPdfShare({title:t('Raporu paylaş'),description:t('Raporun PDF belgesi hazırlanıyor.'),current,createFile:()=>createNoteFile(note,company,'pdf')});
   return;
  }
  if(button.hasAttribute('data-note-print')){
   openDocumentPrint({current,directPdf:true,createFile:()=>createNoteFile(note,company,'pdf')});
   return;
  }
  const type=button.dataset.noteDownload;
  if(type==='text'){downloadText(note);return;}
  if(type!=='pdf'&&type!=='xlsx')return;
  const status=body.querySelector('.report-export-status');
  busy=true;button.disabled=true;status.textContent=t('Rapor dosyası hazırlanıyor…');
  try{
   const destination=await prepareReportSave(noteFilename(note,company,type));
   if(!alive())return;
   if(destination==='cancelled'){status.textContent=downloadStatus(destination);return;}
   const file=await createNoteFile(note,company,type);
   const result=await saveReportFile(file.blob,file.filename,alive,globalThis,destination,{browserDownload:true});
   if(alive())status.textContent=downloadStatus(result);
  }catch(error){if(alive())status.textContent=error.message;}
  finally{busy=false;if(button.isConnected)button.disabled=false;}
 });
}
