import {parseMoneyAmount as paymentAmount} from './money-value.js';
export {paymentAmount};

export function revealPaymentField(input){
 for(let group=input.closest('details');group;group=group.parentElement?.closest('details'))group.open=true;
}

export function validatePaymentForm(form){
 const amounts=[...form.querySelectorAll('[data-line-amount],[data-line-rate],[name=expectedCents],[name=actualCents]')];
 for(const input of amounts){
  input.setCustomValidity('');if(input.disabled)continue;
  try{paymentAmount(input.value,{optional:!input.required,negative:input.hasAttribute('data-line-amount'),rate:input.hasAttribute('data-line-rate')});}
  catch(error){input.setCustomValidity(error.message);}
 }
 for(const line of form.querySelectorAll('[data-payment-line]'))line.querySelector('[data-line-label]').required=Boolean(line.querySelector('[data-line-amount]').value.trim()||line.querySelector('[data-line-rate]').value.trim());
 const start=form.elements.periodStart,end=form.elements.periodEnd;
 start.setCustomValidity('');end.setCustomValidity('');
 if(Boolean(start.value)!==Boolean(end.value)||(start.value&&start.value>end.value))end.setCustomValidity('Dönem başlangıcı ve bitişini birlikte, tarih sırasına göre girin.');
 const invalid=[...form.elements].find(input=>!input.disabled&&input.validity&&!input.validity.valid);
 if(!invalid)return '';
 const label=invalid.labels?.[0]?.textContent?.trim()||'Form alanı';
 revealPaymentField(invalid);
 invalid.focus();invalid.scrollIntoView({block:'center',behavior:'instant'});
 return `${label}: ${invalid.validity.valueMissing?'Bu alanı doldurun.':invalid.validationMessage}`;
}

export function revealSavedPayment(state,data){
 if(state.view!=='payments')return;
 const day=data.status==='paid'?data.paidDate:data.expectedDate;
 state.paymentDay='';
 if(state.dateRange&&day>=state.dateRange.from&&day<=state.dateRange.to)return;
 const month=day.slice(0,7),last=new Date(Date.UTC(Number(day.slice(0,4)),Number(day.slice(5,7)),0)).getUTCDate();
 state.month=month;
 state.dateRange={company:state.company,from:month+'-01',to:month+'-'+last,automatic:false};
}
