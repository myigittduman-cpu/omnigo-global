import {api} from './api.js';
import {html,t} from './i18n.js';
import {esc,toast} from './ui.js';
import {confirmAction} from './action-confirmation.js';
import {applyWorkspaceMenuLabels,workspaceMenuLabelFields,workspaceMenuLabelLimit} from './workspace-menu-labels.js';

function editor(labels,users,userId){
 return html`<form class="form-grid">
  <label class="full-width">Değişikliğin kapsamı<select data-menu-name-scope><option value="user">Bu kullanıcı için güncelle</option><option value="store" selected>Bu mağazanın tüm kullanıcıları için güncelle</option><option value="global">Tüm altyapıda güncelle</option></select></label>
  <label class="full-width" data-menu-name-user-field hidden>Kullanıcı<select data-menu-name-user>${users.map(user=>html`<option value="${Number(user.id)}" ${Number(user.id)===Number(userId)?'selected':''}>${esc(user.name)} · ${Number(user.id)}</option>`).join('')}</select></label>
  <p class="form-help full-width">Seçtiğiniz kapsamda menü, sayfa ve yetki başlıkları birlikte güncellenir. Daha sonra başka kapsamda düzenlenen aynı ad için en son kaydedilen seçim geçerlidir. İzinler ve aktarımlar değişmez.</p>
  ${workspaceMenuLabelFields(labels).map(group=>html`<fieldset class="shipping-scope full-width"><legend>${esc(group.label)}</legend><div class="form-grid">${group.fields.map(({key,label,placeholder,value})=>html`<label><span data-menu-name-caption="${esc(key)}" data-menu-name-default="${esc(label)}">${esc(label)}</span><input type="text" name="${esc(key)}" value="${esc(value)}" placeholder="${esc(placeholder)}" maxlength="${workspaceMenuLabelLimit}" autocomplete="off"></label>`).join('')}</div></fieldset>`).join('')}
  <p class="form-error full-width" role="alert"></p><p class="form-help full-width" data-menu-name-status role="status"></p><button class="btn primary" type="submit">Menü adlarını kaydet</button>
 </form>`;
}
export function bindCustomerMenuLabelEditor(section,{endpoint,company,state,current}){
 let loading=false;
 return async function load(){
  if(loading||!current()||section.querySelector('form'))return;
  loading=true;section.innerHTML=html`<p role="status" class="form-help">Menü adları yükleniyor…</p>`;
  try{
   let record=await api(endpoint);if(!current())return;
   section.innerHTML=editor(record.labels,record.users,Number(state.user?.id));
   const form=section.querySelector('form'),scope=form.querySelector('[data-menu-name-scope]'),user=form.querySelector('[data-menu-name-user]'),userField=form.querySelector('[data-menu-name-user-field]');
   const fields=[...form.querySelectorAll('input[name]')],save=form.querySelector('[type=submit]'),alert=form.querySelector('[role=alert]'),status=form.querySelector('[data-menu-name-status]');
   let selected={scope:'store',userId:user.value},busy=false;
   const updateCaptions=()=>{const labels=record.targetEffective?.labels||{};for(const caption of form.querySelectorAll('[data-menu-name-caption]'))caption.textContent=labels[caption.dataset.menuNameCaption]||record.targetDefaults?.[caption.dataset.menuNameCaption]||caption.dataset.menuNameDefault;};
   updateCaptions();
   const values=()=>Object.fromEntries(fields.map(field=>[field.name,field.value.trim()]).filter(([,value])=>value));
   const dirty=()=>JSON.stringify(values())!==JSON.stringify(Object.fromEntries(fields.map(field=>[field.name,record.labels[field.name]||'']).filter(([,value])=>value)));
   const disable=value=>{busy=value;save.disabled=value;scope.disabled=value;user.disabled=value;fields.forEach(field=>field.disabled=value);};
   async function changeScope(){
    if(busy||!current())return;
    const next={scope:scope.value,userId:user.value};
    if(dirty()&&!await confirmAction(t('Kaydedilmemiş menü adlarını bırakıp diğer kapsamı açmak istiyor musunuz?'))){scope.value=selected.scope;user.value=selected.userId;return;}
    if(!current())return;disable(true);alert.textContent='';status.textContent=t('Seçili kapsam yükleniyor…');
    try{
     const url=endpoint+(endpoint.includes('?')?'&':'?')+'scope='+next.scope+(next.scope==='user'?'&userId='+Number(next.userId):'');
     const response=await api(url);if(!current())return;
     record=response;updateCaptions();selected=next;userField.hidden=next.scope!=='user';fields.forEach(field=>field.value=record.labels[field.name]||'');status.textContent=t('Kaydedilmiş adlar gösteriliyor.');
    }catch(error){if(current()){alert.textContent=error.message;scope.value=selected.scope;user.value=selected.userId;}}
    finally{disable(false);}
   }
   scope.onchange=user.onchange=changeScope;
   form.onsubmit=async event=>{
    event.preventDefault();if(busy||!current())return false;
    if(!current())return false;disable(true);alert.textContent='';status.textContent='';
    try{
     const saved=await api(endpoint,'PUT',{scope:selected.scope,userId:Number(selected.userId),version:record.version,labels:values()});
     if(!current())return false;record=saved;updateCaptions();fields.forEach(field=>field.value=saved.labels[field.name]||'');applyWorkspaceMenuLabels(state,document,{id:company,menuLabels:saved.effective});status.textContent=t('Menü adları kaydedildi.');toast(t('Menü adları kaydedildi.'));return true;
    }catch(error){if(current())alert.textContent=error.message;return false;}
    finally{disable(false);}
   };
  }catch(error){if(current()){section.innerHTML=html`<p class="form-error" role="alert">${esc(error.message)}</p><button class="btn" type="button">Tekrar dene</button>`;section.querySelector('button').onclick=load;}}
  finally{loading=false;}
 };
}
