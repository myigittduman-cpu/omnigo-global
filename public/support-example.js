import {supportTemplate,permissionTemplate} from './customer-api-help.js';
import {t} from './i18n.js';

export function openSupportExample(trigger,provider,company,store,stockUpdates=false){
 const dialog=document.createElement('dialog');
 dialog.className='support-example-dialog';
 dialog.innerHTML='<h2></h2><article class="support-letter"></article><nav aria-label="Metin sayfaları" hidden><button class="btn" data-prev>Önceki</button><span></span><button class="btn" data-next>Sonraki</button></nav><footer><button class="btn" type="button" data-close></button><span role="status"></span><button class="btn primary" type="button" data-copy></button></footer>';
 const title=provider.name+' · '+trigger.textContent.trim();
 dialog.setAttribute('aria-label',title);
 dialog.querySelector('h2').textContent=title;
 const area=dialog.querySelector('.support-letter');
 area.setAttribute('aria-label',title);
 const letter=(trigger.dataset.template==='access'?supportTemplate:permissionTemplate)(provider,company,store,stockUpdates);
 const close=dialog.querySelector('[data-close]'),copy=dialog.querySelector('[data-copy]');
 close.textContent=t('Kapat');close.onclick=()=>dialog.close();
 copy.textContent=t('Metni kopyala');
 copy.onclick=async()=>{
  let copied=false;
  try{await navigator.clipboard.writeText(letter);copied=true;}
  catch{const source=document.createElement('textarea');source.value=letter;dialog.append(source);source.select();try{copied=document.execCommand('copy');}catch{}source.remove();}
  dialog.querySelector('[role=status]').textContent=t(copied?'Metin kopyalandı.':'Kopyalama başarısız. Tekrar deneyin.');
 };
 trigger.closest('dialog').append(dialog);
 dialog.addEventListener('close',event=>{event.stopPropagation();dialog.remove();trigger.focus({preventScroll:true});},{once:true});
 const nav=dialog.querySelector('nav'),prev=nav.querySelector('[data-prev]'),next=nav.querySelector('[data-next]');
 let pages=[],index=0;
 function draw(){
  area.textContent=pages[index];prev.disabled=index===0;next.disabled=index===pages.length-1;
  nav.querySelector('span').textContent=(index+1)+' / '+pages.length;
 }
 function layout(){
  nav.hidden=true;area.textContent=letter;
  if(area.scrollHeight<=area.clientHeight){pages=[letter];index=0;draw();return;}
  nav.hidden=false;
  const words=letter.match(/\S+\s*/gu)||[letter];pages=[];
  let start=0;
  while(start<words.length){
   let low=start+1,high=words.length;
   while(low<high){
    const mid=Math.ceil((low+high)/2);
    area.textContent=words.slice(start,mid).join('').trimEnd();
    if(area.scrollHeight<=area.clientHeight)low=mid;else high=mid-1;
   }
   pages.push(words.slice(start,low).join('').trimEnd());start=low;
  }
  index=Math.min(index,pages.length-1);draw();
 }
 prev.onclick=()=>{index--;draw();};next.onclick=()=>{index++;draw();};
 dialog.showModal();layout();
 const observer=new ResizeObserver(layout);observer.observe(dialog);
 dialog.addEventListener('close',()=>observer.disconnect(),{once:true});
}
