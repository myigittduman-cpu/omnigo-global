import {showFeedback} from './save-feedback.js';
import {fitMarketplacePages} from './marketplace-pages.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog,platform,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {openMarketEditor} from './market-editor.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {managementCompanySelector,bindManagementCompany} from './customer-management-selector.js';

import {marketplaceSettingsView,marketplaceCard} from './customer-marketplace-view.js';
import {ensureMarketplaceAccess,finishAccessCover} from './marketplace-access.js';

export {marketplaceSettingsView};
const selectionHint='Pazaryerleri ve API bilgileri yalnız seçtiğiniz müşteriye aittir. Değişiklikler Kaydet ve onaydan sonra uygulanır.';

export async function customerMarketplaces(state,onSave,companyId=null){
 if(state.user?.role!=='admin')return;
 const token=workspaceToken(state),inWorkspace=()=>sameWorkspace(state,token);
 try{
  if(companyId===null){
   const chooser=openDialog(i18nT('Pazaryeri ayarları'),managementCompanySelector(state.companies,'',selectionHint),{className:'customer-settings-dialog management-company-chooser'});
   bindManagementCompany(chooser,state.companies,null,{current:inWorkspace,onSelect:id=>customerMarketplaces(state,onSave,id)});
   return;
  }
  const company=state.companies.find(c=>Number(c.id)===Number(companyId));
  if(!company)throw new Error(i18nT('Mağaza seçin'));
  companyId=Number(company.id);
  if(!await ensureMarketplaceAccess(state,company)||!inWorkspace())return;
  const endpoint='/management/marketplaces?company='+companyId;
  let data=await api(endpoint),changed=false,busy=false,transition=false;
  let closeRefresh=Promise.resolve();
  if(!sameWorkspace(state,token))return;
  const dialog=openDialog(company.name+i18nT(' · Pazaryeri ayarları'),'',{className:'customer-settings-dialog marketplace-settings-dialog'}),body=dialog.querySelector('.dialog-body');
  const current=()=>dialog.open&&dialog.querySelector('.dialog-body')===body&&sameWorkspace(state,token);
  const syncSelection=()=>{const all=[...body.querySelectorAll('[data-marketplace-select]')],count=all.filter(el=>el.checked).length,master=body.querySelector('[data-marketplace-all]');if(!master)return;master.checked=all.length>0&&count===all.length;master.indeterminate=count>0&&count<all.length;body.querySelector('[data-bulk-summary]').textContent=i18nText`Seçilen mağaza: ${count}`;body.querySelector('.marketplace-bulk-save').disabled=busy||!count||!body.querySelector('#marketplaceBulk').elements.action.value;all.forEach(el=>el.onchange=syncSelection);};
  const paint=()=>{
   body.innerHTML=managementCompanySelector(state.companies,companyId,selectionHint)+marketplaceSettingsView(data.items);
   bindManagementCompany(dialog,state.companies,companyId,{current:()=>inWorkspace()&&!busy,onSelect:async id=>{transition=true;await closeRefresh;if(changed){await onSave();changed=false;}if(inWorkspace())await customerMarketplaces(state,onSave,id);}});
   const fit=fitMarketplacePages(body);
   const bindCard=card=>{
    const item=data.items.find(m=>m.provider===card.dataset.marketplace);
    const change=async(action,extra={})=>{
     if(busy||!current())return false;
     const actionChoice=card.querySelector('[name=action]');if(action==='edit'&&actionChoice?.value){showFeedback('Önce mağaza ayarı seçimini kaydedin veya İşlem seçin durumuna geri dönün.');return false;}
     const input=card.querySelector('[name=storeName]');if(action!=='edit'&&input&&input.value.trim()!==item.storeName){showFeedback('Önce mağaza adı değişikliğini kaydedin veya önceki adına dönün.');return false;}
     busy=true;card.querySelectorAll('button').forEach(b=>b.disabled=true);
     try{await api(`/management/marketplaces/${item.provider}?company=${companyId}`,'POST',{action,version:item.version,...extra});changed=true;data=await api(endpoint);if(current()){const template=document.createElement('template');template.innerHTML=marketplaceCard(data.items.find(m=>m.provider===item.provider));const next=template.content.querySelector('article');const checked=card.querySelector('[data-marketplace-select]')?.checked;card.replaceWith(next);bindCard(next);fit();if(next.querySelector('[data-marketplace-select]'))next.querySelector('[data-marketplace-select]').checked=Boolean(checked);syncSelection();}return true;}
     catch(error){if(current()){showFeedback(error.message,{kind:error.cancelled?'info':'error'});card.querySelectorAll('button').forEach(b=>b.disabled=false);}return false;}finally{busy=false;if(current())syncSelection();}
    };
    card.querySelectorAll('[data-marketplace-action]').forEach(b=>b.onclick=()=>change(b.dataset.marketplaceAction));
    const actionForm=card.querySelector('[data-marketplace-action-form]');if(actionForm)actionForm.onsubmit=async event=>{event.preventDefault();if(!actionForm.reportValidity())return false;return change(actionForm.elements.action.value);};
    const form=card.querySelector('[data-marketplace-name]');if(form)form.onsubmit=async event=>{event.preventDefault();if(!form.reportValidity())return false;return change('edit',{storeName:form.elements.storeName.value.trim()});};
    card.querySelector('[data-marketplace-configure]')?.addEventListener('click',async()=>{
     if(busy||!(await canLeaveDialogDrafts())||!current())return;
     await openMarketEditor({dialog,body,item,company,current,
      change:async action=>{
       if(action)await api(`/management/marketplaces/${item.provider}?company=${companyId}`,'POST',{action,version:item.version});
       changed=true;
      },
      refresh:async()=>{const fresh=await api(endpoint);if(current()){data=fresh;paint();}}
     });
    });
   };for(const card of body.querySelectorAll('[data-marketplace]'))bindCard(card);
   const bulk=body.querySelector('#marketplaceBulk');
   bulk.onsubmit=async event=>{
    event.preventDefault();if(busy||!current()||!bulk.reportValidity())return false;
    const selected=[...body.querySelectorAll('[data-marketplace-select]:checked')].map(el=>data.items.find(item=>item.provider===el.value));if(!selected.length)return false;
    
    if([...body.querySelectorAll('[data-marketplace]')].some(card=>{const item=data.items.find(x=>x.provider===card.dataset.marketplace);return (card.querySelector('[name=storeName]')?.value??item.storeName)!==item.storeName||Boolean(card.querySelector('[name=action]')?.value);})){showFeedback('Önce kartlardaki değişiklikleri kaydedin veya geri alın.');return false;}
    busy=true;body.querySelector('.marketplace-bulk-save').disabled=true;
    try{const result=await api('/management/marketplaces-bulk?company='+companyId,'POST',{action:bulk.elements.action.value,items:selected.map(({provider,version})=>({provider,version})),targetNames:selected.map(x=>x.name)});changed=true;if(current()){data=result;paint();toast(i18nT('Seçilen mağazaların görünürlüğü kaydedildi.'));}return true;}
    catch(e){if(current())showFeedback(e.message,{kind:e.cancelled?'info':'error'});return false;}finally{busy=false;if(current())syncSelection();}
   };
   body.querySelector('[data-marketplace-all]').onchange=event=>{body.querySelectorAll('[data-marketplace-select]').forEach(el=>el.checked=event.target.checked);syncSelection();};
   bulk.elements.action.addEventListener('change',syncSelection);
   body.querySelectorAll('[data-marketplace-select]').forEach(el=>el.onchange=syncSelection);
   syncSelection();
  };
  dialog.addEventListener('close',()=>{if(!transition&&changed&&inWorkspace())closeRefresh=Promise.resolve(onSave()).catch(error=>toast(error.message));},{once:true});paint();
 }catch(error){if(sameWorkspace(state,token))toast(error.message);}finally{finishAccessCover();}
}
