import {html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
import {enhanceDateInputs} from './date-inputs.js';
export {shiftMonth,calendarDays} from './calendar-date.js';
export function periodPicker(value,hidden=false){
 if(hidden)return '';
 return i18nHtml('<label class="period-picker"><span class="period-label">Dönem</span><input type="month" id="periodSelect" aria-label="Rapor dönemi" value="'+esc(value)+'" min="2000-01" max="2100-12" required></label>');
}
export function bindPeriodPicker(root,value,onChange){
 const input=root.querySelector('#periodSelect');if(!input)return;
 enhanceDateInputs(root);
 input.onchange=()=>{if(input.checkValidity()&&input.value!==value)onChange(input.value);};
}
