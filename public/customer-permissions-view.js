import {workspaceMenuLabelMarkup,workspaceMenuLabelText} from './workspace-menu-labels.js';
import {t,html} from './i18n.js';
import {esc} from './ui.js';
import {inventoryModeSetting} from './inventory-mode-setting.js';
import {inventoryPermissionAvailable} from './inventory-permission-model.js';
import {permissionDefaults} from './permission-user-application.js';
import {sectionAudiences,sectionAudienceSelection,sectionAllowsRole} from './customer-section-audience.js';
export const permissionPageEntries=catalog=>Object.entries(catalog).filter(([,definition])=>!definition.settingsOnly&&!definition.adminOnly&&!definition.legacyOnly);
const permissionHelp={viewCancellations:'Şehir Dışı Sevkiyat hariç sipariş, teslimat, satış ve analiz alanlarında iptal kayıtlarını ve tutarlarını görür.',viewReturns:'Şehir Dışı Sevkiyat hariç iade taleplerini, iade tutarlarını ve satış ayrıntılarını görür.',viewPurchasePrice:'Ürünlerin alış fiyatını görür.',editPurchasePrice:'Alış fiyatını değiştirir; müşteri değişikliğinde Omnigo’ya bildirim gider.',viewMovements:'Stok işlem geçmişini ve işlemi yapan kullanıcıyı görür.',viewSales:'Pazaryeri ve mağaza satış dağılımını görür.',viewMarketplaceStocks:'Her pazaryerinin bildirdiği stok adedi ve satış durumunu görür.',editStock:'Seçili stok modelinde manuel sayım ve stok düzeltmesi yapar.',recordStoreSale:'Mağaza satışı veya satış iadesi kaydeder; ilgili stok değişir.',selectRows:'Tablodaki birden fazla satırı birlikte seçer.',bulkStatus:'Seçilen satırların durumunu tek onayla değiştirir. Toplu seçim izni de gerekir.',view:'Bu bölümdeki kayıtları görür.',create:'Yeni kayıt ekler.',edit:'Mevcut kaydı düzenler.',delete:'Kaydı siler.',status:'Kaydın durumunu değiştirir.',invoice:'Fatura durumunu işaretler.',selectDocuments:'Yazdırılacak sipariş ve evrakları seçer.',documents:'Yüklenen belgeyi açar ve cihazına indirir.',upload:'Boş belge alanına fatura veya teslimat evrakı ekler.',replaceDocuments:'Mevcut belgenin yerine yeni dosya yükler; yükleme izni de gerekir.',deleteDocuments:'Yüklenmiş belgeyi siler; diğer belge izinlerinden ayrıdır.',export:'PDF / Excel oluşturur, indirir ve yazdırır.',reply:'Destek talebine yanıt yazar.'};
Object.assign(permissionHelp,{
 viewCancelledStatus:'Listede kalan iptal edilmiş siparişi kırmızı gösterir. İptal tutarı ve iptal adedi için ayrı yetki gerekir.',
 viewCancelledRecords:'Yalnızca bu tablodaki iptal kayıtlarını ve iptal tutarlarını gösterir.',
 viewReturnedRecords:'Yalnızca bu tablodaki iade kayıtlarını ve iade tutarlarını gösterir.',
 viewAfterCancellationTurnover:'Yalnızca bu tablodaki iptal sonrası ciroyu gösterir.',
});

export function permissionSections(catalog,configuration,{includeInventoryMode=true,state}={}){
 const sections=permissionPageEntries(catalog);
 const fixed=Object.entries(catalog).filter(([,definition])=>definition.adminOnly&&definition.fixedAudience);
 return sections.map(([page,definition])=>{
  const stored=configuration.pages[page],selected=sectionAudienceSelection(page,stored);
  const options=Object.entries(sectionAudiences).filter(([value])=>sectionAudienceSelection(page,value)===value).map(([value,[label]])=>{
   const optionValue=value==='hidden'&&stored==='removed'?'removed':value;
   return html`<option value="${optionValue}" ${value===selected?'selected':''}>${esc(t(label))}</option>`;
  }).join('');
  return html`<label class="permission-section"><span>${state?workspaceMenuLabelMarkup(state,page,definition.label):esc(t(definition.label))}</span><select data-section="${page}" aria-label="${esc(state?workspaceMenuLabelText(state,page,definition.label):t(definition.label))}">${options}</select></label>`;
 }).join('')+fixed.map(([page,{label,audienceLabel='Yalnız Omnigo'}])=>html`<label class="permission-section"><span>${state?workspaceMenuLabelMarkup(state,page,label):esc(t(label))}</span><select disabled aria-label="${esc(state?workspaceMenuLabelText(state,page,label):t(label))}"><option>${esc(t(audienceLabel))}</option></select></label>`).join('')+
  (catalog.inventory&&includeInventoryMode?inventoryModeSetting(configuration.inventory?.mode||'marketplace',configuration.inventoryManagement,configuration.inventory):'');
}
export function permissionMatrix(catalog,actions,configuration,userId,{expanded=new Set(),state}={}){
 const storeRole=configuration.roles?.[userId]||'store_manager';
 const grants=configuration.users[userId]||permissionDefaults(configuration,userId);
 const sections=permissionPageEntries(catalog).filter(([page])=>sectionAllowsRole(page,'all_roles',storeRole));
 return html`<div class="permission-grid">${sections.map(([page,definition])=>{
  const available=sectionAllowsRole(page,configuration.pages[page],storeRole);
  const visible=available&&grants[page]?.view;
  const options=definition.actions.filter(action=>action!=='view'&&
    (!['orders','shipments'].includes(page)||!['upload','replaceDocuments','deleteDocuments'].includes(action))&&
    (page!=='orders'||action!=='documents'));
  return html`<fieldset class="permission-card" ${available?'':'disabled'}><legend>${state?workspaceMenuLabelMarkup(state,page,definition.label):esc(t(definition.label))}</legend>
  <label class="permission-view-choice"><span>Görüntüleme</span><select data-page="${page}" data-permission="view" aria-label="${esc(state?workspaceMenuLabelText(state,page,definition.label):t(definition.label))} · ${t('Görüntüleme')}">
   <option value="shown" ${visible?'selected':''}>Görüntüleyebilir</option>
   <option value="hidden" ${visible?'':'selected'}>Gizli</option>
  </select></label>
  ${['overviewSales','overviewTop','overviewWork'].includes(page)?html`<small class="permission-help">${state?workspaceMenuLabelMarkup(state,'overview',catalog.overview?.label||'Mağaza Özeti'):t(catalog.overview?.label||'Mağaza Özeti')} içindeki bölüm.</small>`:''}
  ${!available?html('<small>Bölüm bu role açıldığında uygulanır.</small>'):''}
  ${options.length&&visible?html`<details class="permission-actions" data-permission-actions="${page}" ${expanded.has(page)?'open':''}><summary>İşlem izinleri</summary><div>${options.map(action=>{
   const compatible=page!=='inventory'||inventoryPermissionAvailable(configuration.inventory?.mode||'marketplace',action);
   return html`<label><input type="checkbox" data-page="${page}" data-permission="${action}" ${compatible&&grants[page]?.[action]?'checked':''} ${!compatible?'disabled':''}><span>${esc(t(actions[action]))}<small class="permission-help">${esc(t(compatible?(permissionHelp[action]||''):'Seçili stok modelinde kullanılmaz.'))}</small></span></label>`;
  }).join('')}</div></details>`:''}</fieldset>`;
 }).join('')}</div>`;
}
