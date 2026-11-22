import {colorFields} from './workspace-palette.js';
import {applyWorkspaceDesign} from './workspace-design-state.js';

function paletteControls(){
 return Object.entries(colorFields).map(([key,[label,color]])=>html`<div class="workspace-color-field"><label><input type="checkbox" name="use_${key}"> ${t(label)}</label><input type="color" name="${key}" value="${color}" aria-label="${t(label)}"></div>`).join('');
}
import {api} from './api.js';
import {html,t} from './i18n.js';

export function workspaceDesignSettings(){
 return html`<section class="workspace-design-settings"><h3>Uygulama düzeni</h3><p class="settings-note">Seçiminiz tüm müşteriler, mevcut ve yeni kullanıcılar ile tüm cihazlarda ortak uygulanır. Önceki düzene istediğiniz zaman dönebilirsiniz.</p><form data-workspace-design-form><label class="settings-field">Düzen<select name="mode" disabled><option value="balanced">Dengeli düzen</option><option value="classic">Önceki düzen</option></select></label><fieldset disabled data-palette-fields><legend>Ortak renk paleti</legend><p class="settings-note">İşaretlediğiniz renkler tüm mağazalarda kullanılır. İşareti kaldırırsanız kişisel temanın rengi geçerli olur.</p>${paletteControls()}</fieldset><div class="settings-actions"><button class="btn" type="submit" disabled>Kaydet</button></div><p class="form-error" role="alert"></p><p role="status">Düzen yükleniyor…</p></form></section>`;
}
export async function bindWorkspaceDesignSettings(form,{drafts,current}){
 if(!form)return;const valid=()=>form.isConnected&&current(),status=form.querySelector('[role=status]'),error=form.querySelector('[role=alert]');let revision;
 try{
  const result=await api('/account/workspace-design');if(!valid())return;
  revision=result.workspaceDesign.version;form.elements.mode.value=result.workspaceDesign.mode;form.elements.mode.disabled=false;status.textContent='';
  for(const key of Object.keys(colorFields)){
   const input=form.elements[key],toggle=form.elements['use_'+key];
   const stored=result.workspaceDesign.palette?.[key];
   if(stored){input.value=stored;toggle.checked=true;}
   input.addEventListener('input',()=>{toggle.checked=true;});
  }
  form.querySelector('[data-palette-fields]').disabled=false;
  drafts.track(form,{label:t('Uygulama düzeni'),save:async values=>{
   error.textContent='';status.textContent='';
   try{const saved=await api('/account/workspace-design','PUT',{mode:values.mode,version:revision,palette:Object.fromEntries(Object.keys(colorFields).filter(key=>values['use_'+key]).map(key=>[key,values[key]]))});if(!valid())return true;revision=saved.workspaceDesign.version;applyWorkspaceDesign(saved.workspaceDesign);status.textContent=t(values.mode==='classic'?'Önceki düzen tüm uygulamalara ve kullanıcılara uygulandı.':'Dengeli düzen tüm uygulamalara ve kullanıcılara uygulandı.');return true;}
   catch(failure){if(valid())error.textContent=failure.message;return false;}
  }});
 }catch(failure){if(valid()){status.textContent='';error.textContent=failure.message;}}
}
