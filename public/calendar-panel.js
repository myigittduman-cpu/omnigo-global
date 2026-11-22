import {t as i18nT,text as i18nText,html as i18nHtml,currentLocale} from './i18n.js';
import {esc} from './ui.js';
import {calendarMonths,calendarToday,calendarDays,shiftMonth,shiftCalendarDay,validCalendarMonth,validCalendarDate,calendarValueAllowed} from './calendar-date.js';

export function showCalendar({anchor,label=i18nT('Tarih seçin'),value='',mode='date',min='',max='',required=false,step='',onApply}){
 document.querySelector('dialog.portal-calendar')?.close();
 const today=calendarToday(),initial=validCalendarDate(value.slice(0,10))?value.slice(0,10):today;
 let selected=mode==='month'&&validCalendarMonth(value)?value:initial,viewed=selected.slice(0,7),months=mode==='month';
 let time=/T\d{2}:\d{2}/.test(value)?value.split('T')[1]:'09:00',focused=initial;
 const seconds=mode==='datetime-local'&&step!==''&&Number(step)>0&&Number(step)<60;
 const dialog=document.createElement('dialog');dialog.className='portal-calendar';dialog.setAttribute('aria-label',label);document.body.append(dialog);
 const candidate=()=>mode==='datetime-local'?selected+'T'+time:mode==='month'?selected.slice(0,7):selected;
 const dayAllowed=date=>validCalendarDate(date)&&(!min||date>=min.slice(0,10))&&(!max||date<=max.slice(0,10));
 const monthAllowed=month=>validCalendarMonth(month)&&(!min||month>=min.slice(0,7))&&(!max||month<=max.slice(0,7));
 const apply=next=>{if(!calendarValueAllowed(next,{mode,min,max,required}))return;dialog.close();onApply(next);};
 const dayName=date=>new Intl.DateTimeFormat(currentLocale(),{day:'numeric',month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(date+'T12:00:00Z'));
 function updateApply(){dialog.querySelector('[data-date-apply]').disabled=!calendarValueAllowed(candidate(),{mode,min,max,required});}
 function render(){
  const [year,month]=viewed.split('-').map(Number),previous=shiftMonth(viewed,months?-12:-1),next=shiftMonth(viewed,months?12:1);
  dialog.innerHTML=i18nHtml`<header><div><small>${esc(label)}</small><strong>${mode==='month'?calendarMonths[Number(selected.slice(5,7))-1]+' '+selected.slice(0,4):esc(dayName(selected))}</strong></div><button type="button" data-date-close aria-label="Takvimi kapat">×</button></header>
   <div class="portal-calendar-navigation"><button type="button" data-date-step="-1" aria-label="${months?i18nT('Önceki yıl'):i18nT('Önceki ay')}" ${validCalendarMonth(previous)?'':'disabled'}>‹</button><div><select data-date-month aria-label="Ay">${calendarMonths.map((name,index)=>i18nHtml`<option value="${index+1}" ${month===index+1?'selected':''}>${name}</option>`).join('')}</select><input type="number" data-date-year aria-label="Yıl" min="1900" max="2100" value="${year}"></div><button type="button" data-date-step="1" aria-label="${months?i18nT('Sonraki yıl'):i18nT('Sonraki ay')}" ${validCalendarMonth(next)?'':'disabled'}>›</button></div>
   <div class="portal-calendar-selection">${mode==='month'?'':i18nHtml`<button class="portal-calendar-mode" type="button" data-date-mode>${months?i18nT('Gün seçimine dön'):i18nT('Ayları göster')}</button>`}
   ${months?i18nHtml`<div class="portal-calendar-months">${calendarMonths.map((name,index)=>{const monthValue=year+'-'+String(index+1).padStart(2,'0');return i18nHtml`<button type="button" data-date-choice-month="${monthValue}" class="${selected.slice(0,7)===monthValue?'selected':''}" ${monthAllowed(monthValue)?'':'disabled'}>${name}</button>`;}).join('')}</div>`:i18nHtml`<div class="portal-calendar-weekdays" aria-hidden="true">${['Pt','Sa','Ça','Pe','Cu','Ct','Pa'].map(day=>i18nHtml`<span>${i18nT(day)}</span>`).join('')}</div><div class="portal-calendar-days" role="group" aria-label="Günler">${calendarDays(viewed).map(day=>i18nHtml`<button type="button" data-date-day="${day.date}" aria-label="${esc(dayName(day.date))}" aria-pressed="${day.date===selected}" ${day.date===today?'aria-current="date"':''} class="${day.current?'':'outside'} ${day.date===selected?'selected':''} ${day.date===today?'today':''}" ${dayAllowed(day.date)?'':'disabled'}>${day.day}</button>`).join('')}</div>`}
   </div>${mode==='datetime-local'?i18nHtml`<div class="portal-calendar-time"><span>Saat</span>${[i18nT('Saat'),'Dakika',...(seconds?['Saniye']:[])].map((name,index)=>i18nHtml`<label><span>${i18nT(name)}</span><input type="number" data-date-time="${index}" aria-label="${i18nT(name)}" min="0" max="${index?59:23}" value="${Number(time.split(':')[index]||0)}"></label>`).join('')}</div>`:''}
   <footer><div><button type="button" data-date-today>${mode==='month'?i18nT('Bu ay'):i18nT('Bugün')}</button>${required?'':i18nHtml('<button type="button" data-date-clear>Temizle</button>')}</div><button type="button" class="portal-calendar-apply" data-date-apply>${mode==='month'?i18nT('Dönemi uygula'):i18nT('Tarihi uygula')}</button></footer>`;
  updateApply();
 }
 dialog.addEventListener('click',event=>{
  const button=event.target.closest('button');if(!button||button.disabled)return;
  if(button.hasAttribute('data-date-close'))dialog.close();
  else if(button.hasAttribute('data-date-clear'))apply('');
  else if(button.hasAttribute('data-date-apply'))apply(candidate());
  else if(button.hasAttribute('data-date-today')){selected=mode==='month'?today.slice(0,7):today;viewed=today.slice(0,7);render();}
  else if(button.hasAttribute('data-date-mode')){months=!months;render();}
  else if(button.dataset.dateStep){viewed=shiftMonth(viewed,Number(button.dataset.dateStep)*(months?12:1));render();dialog.querySelector(`[data-date-step="${button.dataset.dateStep}"]`).focus();}
  else if(button.dataset.dateChoiceMonth){viewed=button.dataset.dateChoiceMonth;if(mode==='month')selected=viewed;else months=false;render();}
  else if(button.dataset.dateDay){selected=button.dataset.dateDay;focused=selected;viewed=selected.slice(0,7);render();dialog.querySelector(`[data-date-day="${selected}"]`)?.focus();}
 });
 dialog.addEventListener('change',event=>{
  if(event.target.matches('[data-date-month],[data-date-year]')){const next=dialog.querySelector('[data-date-year]').value+'-'+String(dialog.querySelector('[data-date-month]').value).padStart(2,'0');if(validCalendarMonth(next)){viewed=next;render();}}
 });
 dialog.addEventListener('input',event=>{
  if(!event.target.matches('[data-date-time]'))return;
  const fields=[...dialog.querySelectorAll('[data-date-time]')];time=fields.every(field=>field.value!==''&&field.validity.valid)?fields.map(field=>String(Number(field.value)).padStart(2,'0')).join(':'):'';updateApply();
 });
 dialog.addEventListener('keydown',event=>{
  if(event.key==='Enter'&&event.target.matches('[data-date-year],[data-date-time]')){event.preventDefault();return;}
  if(!event.target.matches('[data-date-day]'))return;
  const offsets={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7};if(!(event.key in offsets))return;
  event.preventDefault();const next=shiftCalendarDay(event.target.dataset.dateDay,offsets[event.key]);if(!dayAllowed(next))return;focused=next;viewed=next.slice(0,7);render();dialog.querySelector(`[data-date-day="${focused}"]`)?.focus();
 });
 dialog.addEventListener('close',()=>{dialog.remove();if(anchor.isConnected)anchor.focus({preventScroll:true});},{once:true});
 render();dialog.showModal();
 const active=dialog.querySelector('[aria-pressed="true"]:not(:disabled),.portal-calendar-months .selected:not(:disabled)');(active||dialog.querySelector('[data-date-month]')).focus({preventScroll:true});
 return dialog;
}
