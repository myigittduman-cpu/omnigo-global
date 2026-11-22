import {createCustomerAccount} from './customer-account-form.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,now,openDialog,toast} from './ui.js';
import {manualPaymentForm} from './payment-form.js';
import {bindMoneyInputs} from './money-inputs.js';
import {parseMoneyAmount} from './money-value.js';
import {api} from './api.js';
const field=(label,name,value='',type='text',extra='')=>i18nHtml`<label>${i18nT(label)}<input name="${name}" type="${type}" value="${esc(value)}" ${extra} required></label>`;
const select=(label,name,values,value)=>i18nHtml`<label>${label}<select name="${name}">${values.map(([v,l])=>i18nHtml`<option value="${esc(v)}" ${value===v?'selected':''}>${esc(l)}</option>`).join('')}</select></label>`;
const area=(label,name,value='',max=5000)=>i18nHtml`<label class="full-width">${label}<textarea name="${name}" maxlength="${max}" rows="6" required>${esc(value)}</textarea></label>`;
const currency=(label,name,cents=0)=>field(label,name,cents/100,'text','inputmode="decimal" autocomplete="off"');
export function recordForm(kind,record,state,onSave){
 if(kind==='payments')return manualPaymentForm(record,state,onSave);
 const r=record||{};const channels=state.data.channels.map(c=>[c.name,c.name]);const channel=select(i18nT('Pazaryeri'),'channel',channels,r.channel);let fields='';
 if(kind==='sales')fields=channel+field(i18nT('Dönem'),'month',r.month||state.month,'month')+currency(i18nT('Brüt satış (₺)'),'grossCents',r.grossCents)+currency(i18nT('İptal tutarı (₺)'),'cancelCents',r.cancelCents)+currency(i18nT('İade tutarı (₺)'),'returnCents',r.returnCents)+field(i18nT('Sipariş sayısı'),'orders',r.orders||0,'number','min="0" step="1"')+field(i18nT('Bilgi kaynağı'),'source',r.source||'','text','maxlength="250"');
 if(kind==='tasks')fields=field(i18nT('Çalışma başlığı'),'title',r.title||'','text','maxlength="150"')+select(i18nT('Pazaryeri'),'channel',[['Tüm kanallar',i18nT('Tüm kanallar')],...channels],r.channel||'Tüm kanallar')+area(i18nT('Açıklama'),'description',r.description)+select(i18nT('Durum'),'status',[['planned',i18nT('Planlandı')],['running',i18nT('Devam ediyor')],['done',i18nT('Tamamlandı')]],r.status||'planned')+select(i18nT('Görünürlük'),'visibility',[['internal',i18nT('Yalnızca ekip')],['client',i18nT('Müşteri görebilir')]],r.visibility||'internal')+field('Hedef / tamamlanma tarihi','date',r.date||now(),'date')+field('Sorumlu','owner',r.owner||'Omnigo operasyon ekibi','text','maxlength="100"');
 if(kind==='reports')fields=field(i18nT('Rapor başlığı'),'title',r.title||'','text','maxlength="150"')+field(i18nT('Dönem'),'month',r.month||state.month,'month')+area(i18nT('Rapor içeriği'),'content',r.content,30000)+select(i18nT('Paylaşım'),'status',[['draft',i18nT('Taslak — yalnızca ekip')],['published',i18nT('Yayımla — müşteri görebilir')]],r.status||'draft');
 if(kind==='channels')fields=field(i18nT('Pazaryeri'),'name',r.name||'','text','maxlength="60" readonly')+field(i18nT('Mağaza adı'),'storeName',r.storeName||'','text',i18nT('maxlength="150" placeholder="Pazaryerindeki mağaza adınız"'))+select(i18nT('Mağaza durumu'),'status',[['active',i18nT('Aktif')],['inactive',i18nT('Pasif')]],r.status||'active')+i18nHtml('<p class="form-help full-width">Aktif bilgisi yönetici beyanıdır. API bağlantısı ayrı takip edilir. Kaydettiğiniz mağaza adı müşteri panelinde de görünür.</p>');
 const dialog=openDialog(kind==='channels'?i18nT('Mağaza bilgileri'):record?i18nT('Kaydı düzenle'):i18nT('Yeni kayıt'),i18nHtml`<form class="form-grid" id="recordForm">${fields}<p id="formError" class="form-error full-width" role="alert"></p><div class="dialog-actions"><button type="button" class="btn cancel">Vazgeç</button><button class="btn primary" type="submit">Kaydet</button></div></form>`);
 const form=dialog.querySelector('form');
 bindMoneyInputs(form);
 if(state.user.role==='client'){for(const [key,value] of [['visibility','client'],['status','published']]){const input=form.elements[key];if(input&&((kind==='tasks'&&key==='visibility')||(kind==='reports'&&key==='status'))){input.value=value;input.closest('label').hidden=true;}}}
 form.querySelector('.cancel').onclick=()=>dialog.close();
 form.onsubmit=async event=>{event.preventDefault();const button=form.querySelector('[type=submit]');button.disabled=true;try{const data=Object.fromEntries(new FormData(form));for(const key of Object.keys(data)){if(key.endsWith('Cents'))data[key]=parseMoneyAmount(data[key]);if(key==='orders')data[key]=Number(data[key]);}if(r.id)data.version=r.version;await api(`/records/${kind}${r.id?'/'+r.id:''}?company=${state.company}`,r.id?'PUT':'POST',data);dialog.close();await onSave();toast(i18nT('Kayıt kaydedildi.'));}catch(error){form.querySelector('#formError').textContent=error.message;}finally{button.disabled=false;}};
}
export function accountForm(state,onSave,options={}){return createCustomerAccount(state,onSave,options);}
