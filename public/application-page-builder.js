import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';

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
 <footer class="page-builder-publish"><div><strong>Yayınlanan sayfa: ${model.published.items.length}</strong><small>Taslak kaydedilince canlıya çıkmaz. Yayınla düğmesiyle tüm uygun hesaplarda görünür.</small></div><button class="btn" data-rollback ${model.previous.items.length?'':'disabled'}>Önceki yayına dön</button><button class="btn primary" data-publish>Yayınla</button></footer></section>`;
}
function pageFromForm(form,current){
 const values=Object.fromEntries(new FormData(form));
 return {...current,...values,companyIds:[...form.querySelectorAll('[name=companyIds]:checked')].map(input=>Number(input.value))};
}
export async function openApplicationPageBuilder(companies=[]){
 const dialog=openDialog('Sayfa ve Modül Oluşturucu','<p role="status">Sayfalar yükleniyor…</p>',{className:'settings-dialog page-builder-dialog',loading:true});
 const body=dialog.querySelector('.dialog-body');let result;
 try{result=await api('/account/page-builder');}
 catch(error){body.innerHTML=`<p class="form-error" role="alert">${esc(error.message)}</p>`;dialog.setAttribute('aria-busy','false');return;}
 let model={...result,selected:0};
 const paint=()=>{
  body.innerHTML=shell(model,companies);dialog.setAttribute('aria-busy','false');
  body.querySelectorAll('[data-page-index]').forEach(button=>button.onclick=()=>{model.selected=Number(button.dataset.pageIndex);paint();});
  body.querySelector('[data-new-page]').onclick=()=>{model.draft.items=[...model.draft.items,emptyPage()];model.selected=model.draft.items.length-1;paint();};
  const form=body.querySelector('[data-page-editor]');
  if(form){
   for(const name of ['role','menuGroup','layout','scope'])form.elements[name].value=model.draft.items[model.selected][name];
   form.onsubmit=async event=>{event.preventDefault();await saveDraft(form);};
   form.querySelector('[data-delete]').onclick=()=>{model.draft.items=model.draft.items.filter((_,index)=>index!==model.selected);model.selected=Math.max(0,model.selected-1);paint();};
   form.querySelector('[data-preview]').onclick=()=>{model.draft.items[model.selected]=pageFromForm(form,model.draft.items[model.selected]);const preview=openDialog('Sayfa önizlemesi',applicationPageMarkup(model.draft.items[model.selected]),{className:'settings-dialog page-preview-dialog'});preview.querySelector('.dialog-body')?.setAttribute('data-preview-layout',model.draft.items[model.selected].layout);};
  }
  body.querySelector('[data-publish]').onclick=async()=>{try{if(!await saveDraft(form,false))return;const saved=await api('/account/page-builder/publish','POST',{version:model.draft.version});model.published=saved.published;toast('Sayfalar yayımlandı. Açık çalışma alanları yenilendiğinde görünür.');paint();}catch(error){toast(error.message);}};
  body.querySelector('[data-rollback]').onclick=async()=>{try{const saved=await api('/account/page-builder/rollback','POST',{version:model.published.version});model.published=saved.published;result=await api('/account/page-builder');model={...result,selected:0};toast('Önceki yayın geri yüklendi.');paint();}catch(error){toast(error.message);}};
 };
 const saveDraft=async(form,notify=true)=>{
  if(form)model.draft.items[model.selected]=pageFromForm(form,model.draft.items[model.selected]);
  try{model.draft=await api('/account/page-builder','PUT',{version:model.draft.version,items:model.draft.items});if(notify)toast('Sayfa taslağı kaydedildi.');paint();return true;}
  catch(error){const status=form?.querySelector('[role=status]');if(status)status.textContent=error.message;else toast(error.message);return false;}
 };
 paint();
}
export function applicationPageMarkup(page){
 const paragraphs=String(page.content||'').split(/\n{2,}/).map(text=>`<p>${esc(text).replaceAll('\n','<br>')}</p>`).join('');
 const layout=['standard','wide','two-column'].includes(page.layout)?page.layout:'standard';
 const actionUrl=String(page.actionUrl||'');const safeAction=/^(https:\/\/|\/)[^\s]+$/.test(actionUrl);
 return `<article class="custom-page custom-page--${layout}">${page.notice?`<aside class="custom-page-notice">${esc(page.notice)}</aside>`:''}<section class="custom-page-content">${paragraphs}</section>${page.actionLabel&&safeAction?`<a class="btn primary" href="${esc(actionUrl)}" ${actionUrl.startsWith('https://')?'target="_blank" rel="noopener"':''}>${esc(page.actionLabel)}</a>`:''}</article>`;
}
