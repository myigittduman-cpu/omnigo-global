import {html} from './i18n.js';

export function cityProductSortField(){
 return html`<label class="city-products-filter">Sıralama
  <select data-products-sort aria-controls="city-products-panel">
   <option value="newest">Tarih (yeniden eskiye)</option>
   <option value="city">İl (A–Z)</option>
  </select></label>`;
}

export function bindCityProductFilters(root,{apply}){
 const cityPicker='[data-products-city-picker]',sortPicker='[data-products-sort]';
 root.addEventListener('change',event=>{
  if(event.target.matches(cityPicker))apply({city:event.target.value,page:0});
  if(event.target.matches(sortPicker))apply({sort:event.target.value,page:0});
 });
 return {showValues({city,sort}){
  const picker=root.querySelector(cityPicker);
  if(picker)picker.value=city;
  root.querySelector(sortPicker).value=sort;
 }};
}
