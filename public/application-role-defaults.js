import {workspaceMenuLabelMarkup} from './workspace-menu-labels.js';
import {api} from './api.js';
import {confirmAction} from './action-confirmation.js';
import {html,t,text} from './i18n.js';
import {applyPermissionChoice} from './permission-grant-choice.js';
import {esc,openDialog} from './ui.js';
import {addDialogBack} from './dialog-return.js';
import {createSettingsDraftGuard} from './settings-drafts.js';
import {loadingState,contentError} from './content-state.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

const endpoint='/management/role-defaults';
const groups=[
 ['Mağaza ve satış özeti',['overview','overviewSales','overviewTop','overviewWork','channels','tasks']],
 ['Sipariş ve stok işlemleri',['orders','returns','inventory','shipments','deliveries','cityProducts']],
 ['Finans, analiz ve belgeler',['analytics','payments','reports','shipmentInvoices']],
 ['Destek',['support']],
];

function rolePage(page,definition,actions,grants,role,state){
 const available=role!=='accounting_logistics'||page!=='support';
 const rights=grants[page]??{};
 const check=(action,label)=>html`<label><input type="checkbox" name="${esc(page+'.'+action)}"
  data-role-default-page="${esc(page)}" data-role-default-action="${esc(action)}"
  ${rights[action]&&available?'checked':''} ${available&&(action==='view'||rights.view)?'':'disabled'}>
  <span>${action==='view'&&state?workspaceMenuLabelMarkup(state,page,label):esc(t(label))}</span></label>`;
 const options=definition.actions.filter(action=>action!=='view');
 return html`<section>
  ${check('view',definition.label)}
  ${options.length&&available?html`<details class="permission-actions"><summary>${state?workspaceMenuLabelMarkup(state,page,definition.label):esc(t(definition.label))} ${t('işlem izinleri')}</summary>
   <div>${options.map(action=>check(action,actions[action])).join('')}</div></details>`:''}
  ${available?'':html`<small>Bu rol için destek taleplerine erişim kapalı.</small>`}
 </section>`;
}

function roleForm(result,role,state){
 const {roles,permissionsCatalog,actions}=result;
 const catalog=Object.fromEntries(Object.entries(permissionsCatalog).filter(([,definition])=>!definition.adminOnly));
 const categorized=new Set(groups.flatMap(([,pages])=>pages));
 const sections=[...groups,['Diğer izinler',Object.keys(catalog).filter(page=>!categorized.has(page))]];
 return html`<form data-role-defaults-form>
  <div class="settings-account-grid">${sections.map(([heading,keys])=>{
   const pages=keys.filter(page=>Object.hasOwn(catalog,page));
   return pages.length?html`<fieldset class="settings-account-card permission-card"><legend>${esc(t(heading))}</legend>
    ${pages.map(page=>rolePage(page,catalog[page],actions,roles[role].grants,role,state)).join('')}
   </fieldset>`:'';
  }).join('')}</div>
  <p class="form-error" role="alert"></p><p class="settings-note" role="status"></p>
  <div class="settings-actions"><button type="submit" class="btn primary" disabled>Varsayılan olarak ayarla</button></div>
 </form>`;
}

export function roleDefaultsView(result,{state}={}){
 const entries=Object.entries(result?.roles??{}),role=entries[0]?.[0];
 if(!role)return html`<p class="form-error" role="alert">Rol varsayılanları yüklenemedi.</p>`;
 return html`<section data-application-role-defaults>
  <p class="settings-note">Burada kaydettiğiniz rol izinleri tüm mağazalarda bundan sonra oluşturulan kullanıcılar için geçerlidir. Mevcut bir kullanıcıya uygulamak için o kullanıcının yetkilerinde Varsayılana dön seçeneğini kullanın ve kaydedin.</p>
  <label class="settings-field"><span>Mağaza rolü</span><select data-role-defaults-role>
   ${entries.map(([key,preset])=>html`<option value="${esc(key)}">${esc(preset.label)}</option>`).join('')}
  </select></label>
  <div data-role-defaults-editor>${roleForm(result,role,state)}</div>
 </section>`;
}

export function bindRoleDefaults(root,result,{drafts,current,state,onUpdated}){
 const panel=root?.matches('[data-application-role-defaults]')?root:root?.querySelector('[data-application-role-defaults]');
 if(!panel)return;
 const selector=panel.querySelector('[data-role-defaults-role]'),editor=panel.querySelector('[data-role-defaults-editor]');
 const active=()=>panel.isConnected&&current();
 let role=selector.value;

 function bindForm(){
  const form=editor.querySelector('[data-role-defaults-form]'),preset=result.roles[role];
  const error=form.querySelector('[role=alert]'),status=form.querySelector('[role=status]');
  const controls=[...form.querySelectorAll('[data-role-default-action]')];
  let grants=structuredClone(preset.grants);
  const valid=()=>active()&&form.isConnected;
  form.addEventListener('change',event=>{
   if(!event.target.matches('[data-role-default-action]'))return;
   const {roleDefaultPage:page,roleDefaultAction:action}=event.target.dataset;
   applyPermissionChoice(grants,page,action,event.target.checked);
   for(const control of controls){
    if(control.dataset.roleDefaultPage!==page)continue;
    const key=control.dataset.roleDefaultAction;
    control.checked=Boolean(grants[page]?.[key]);
    if(key!=='view')control.disabled=!grants[page]?.view;
   }
   status.textContent='';error.textContent='';
  });
  drafts.track(form,{label:preset.label,save:async()=>{
   if(!valid())return false;
   error.textContent='';status.textContent='';
   const approved=await confirmAction(text`${preset.label} rolü için yeni başlangıç izinlerini kaydetmek istiyor musunuz? Bu seçim yeni mağaza hesaplarında kullanılır.`,{
    heading:t('Rol varsayılanlarını kaydet'),approveLabel:t('Varsayılan olarak ayarla'),
   });
   if(!approved||!valid())return false;
   try{
    const saved=await api(endpoint,'PUT',{role,version:result.roles[role].version,grants},{confirmed:true});
    if(!valid())return true;
    Object.assign(result,saved);
    grants=structuredClone(saved.roles[role].grants);
    for(const control of controls){
     const {roleDefaultPage:page,roleDefaultAction:action}=control.dataset;
     control.checked=Boolean(grants[page]?.[action]);
    }
    status.textContent=t('Rol varsayılanları kaydedildi.');
    onUpdated?.();
    return true;
   }catch(failure){if(valid())error.textContent=failure.message;return false;}
  }});
 }

 selector.onchange=async()=>{
  const next=selector.value;
  selector.value=role;
  if(next===role||!Object.hasOwn(result.roles,next)||!active())return;
  selector.disabled=true;
  try{
   if(!await drafts.canLeave()||!active())return;
   role=next;selector.value=role;editor.innerHTML=roleForm(result,role,state);bindForm();
  }finally{selector.disabled=false;}
 };
 bindForm();
}

export function mountPermissionRoleDefaults(content,state,{drafts,current,onUpdated,onReady}){
 if(state.user?.role!=='admin'||!current())return;
 const active=()=>content.isConnected&&state.user?.role==='admin'&&current();
 async function load(){
  if(!active())return;
  content.innerHTML=loadingState('Rol varsayılanları yükleniyor…');
  try{
   const result=await api(endpoint);
   if(!active())return;
   content.innerHTML=roleDefaultsView(result,{state});
   bindRoleDefaults(content,result,{drafts,current:active,state,onUpdated});
   onReady?.();
  }catch(error){
   if(!active())return;
   content.innerHTML=contentError(error.message)+html`<button class="btn" type="button" data-retry-role-defaults>Yeniden dene</button>`;
   content.querySelector('[data-retry-role-defaults]').onclick=load;
  }
 }
 void load();
}

export function openPermissionRoleDefaults(state,{onBack,onUpdated}){
 if(state.user?.role!=='admin')return;
 const token=workspaceToken(state),drafts=createSettingsDraftGuard();
 const dialog=openDialog(t('Rol Varsayılanları'),html`<section class="settings-content" data-role-defaults-content></section>`,
  {className:'settings-dialog',draftGuard:drafts});
 addDialogBack(dialog,onBack,'Kullanıcı Yetkilerine dön');
 const content=dialog.querySelector('[data-role-defaults-content]');
 mountPermissionRoleDefaults(content,state,{drafts,onUpdated,current:()=>dialog.open&&sameWorkspace(state,token)});
}
