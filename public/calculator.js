import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {calculateExpression} from './calculator-model.js';
let panel=null,outsideClick=null;
// Keep the shared header contract for both portals; the calculator entry is retired.
export const calculatorButton=()=>'';
export function closeCalculator(){if(outsideClick)document.removeEventListener('pointerdown',outsideClick);outsideClick=null;panel?.remove();panel=null;document.querySelector('[data-calculator-open]')?.setAttribute('aria-expanded','false');}
export function bindCalculator(root){
 const toggle=root.querySelector('[data-calculator-open]');if(!toggle)return;toggle.setAttribute('aria-expanded',String(Boolean(panel)));
 toggle.onclick=()=>{
  if(panel){closeCalculator();return;}
  panel=document.createElement('section');panel.id='quickCalculator';panel.className='quick-calculator';panel.setAttribute('role','dialog');panel.setAttribute('aria-label',i18nT('Hesap makinesi'));
  panel.innerHTML=i18nHtml('<header><span>Hesap makinesi</span><button type="button" data-calc-close aria-label="Hesap makinesini kapat">×</button></header><label><span class="calc-input-label">İşlem</span><input data-calc-input inputmode="decimal" autocomplete="off" maxlength="120" placeholder="0" aria-label="Hesaplanacak işlem"></label><p data-calc-feedback role="status">Yalnızca hesaplama yapar; kayıtları değiştirmez.</p><div class="calc-keys">')+['AC','(',')','⌫','7','8','9','÷','4','5','6','×','1','2','3','−','0',',','%','+'].map(key=>i18nHtml`<button type="button" data-calc-key="${key}"${key==='%'?i18nT(' title="Yüzde: sayıyı 100’e böler"'):''}>${key}</button>`).join('')+i18nHtml('<button type="button" class="calc-equals" data-calc-key="=">=</button></div>');
  document.body.append(panel);outsideClick=event=>{if(panel&&!panel.contains(event.target)&&!toggle.contains(event.target))closeCalculator();};document.addEventListener('pointerdown',outsideClick);toggle.setAttribute('aria-expanded','true');const input=panel.querySelector('input'),feedback=panel.querySelector('[role=status]');
  const close=()=>{closeCalculator();document.querySelector('[data-calculator-open]')?.focus({preventScroll:true});};
  panel.querySelector('[data-calc-close]').onclick=close;
  function apply(key){
   if(key==='AC'){input.value='';feedback.textContent=i18nT('Yeni işlem');}
   else if(key==='⌫')input.value=input.value.slice(0,-1);
   else if(key==='='){try{const expression=input.value;input.value=String(calculateExpression(expression)).replace('.',',');feedback.textContent=expression+' = '+input.value;}catch(error){feedback.textContent=error.message;}}
   else input.value=(input.value+key.replace('−','-')).slice(0,120);
   input.focus({preventScroll:true});
  }
  panel.addEventListener('click',event=>{const key=event.target.closest('[data-calc-key]');if(key)apply(key.dataset.calcKey);});
  panel.addEventListener('keydown',event=>{if(event.key==='Escape'){event.stopPropagation();close();}else if(event.key==='Enter'&&event.target===input){event.preventDefault();apply('=');}});
  input.focus({preventScroll:true});
 };
}
