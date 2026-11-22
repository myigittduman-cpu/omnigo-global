import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {createValueDraftGuard} from './value-draft-guard.js';
import {pageActionUrl} from './page-action-url.js';

const emptyPage=()=>({slug:'yeni-sayfa',title:'Yeni sayfa',summary:'',role:'both',scope:'all',companyIds:[],layout:'standard',menuGroup:'management',content:'Sayfa içeriğini buraya yazın.',notice:'',actionLabel:'',actionUrl:''});
const field=(label,name,value='',type='text')=>`<label class="settings-field"><span>${label}</span><input name="${name}" type="${type}" value="${esc(value)}"></label>`;
function editor(page,companies){
 return `<form data-page-editor><div class="page-builder-fields">
  ${field('Sayfa adı','title',page.title)}${field('Kısa ad','slug',page.slug)}
  <label class="settings-field"><span>Kimler görebilir?</span><select name="role"><option value="both">Yönetici ve müşteri</option><option value="admin">Yalnız yönetici</option><option value="client">Yalnız müşteri</option></select></label>
  <label class="settings-field"><span>Menü bölümü</span><select name="menuGroup"><option value="management">Yönetim</option><option value="operations">Operasyon</option><option value="finance">Finans ve analiz</option><option value="system">Destek ve sistem</option></select></label>
  <label class="settings-field"><span>Yerleşim</span><select name="layout"><option value="standard">Standart</option><option value="wide">Geniş</option><option value="two-column">İki sütun</option></select></label>
  <label class="settings-field"><span>Mağaza kapsamı</span><select name="scope"><option value="all">Tüm mevcut ve yeni mağazalar</option><option value="selected">Yalnız seçilen mağazalar</option></select></label>
  <fieldset class="page-builder-companies"><legend>Seçili mağazalar</legend>${companies.map(company=>`<label><input type="checkbox" name="companyIds" value="${company.id}" ${page.companyIds.includes(Number(company.id))?'checked':''}> ${esc(company.name)}</label>`).join('')}</fieldset>
  <label class="settings-field full-width"><span>Sayfa açıklaması</span><input name="summary" value="${esc(page.summary)}"></label>
  <label class="settings-field full-width"><span>Ana içerik</span><textarea name="content" rows="8">${esc(page.content)}</textarea><small>Düz metin kullanılır; HTML ve komut çalıştırılmaz.</small></label>
  <label class="settings-field full-width"><span>Bilgi notu</span><textarea name="notice" rows="3">${esc(page.notice)}</textarea></label>
  ${field('Bağlantı düğmesi metni','actionLabel',page.actionLabel)}${field('Bağlantı adresi','actionUrl',page.actionUrl)}
 </div><div class="settings-actions"><button class="btn primary" type="submit">Taslağı kaydet</button><button class="btn" type="button" data-preview>Önizle</button><button class="btn danger" type="button" data-delete>Sayfayı sil</button></div><p role="status"></p></form>`;
}
function shell(model,companies){
 return `<section class="page-builder"><header><div><span class="management-kicker">Kodsuz ve güvenli</span><h3>Sayfa ve Modül Oluşturucu</h3><p>Yeni sayfalar ortak tema, responsive düzen, erişim ve geçiş kurallarını otomatik kullanır.</p></div><button class="btn primary" data-new-page>Yeni sayfa</button></header>
 <div class="page-builder-workspace"><nav aria-label="Taslak sayfalar">${model.draft.items.map((page,index)=>`<button type="button" data-page-index="${index}" aria-pressed="${index===model.selected}"><strong>${esc(page.title)}</strong><small>/${esc(page.slug)}</small></button>`).join('')||'<p>Henüz taslak sayfa yok.</p>'}</nav><section data-page-editor-host>${model.draft.items[model.selected]?editor(model.draft.items[model.selected],companies):'<div class="empty-state"><h3>İlk sayfanızı oluşturun</h3><p>Yeni sayfa düğmesiyle başlayın.</p></div>'}</section></div>
 <footer class="page-builder-publish"><div><strong>Yayınlanan sayfa: ${model.published.items.length}</strong><small>Taslak kaydedilince canlıya çıkmaz. Yayınla düğmesiyle tüm uygun hesaplarda görünür.</small>${model.error?`<p class="form-error" role="alert">${esc(model.error)}</p>`:''}</div>${!model.draft.items.length?'<button class="btn" data-save-empty>Taslağı kaydet</button>':''}<button class="btn" data-rollback ${(model.canRollback??model.previous.items.length)?'':'disabled'}>Önceki yayına dön</button><button class="btn primary" data-publish>Yayınla</button></footer></section>`;
}
function pageFromForm(form,current){
 const values=Object.fromEntries(new FormData(form));
 return {...current,...values,companyIds:[...form.querySelectorAll('[name=companyIds]:checked')].map(input=>Number(input.value))};
}
export async function openApplicationPageBuilder(companies=[],{onPublished}={}){
 let model=null,busy=false,saveDraft;
 const readDraft=()=>{
  const form=model&&body.querySelector('[data-page-editor]');
  if(form&&model.draft.items[model.selected])model.draft.items[model.selected]=pageFromForm(form,model.draft.items[model.selected]);
  return model?.draft.items??[];
 };
 const guard=createValueDraftGuard({label:'Sayfa taslağı',read:readDraft,restore:items=>{model.draft.items=items;paint();},write:async items=>{
  model.draft.items=items;busy=true;body.querySelectorAll('button,input,select,textarea').forEach(field=>field.disabled=true);
  try{return await saveDraft(null);}finally{busy=false;paint();}
 }});
 const canLeave=guard.canLeave.bind(guard);guard.canLeave=()=>busy?Promise.resolve(false):canLeave();
 const dialog=openDialog('Sayfa ve Modül Oluşturucu','<p role="status">Sayfalar yükleniyor…</p>',{className:'settings-dialog page-builder-dialog',draftGuard:guard,loading:true});
 const body=dialog.querySelector('.dialog-body');let result;
 try{result=await api('/account/page-builder');}
 catch(error){body.innerHTML=`<p class="form-error" role="alert">${esc(error.message)}</p>`;dialog.setAttribute('aria-busy','false');return;}
 model={...result,selected:0,error:''};
 const mutate=async operation=>{
  if(busy)return;readDraft();busy=true;model.error='';
  body.querySelectorAll('button,input,select,textarea').forEach(field=>field.disabled=true);
  try{await operation();}catch(error){model.error=error.message;toast(error.message);}
  finally{busy=false;paint();}
 };
 const refreshPublished=async()=>{try{await onPublished?.();}catch{toast('Sayfalar kaydedildi. Çalışma alanını yenileyin.');}};
 const paint=()=>{
  dialog.setAttribute('aria-busy','false');
  if(model.preview){
   body.innerHTML=`<section data-preview-layout="${esc(model.draft.items[model.selected].layout)}">${applicationPageMarkup(model.draft.items[model.selected])}<div class="settings-actions"><button class="btn" data-back-editor>Düzenlemeye dön</button></div></section>`;
   body.querySelector('[data-back-editor]').onclick=()=>{model.preview=false;paint();};return;
  }
  body.innerHTML=shell(model,companies);
  body.querySelectorAll('[data-page-index]').forEach(button=>button.onclick=()=>{if(busy)return;readDraft();model.selected=Number(button.dataset.pageIndex);paint();});
  body.querySelector('[data-new-page]').onclick=()=>{
   if(busy)return;if(model.draft.items.length>=24){toast('En fazla 24 özel sayfa oluşturabilirsiniz.');return;}
   readDraft();const page=emptyPage(),slugs=new Set(model.draft.items.map(item=>item.slug));let number=2;
   while(slugs.has(page.slug))page.slug='yeni-sayfa-'+number++;
   model.draft.items=[...model.draft.items,page];model.selected=model.draft.items.length-1;paint();
  };
  const form=body.querySelector('[data-page-editor]');
  if(form){
   for(const name of ['role','menuGroup','layout','scope'])form.elements[name].value=model.draft.items[model.selected][name];
   form.onsubmit=async event=>{event.preventDefault();await mutate(()=>saveDraft(form));};
   form.querySelector('[data-delete]').onclick=()=>{if(busy)return;model.draft.items=model.draft.items.filter((_,index)=>index!==model.selected);model.selected=Math.max(0,model.selected-1);paint();};
   form.querySelector('[data-preview]').onclick=()=>{if(busy)return;readDraft();model.preview=true;paint();};
  }
  body.querySelector('[data-save-empty]')?.addEventListener?.('click',()=>mutate(()=>saveDraft(null)));
  body.querySelector('[data-publish]').onclick=()=>mutate(async()=>{if(!await saveDraft(form,false))return;Object.assign(model,await api('/account/page-builder/publish','POST',{version:model.draft.version}));paint();guard.accept();toast('Sayfalar yayımlandı. Açık çalışma alanları yenilendiğinde görünür.');await refreshPublished();});
  body.querySelector('[data-rollback]').onclick=()=>mutate(async()=>{Object.assign(model,await api('/account/page-builder/rollback','POST',{version:model.published.version}),{selected:0});paint();guard.accept();toast('Önceki yayın geri yüklendi.');await refreshPublished();});
  if(busy)body.querySelectorAll('button,input,select,textarea').forEach(field=>field.disabled=true);
 };
 saveDraft=async(form,notify=true)=>{
  if(form)model.draft.items[model.selected]=pageFromForm(form,model.draft.items[model.selected]);
  try{model.draft=await api('/account/page-builder','PUT',{version:model.draft.version,items:model.draft.items});paint();guard.accept();if(notify)toast('Sayfa taslağı kaydedildi.');return true;}
  catch(error){model.error=error.message;toast(error.message);return false;}
 };
 paint();guard.accept();
}
export function applicationPageMarkup(page){
 const paragraphs=String(page.content||'').split(/\n{2,}/).map(text=>`<p>${esc(text).replaceAll('\n','<br>')}</p>`).join('');
 const layout=['standard','wide','two-column'].includes(page.layout)?page.layout:'standard';
 const actionUrl=pageActionUrl(page.actionUrl);const safeAction=Boolean(actionUrl);
 return `<article class="custom-page custom-page--${layout}">${page.notice?`<aside class="custom-page-notice">${esc(page.notice)}</aside>`:''}<section class="custom-page-content">${paragraphs}</section>${page.actionLabel&&safeAction?`<a class="btn primary" href="${esc(actionUrl)}" ${/^https:\/\//i.test(actionUrl)?'target="_blank" rel="noopener"':''}>${esc(page.actionLabel)}</a>`:''}</article>`;
}
