import {permissionTargets} from './customer-permission-targets.js';
import {addDialogBack,closeDialogThen} from './dialog-return.js';
import {api} from './api.js';
import {html,t} from './i18n.js';
import {openDialog,esc,toast} from './ui.js';

export async function customerPermissionBatch(configuration,catalog,onSaved,{companyId=null,onlyCompany=false,sourceName='',onBack=null,current=()=>true}={}){
 try{
  const result=await api('/management/permissions-bulk'),items=onlyCompany?result.items.filter(item=>Number(item.id)===Number(companyId)):result.items;if(!items.length||!current())return;
  let index=Math.max(0,items.findIndex(item=>Number(item.id)===Number(companyId))),busy=false;const selected=new Map();
  const dialog=openDialog(t('Tasarım uygulanacak kullanıcılar'),html`<form class="form-grid"><p class="form-help full-width">Kaynak: <strong>${esc(sourceName)}</strong>. Önceki ekranda kaydedilen yetkileri aşağıda seçeceğiniz hesaplara kopyalayın. Kaydet ve onaydan önce hiçbir hesap değişmez.</p><label>1. Uygulanacak bölüm<select name="page"><option value="all">Tüm bölümlerin işlem yetkileri</option>${Object.entries(catalog).map(([key,value])=>html`<option value="${key}">${esc(t(value.label))}</option>`).join('')}</select><small>Tek bölüm veya tüm işlem yetkilerini seçin.</small></label><label>2. Müşteri<select name="company">${items.map((item,i)=>html`<option value="${i}">${esc(item.name)}</option>`).join('')}</select><small>Bu müşteriye bağlı kullanıcılar aşağıda listelenir.</small></label><div class="full-width" data-batch-accounts></div><small class="full-width">Yalnız seçilen kullanıcıların işlem yetkileri değişir. Mağaza bağlantıları, sayfa görünürlüğü ve şehirler değişmez. Yeni kullanıcıların standart yetkileri korunur.</small><p class="full-width" data-batch-summary role="status"></p><textarea name="batchDraft" hidden></textarea><p class="form-error full-width" role="alert"></p><button class="btn primary" type="submit">Kaydet</button></form>`,{className:'customer-permissions-dialog permission-batch-dialog'});
  if(onBack)addDialogBack(dialog,onBack,'Yetki düzenlemeye dön');
  const form=dialog.querySelector('form'),accountRoot=form.querySelector('[data-batch-accounts]');
  const target=item=>selected.get(item.id)||{companyId:Number(item.id),version:Number(item.version),defaults:false,users:[]};
  function paint(){const item=items[index],value=target(item);
   const expanded=accountRoot.querySelector('details')?.open||false;
   accountRoot.innerHTML=`<details class="permission-user-picker"${expanded?' open':''}><summary>${esc(t('Kullanıcı hesaplarını seçin'))} · ${value.users.length} / ${item.users.length}</summary>${permissionTargets(item,value)}</details>`;
   form.querySelector('[type=submit]').disabled=busy||!selected.size;
   form.elements.batchDraft.value=JSON.stringify([...selected.values()]);form.querySelector('[data-batch-summary]').textContent=t('Seçilen müşteriler')+': '+selected.size+' · '+t('Kullanıcılar')+': '+[...selected.values()].reduce((sum,x)=>sum+x.users.length,0);
  }
  form.addEventListener('change',event=>{if(busy)return;const el=event.target,item=items[index];let value=target(item);
   if(el.name==='company'){index=Number(el.value);paint();return;}
   if(el.name==='companyAll'){value={...value,users:el.checked?item.users.map(u=>Number(u.id)):[]};selected.set(item.id,value);}
   else if(el.dataset.batchUser){const id=Number(el.dataset.batchUser);value.users=el.checked?[...new Set([...value.users,id])]:value.users.filter(x=>x!==id);selected.set(item.id,value);}
   for(const [id,value] of selected)if(!value.defaults&&!value.users.length)selected.delete(id);paint();
  });
  form.onsubmit=async event=>{event.preventDefault();if(busy||!current())return false;const error=form.querySelector('.form-error');error.textContent='';
   if(!selected.size){error.textContent=t('Uygulanacak hesapları seçin.');return false;}
   busy=true;form.querySelectorAll('button,input,select').forEach(el=>el.disabled=true);
   try{await api('/management/permissions-bulk','POST',{configuration,selectedPages:form.elements.page.value==='all'?Object.keys(catalog):[form.elements.page.value],targets:[...selected.values()],includeSettings:false,targetNames:items.filter(item=>selected.has(item.id)).map(item=>item.name)});form.reset();selected.clear();toast(t('Seçilen hesapların ayarları kaydedildi.'));await onSaved?.();dialog.dispatchEvent(new Event('omnigo-dialog-navigation'));await new Promise(resolve=>{dialog.addEventListener('close',resolve,{once:true});dialog.close();});if(current())await onBack?.();return true;}
   catch(e){error.textContent=e.message;return false;}finally{busy=false;if(form.isConnected&&dialog.contains(form)){form.querySelectorAll('button,input,select').forEach(el=>el.disabled=false);paint();}}
  };form.elements.company.value=String(index);paint();
 }catch(error){toast(error.message);}
}
