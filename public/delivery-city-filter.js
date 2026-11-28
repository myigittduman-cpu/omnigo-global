import {turkeyCities,defaultCities} from './turkey-cities.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';

export const deliveryCities=Object.freeze([...defaultCities,...turkeyCities.filter(city=>!defaultCities.includes(city))]);
export const availableDeliveryCities=model=>model.availableCities??defaultCities;
export const selectedDeliveryCities=model=>model.cities??(model.city==='all'?[...availableDeliveryCities(model)]:availableDeliveryCities(model).filter(city=>city===model.city));
export const deliveryCityLabel=model=>model.cities?.length?model.cities.length===availableDeliveryCities(model).length?i18nT('Tüm Şehirler'):model.cities.length>3?i18nText`${model.cities.length} şehir seçili`:model.cities.join(', '):model.city==='all'?i18nT('Tüm Şehirler'):i18nT(model.city);
export const deliveryCityQuery=model=>(model.cities||[]).map(city=>'&cities[]='+encodeURIComponent(city)).join('');
const searchText=value=>String(value).toLocaleLowerCase('tr-TR').normalize('NFD').replace(/\p{M}/gu,'').replaceAll('ı','i');
export const deliveryCityMatches=(city,query)=>searchText(city).includes(searchText(query).trim());
export function filterDeliveryCityOptions(form,query){
 let count=0;for(const label of form.querySelectorAll('[data-city-option]')){label.hidden=!deliveryCityMatches(label.querySelector('input').value,query);if(!label.hidden)count++;}
 const empty=form.querySelector('[data-city-search-empty]');if(empty)empty.hidden=count>0;return count;
}
export function applyDeliveryCities(model,cities){
 const available=availableDeliveryCities(model);
 const selected=available.filter(city=>cities.includes(city));
 if(!selected.length)throw new Error(i18nT('En az bir şehir seçin.'));
 const nationwideAll=model.shippingMode==='nationwide'&&selected.length===available.length;
 Object.assign(model,{cities:nationwideAll?null:selected,city:!nationwideAll&&selected.length===1?selected[0]:'all',
  ...(nationwideAll?{queue:'all'}:{}),page:0,data:null,selected:new Set(),manualSelection:false,message:'',cityDraft:null,cityFilterOpen:false,citySearch:''});
}
export function deliveryCityFilter(model){
 if(model.showCityFilter===false)return '';
 const draft=model.cityDraft??selectedDeliveryCities(model),national=model.shippingMode==='nationwide',search=model.citySearch||'';
 const partial=model.cities?.length>1&&(!national||model.cities.length!==availableDeliveryCities(model).length);
 const label=partial?i18nText`${model.cities.length} şehir seçili`:deliveryCityLabel(model);
 return i18nHtml`<div class="delivery-city-filter"><span class="delivery-filter-label">Şehirler</span>
 <details data-city-filter ${model.cityFilterOpen?'open':''}><summary aria-label="Teslimat şehirlerini seç">${esc(label)}</summary>
 <form data-city-filter-form class="delivery-city-options">${national?i18nHtml`<label class="delivery-city-search"><span>Şehir ara</span><input type="search" data-city-search value="${esc(search)}" placeholder="Şehir adı yazın" autocomplete="off"></label>`:''}<fieldset><legend>Birden fazla şehir seçebilirsiniz.</legend>
 ${availableDeliveryCities(model).map(city=>i18nHtml`<label data-city-option ${national&&!deliveryCityMatches(city,search)?'hidden':''}><input type="checkbox" name="cities" value="${esc(city)}" ${draft.includes(city)?'checked':''}><span>${esc(city)}</span></label>`).join('')}
 ${national?i18nHtml`<p data-city-search-empty ${availableDeliveryCities(model).some(city=>deliveryCityMatches(city,search))?'hidden':''}>Eşleşen şehir yok.</p>`:''}
 </fieldset><p class="delivery-city-error" role="alert">${model.cityFilterError?esc(i18nT('En az bir şehir seçin.')):''}</p>
 <div class="delivery-city-actions"><button type="button" class="btn" data-city-select-all>Tümünü seç</button>${national?i18nHtml`<button type="button" class="btn" data-city-clear>Seçimi temizle</button>`:''}<button type="button" class="btn" data-city-cancel>Vazgeç</button><button type="submit" class="btn primary">Uygula</button></div></form></details></div>`;
}
