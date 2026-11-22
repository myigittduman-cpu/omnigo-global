import {turkeyCities} from './turkey-cities.js';
import {html,t,text} from './i18n.js';
import {esc} from './ui.js';

export function shippingScopeFields(scope={mode:'',regionalCities:[]},{detailed=false}={}){
 const selected=scope.regionalCities;
 return html`<fieldset class="shipping-scope full-width">
  <legend>Sevkiyat kapsamı</legend>
  <label>Satış ve sevkiyat şekli<select name="shippingMode" required>
   <option value="">Seçin</option>
   <option value="regional" ${scope.mode==='regional'?'selected':''}>Bölgesel Satış</option>
   <option value="nationwide" ${scope.mode==='nationwide'?'selected':''}>Tüm Türkiye</option>
  </select></label>
  <p class="form-help" data-shipping-hint></p>
  ${detailed?html`<dl class="shipping-model-summary" data-shipping-summary hidden>
   <div><dt>İl kapsamı</dt><dd data-shipping-coverage></dd></div>
   <div><dt>Şehir Dışı Teslimatlar</dt><dd data-shipping-presentation></dd></div>
  </dl>`:''}
  <details data-shipping-regional ${scope.mode==='regional'?'':'hidden'}>
   <summary>Bölgesel satış illeri · <span data-shipping-count>${selected.length}</span> il seçili</summary>
   ${detailed?html`<p class="form-help shipping-selected-cities" data-shipping-selected aria-live="polite"></p>`:''}
   <label>İl ara<input type="search" data-shipping-search placeholder="İl adı yazın" autocomplete="off"></label>
   <div class="shipping-city-choices">${turkeyCities.map(city=>html`<label data-shipping-city-row="${esc(city)}"><input type="checkbox" name="shippingCities" value="${esc(city)}" ${selected.includes(city)?'checked':''}><span>${esc(city)}</span></label>`).join('')}</div>
  </details>
 </fieldset>`;
}

export function bindShippingScope(form){
 const host=form.querySelector('.shipping-scope');
 const mode=host.querySelector('[name=shippingMode]');
 const cities=[...host.querySelectorAll('[name=shippingCities]')];
 const regional=host.querySelector('[data-shipping-regional]');
 const summary=host.querySelector('[data-shipping-summary]');
 const selectedCities=()=>cities.filter(input=>input.checked).map(input=>input.value);

 const refresh=()=>{
  const nationwide=mode.value==='nationwide',selected=selectedCities();
  regional.hidden=mode.value!=='regional';
  cities.forEach(input=>input.disabled=nationwide);
  host.querySelector('[data-shipping-count]').textContent=selected.length;
  host.querySelector('[data-shipping-hint]').textContent=t(nationwide
   ?'81 il kapsama alınır. Şehir Dışı Teslimatlar sayfasında tüm Türkiye özeti ve il filtresi kullanılır.'
   :'Bölgesel satışta seçtiğiniz iller sevkiyat filtrelerinde ve Şehir Dışı Teslimatlar sayfasında görünür. Bu seçim sonradan güncellenebilir.');
  if(!summary)return;
  summary.hidden=!mode.value;
  summary.querySelector('[data-shipping-coverage]').textContent=nationwide
   ?text`${turkeyCities.length} il`:text`${selected.length} seçili il`;
  summary.querySelector('[data-shipping-presentation]').textContent=t(nationwide
   ?'Türkiye geneli özet ve il filtresi':'Seçili illerin teslimat görünümü');
  host.querySelector('[data-shipping-selected]').textContent=selected.join(', ')||t('Henüz il seçilmedi.');
 };
 mode.addEventListener('change',refresh);
 cities.forEach(input=>input.addEventListener('change',refresh));
 host.querySelector('[data-shipping-search]').addEventListener('input',event=>{
  const query=event.target.value.toLocaleLowerCase('tr');
  host.querySelectorAll('[data-shipping-city-row]').forEach(row=>{
   row.hidden=!row.dataset.shippingCityRow.toLocaleLowerCase('tr').includes(query);
  });
 });
 refresh();
 return {value(){
  if(!mode.value)throw new Error(t('Sevkiyat kapsamını seçin.'));
  const regionalCities=selectedCities();
  if(mode.value==='regional'&&!regionalCities.length)throw new Error(t('Bölgesel satış için en az bir il seçin.'));
  return {mode:mode.value,regionalCities};
 }};
}
