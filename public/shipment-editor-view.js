import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
export const editorFields=[
 ['orderedAt','Sipariş tarihi ve saati','datetime-local',true],['customerName','Müşteri adı','text',true],['recipientName','Alıcı adı','text'],['phone','Tel no','tel'],
 ['province','İl','city',true],['district','İlçe','text'],['deliveryAddress','Teslimat adresi','textarea',true],['productName','Ürün adı','textarea',true],
 ['invoiceAmount','Fatura tutarı (TL)','text'],['quantity','Adet','number',true],['invoiceName','Fatura adı','text'],['invoiceAddress','Fatura adresi / V.D.','textarea'],
 ['recipientEmail','Alıcı mail','email'],['customerType','Müşteri tipi','type'],['taxNumber','Vergi numarası','text']
];
const limits={customerName:240,recipientName:240,phone:60,district:150,deliveryAddress:8000,productName:1000,invoiceAmount:20,invoiceName:500,invoiceAddress:8000,recipientEmail:254,taxNumber:20};
export function shipmentEditorRow(editor,month,cities,columnCount=15){
 if(!editor)return '';
 const fields=editorFields.map(([key,label,type,required])=>{
  const value=editor.values[key]??'',id='shipment-edit-'+key,attrs=`id="${id}" name="${key}" ${required?'required':''}`;
  let input;
  if(type==='textarea')input=i18nHtml`<textarea ${attrs} rows="2" maxlength="${limits[key]}">${esc(value)}</textarea>`;
  else if(type==='city'||type==='type')input=i18nHtml`<select ${attrs}><option value="">Seçin</option>${(type==='city'?cities:['Bireysel','Kurumsal']).map(option=>i18nHtml`<option value="${esc(option)}" ${value===option?'selected':''}>${type==='type'?i18nT(option):option}</option>`).join('')}</select>`;
  else input=i18nHtml`<input ${attrs} type="${type}" value="${esc(value)}" ${limits[key]?`maxlength="${limits[key]}"`:''} ${type==='number'?'min="1" max="99999" step="1"':''} ${type==='datetime-local'?`step="1" min="${month}-01T00:00:00" max="${month}-${new Date(Number(month.slice(0,4)),Number(month.slice(5)),0).getDate()}T23:59:59"`:''} ${key==='invoiceAmount'?'inputmode="decimal" placeholder="25890,50"':key==='taxNumber'?'inputmode="numeric"':''}>`;
  return i18nHtml`<label class="shipment-edit-field ${type==='textarea'?'shipment-edit-wide':''}" for="${id}"><span>${i18nT(label)}${required?' *':''}</span>${input}</label>`;
 }).join('');
 return i18nHtml`<tr class="shipment-editor-row"><td colspan="${Number(columnCount)}"><form data-shipment-editor aria-label="${editor.id?i18nT('Sevkiyat kaydını düzenle'):i18nT('Yeni sevkiyat kaydı')}"><div class="shipment-editor-title"><strong>${editor.id?i18nT('Kaydı düzenle'):i18nT('Yeni satır ekle')}</strong><span>* Zorunlu alanlar</span></div><fieldset ${editor.saving?'disabled':''}><div class="shipment-editor-grid">${fields}</div><div class="shipment-editor-actions"><button type="submit" class="btn primary">${editor.saving?i18nT('Kaydediliyor…'):i18nT('Değişikliği ekle')}</button><button type="button" class="btn" data-shipment-edit-cancel>Vazgeç</button><p role="status" data-shipment-editor-message>${esc(editor.message||'')}</p></div></fieldset></form></td></tr>`;
}
export function shipmentEditorActions(model){
 const disabled=model.busy||model.editor||model.writing||model.exporting;
 return i18nHtml`<div class="shipment-edit-actions">${model.addWarning?i18nHtml`<p class="shipment-add-warning" role="alert">${esc(model.addWarning)}</p>`:''}<div class="shipment-session-buttons">${model.editMode?i18nHtml`<button type="button" class="btn primary" data-shipment-session-save ${disabled?'disabled':''}>Kaydet${model.draftChanges?.length?' ('+model.draftChanges.length+')':''}</button><button type="button" class="btn" data-shipment-session-cancel ${model.writing?'disabled':''}>Düzenlemeyi bitir</button>`:i18nHtml`<button type="button" class="btn" data-shipment-edit-mode ${disabled?'disabled':''}>Düzenle</button>`}${model.canCreate?i18nHtml`<button type="button" class="btn" data-shipment-add ${disabled||(!model.editMode&&model.provider!=='all')?'disabled':''}>+ Yeni satır ekle</button>`:''}</div>${model.draftLog?.length?i18nHtml`<details class="shipment-session-summary"><summary>${model.draftLog.length} işlem kaydedilmeyi bekliyor</summary><ol>${model.draftLog.map(label=>i18nHtml`<li>${esc(label)}</li>`).join('')}</ol></details>`:''}</div>`;
}
