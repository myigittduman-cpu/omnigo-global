import {showFeedback} from './save-feedback.js';
import {mountSetupConnections} from './customer-setup-connections.js';
import {api} from './api.js';
import {esc} from './ui.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';

export async function openMarketEditor({dialog,body,item,company,current,change,refresh}){
 const steps=['Mağaza adı','API bağlantısı','Görünürlük ve bağlantı','Son kontrol'];
 const panel=document.createElement('section');
 panel.className='market-editor';
 panel.innerHTML=`<nav aria-label="Mağaza ayarları">${steps.map((label,i)=>`<button type="button" class="btn" data-go="${i}">${label}</button>`).join('')}</nav>
 <div class="market-editor-main"><small>${esc(company.name)} · ${esc(item.name)}</small><h2></h2>
 <div data-connections></div><section data-policy><form class="form-grid"><label>Yapılacak işlem<select required name="action"><option value="">İşlem seçin</option><option value="${item.hidden?'show':'hide'}">${item.hidden?'Kartı göster':'Kartı gizle'}</option>${item.configured?'<option value="disconnect">Bağlantıyı kaldır</option>':''}<option value="delete">Mağaza tanımını sil</option></select></label><button class="btn">Kaydet</button></form></section>
 <section data-review></section><p role="alert"></p></div><footer><button class="btn" data-prev>Geri</button><span></span><button class="btn" data-next>İlerle</button></footer>`;
 const parentNodes=[...body.children];
 parentNodes.forEach(el=>el.hidden=true);
 body.append(panel);
 dialog.classList.add('market-editor-open');
 const live=()=>current()&&panel.isConnected;
 const root=panel.querySelector('[data-connections]');
 const error=panel.querySelector('[role=alert]');
 let step=0;
 async function leave(){
  if(!await canLeaveDialogDrafts()||!live())return;
  await refresh();
  dialog.classList.remove('market-editor-open');
 }
 async function show(n){
  if(!live())return;
  step=n;
  panel.querySelector('h2').textContent=steps[n];
  panel.querySelectorAll('[data-go]').forEach((b,i)=>b.setAttribute('aria-current',i===n?'step':'false'));
  root.hidden=n>1;
  root.dataset.mode=n===0?'name':'api';
  panel.querySelector('[data-policy]').hidden=n!==2;
  const review=panel.querySelector('[data-review]');review.hidden=n!==3;
  panel.querySelector('footer span').textContent=`Adım ${n+1} / 4`;
  panel.querySelector('[data-prev]').textContent=n?'Geri':'Listeye dön';
  panel.querySelector('[data-next]').textContent=n===3?'Tamamla':'İlerle';
  if(n===3){
   review.textContent='Bağlantı durumu alınıyor…';
   try{const report=await api('/admin/connection-health?company='+company.id);
    if(live())review.textContent=report.items.find(x=>x.channel===item.name)?.message||'Bağlantı durumu doğrulanamadı.';
   }catch(e){if(live())review.textContent=e.message;}
  }
 }
 panel.querySelectorAll('[data-go]').forEach(b=>b.onclick=()=>show(Number(b.dataset.go)));
 panel.querySelector('[data-prev]').onclick=()=>step?show(step-1):leave();
 panel.querySelector('[data-next]').onclick=()=>step===3?leave():show(step+1);
 panel.querySelector('[data-policy] form').onsubmit=async e=>{
  e.preventDefault();
  try {await change(e.target.elements.action.value);await refresh();dialog.classList.remove('market-editor-open');}
  catch(err){if(live())showFeedback(err.message,{kind:err.cancelled?'info':'error'});}
 };
 show(0);
 try{await mountSetupConnections(root,{company,markets:[item],current:live,onChange:()=>change(null)});
  const details=root.querySelector('[data-setup-provider]');if(details)details.open=true;
 }catch(e){if(live())error.textContent=e.message;}
}
