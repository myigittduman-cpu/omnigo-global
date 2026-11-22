import {html,t} from './i18n.js';
import {esc,icon} from './ui.js';

export function permissionUserPicker(users,catalog,companyName){
 return html`<div class="permission-user-picker" data-open="false"><button type="button" class="btn" data-application-toggle aria-expanded="false" ${users.length?'':'disabled'} title="${users.length?'Kullanıcıları seçin':'Bu mağazaya bağlı müşteri kullanıcısı yok.'}"><span>Yetkileri kullanıcılara uygula</span><small data-application-count>${users.length?'0 kullanıcı seçili':'Müşteri kullanıcısı yok'}</small>${icon('more')}</button><div class="permission-user-popover" hidden aria-label="Yetki uygulanacak kullanıcılar"><header><strong>${esc(companyName)}</strong><small>Yalnız bu mağazanın kullanıcıları</small></header><p class="form-help" data-application-source></p><label class="permission-application-scope"><span>Uygulanacak bölüm</span><select data-application-page><option value="all">Tüm işlem yetkileri</option>${Object.entries(catalog).map(([key,value])=>html`<option value="${esc(key)}">${esc(t(value.label))}</option>`).join('')}</select></label><label class="permission-application-all"><input type="checkbox" data-application-all ${users.length?'':'disabled'}><span>Tümünü seç</span></label><div class="permission-application-users">${users.map(user=>html`<label><input type="checkbox" data-application-user="${esc(user.id)}"><span><strong>${esc(user.name)}</strong><small>${esc(user.username||user.email||'')} · ${t(Number(user.active)?'Aktif':'Pasif')}</small></span></label>`).join('')||html('<p class="form-help">Bu mağazaya bağlı müşteri kullanıcısı yok.</p>')}</div><p class="permission-application-note">Seçilen kullanıcıların işlem yetkileri Kaydet ile uygulanır. Bölüm görünürlüğü ve teslimat şehirleri bu seçimle kopyalanmaz.</p></div></div>`;
}

export function bindPermissionUserPicker(form,users,onChange){
 const picker=form.querySelector('.permission-user-picker'),inputs=[...picker.querySelectorAll('[data-application-user]')],all=picker.querySelector('[data-application-all]');
 const ids=()=>inputs.filter(input=>input.checked).map(input=>input.dataset.applicationUser);
 const controller=new AbortController(),options={signal:controller.signal},summary=picker.querySelector('[data-application-toggle]'),panel=picker.querySelector('.permission-user-popover');
 const setOpen=value=>{const open=Boolean(value)&&!summary.disabled;panel.hidden=!open;picker.dataset.open=String(open);summary.setAttribute('aria-expanded',String(open));};
 summary.addEventListener('click',()=>{setOpen(panel.hidden);summary.focus({preventScroll:true});},options);
 document.addEventListener('pointerdown',event=>{if(!panel.hidden&&!picker.contains(event.target))setOpen(false);},options);
 document.addEventListener('focusin',event=>{if(!panel.hidden&&!picker.contains(event.target))setOpen(false);},options);
 form.closest('dialog')?.addEventListener('close',()=>controller.abort(),{once:true});
 const refresh=()=>{const count=ids().length;picker.querySelector('[data-application-count]').textContent=users.length?count+' '+t('kullanıcı seçili'):t('Müşteri kullanıcısı yok');all.checked=count>0&&count===inputs.length;all.indeterminate=count>0&&count<inputs.length;};
 picker.addEventListener('change',event=>{if(event.target===all)inputs.forEach(input=>input.checked=all.checked);refresh();onChange();});
 picker.addEventListener('keydown',event=>{if(event.key==='Escape'&&!panel.hidden){event.preventDefault();event.stopPropagation();setOpen(false);summary.focus({preventScroll:true});}});
 return {
  selection:()=>({selectedIds:ids(),page:picker.querySelector('[data-application-page]').value}),
  get dirty(){return ids().length>0;},
  setSource(id){const user=users.find(item=>String(item.id)===String(id));picker.querySelector('[data-application-source]').textContent=t('Kaynak yetkiler')+': '+(user?.name||t('Müşterinin genel yetkileri'));},
  reset(){inputs.forEach(input=>input.checked=false);setOpen(false);refresh();},
  setBusy(value){summary.disabled=Boolean(value)||!users.length;if(value)setOpen(false);},
 };
}
