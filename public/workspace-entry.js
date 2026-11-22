import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,icon} from './ui.js';
import {companyWordmarkClass} from './user-portrait.js';

export function companyIdentity(company){
 return company.hasLogo?i18nHtml`<img class="company-wordmark${companyWordmarkClass(company.name)}" src="/api/management/logo?fit=1&company=${Number(company.id)}&v=${Number(company.brandingVersion)||0}" alt="${esc(company.name)} logosu">`:i18nHtml`<span>${esc(company.name.slice(0,2).toLocaleUpperCase('tr-TR'))}</span>`;
}

export function mountCompanyEntry(root,{companies,onSelect,onRefresh,onCreate,onLogout,onSecurity}){
 document.body.classList.add('workspace-entry-open');
 root.innerHTML=i18nHtml`<main class="workspace-entry"><div class="entry-orbits" aria-hidden="true"><i></i><i></i><i></i></div><header class="entry-top"><span>OMNIGO GLOBAL <b>YÖNETİCİ PORTALI</b></span><button type="button" class="entry-logout">Oturumu kapat ${icon('logout')}</button></header><section class="entry-content" aria-labelledby="entryTitle"><div class="entry-brand"><div class="entry-symbol" aria-hidden="true"><span class="entry-symbol-mark"><img src="assets/omnigo-logo.png" alt=""></span></div><p>OMNIGO GLOBAL</p><span>E-TİCARET DANIŞMANLIĞI</span></div><div class="entry-selection"><span class="entry-eyebrow">MARKALARINIZIN ÇALIŞMA ALANI</span><h1 id="entryTitle">Bugün hangi markayla çalışıyoruz?</h1><p>Devam etmek için bir mağaza seçin.</p><label class="entry-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6"></circle><path d="m15 15 5 5"></path></svg><input type="search" placeholder="Mağaza adıyla ara…" aria-label="Mağaza ara" autocomplete="off"></label><div class="entry-clients" aria-label="Mağazalar"></div><p class="entry-status" role="status" aria-live="polite"></p><div class="entry-actions"><button type="button" class="text-btn" data-entry-refresh>Listeyi yenile</button><button type="button" class="text-btn" data-entry-create>${icon('plus')} Yeni mağaza</button></div></div></section><footer class="entry-footer"><span>Her marka için ayrı çalışma alanı</span><a class="entry-security" href="/account-security.html">Hesap güvenliği</a><span>Omnigo Global · Yönetim</span></footer></main>`;
 const list=root.querySelector('.entry-clients'),status=root.querySelector('.entry-status');let busy=false;
 const matches=query=>companies.filter(company=>company.name.toLocaleLowerCase('tr-TR').includes(query.toLocaleLowerCase('tr-TR').trim()));
 function renderList(query=''){
  const found=matches(query);
  list.innerHTML=found.map(company=>i18nHtml`<button type="button" class="entry-client" data-entry-company="${Number(company.id)}"><span class="entry-client-logo">${companyIdentity(company)}</span><span class="entry-client-name"><strong title="${esc(company.name)}">${esc(company.name)}</strong><small>Yönetim panelini aç</small></span><span class="entry-client-arrow">${icon('arrow')}</span></button>`).join('')||i18nHtml`<p class="entry-empty">${companies.length?i18nT('Bu adla eşleşen mağaza bulunamadı.'):i18nT('Henüz mağaza eklenmedi. Yeni mağaza oluşturarak başlayın.')}</p>`;
 }
 renderList();
 root.querySelector('input').oninput=event=>{if(!busy)renderList(event.target.value);};
 list.onclick=async event=>{
  const button=event.target.closest('[data-entry-company]');if(!button||busy)return;
  busy=true;root.querySelectorAll('button,input').forEach(element=>element.disabled=true);button.classList.add('is-opening');list.setAttribute('aria-busy','true');status.textContent=i18nT('Çalışma alanı hazırlanıyor…');
  try{await onSelect(Number(button.dataset.entryCompany));}
  catch(error){if(!root.contains(list))return;status.textContent=error.message;status.classList.add('is-error');busy=false;root.querySelectorAll('button,input').forEach(element=>element.disabled=false);button.classList.remove('is-opening');list.removeAttribute('aria-busy');}
 };
 root.querySelector('[data-entry-refresh]').onclick=async()=>{
  if(busy)return;busy=true;status.classList.remove('is-error');status.textContent=i18nT('Mağaza listesi yenileniyor…');
  const controls=[...root.querySelectorAll('button,input')];controls.forEach(element=>element.disabled=true);list.setAttribute('aria-busy','true');
  try{const updated=await onRefresh();if(!root.contains(list))return;if(Array.isArray(updated))companies=updated;renderList(root.querySelector('input').value);status.textContent=i18nText`Liste güncellendi · ${companies.length} mağaza · ${new Date().toLocaleTimeString(currentLocale(),{hour:'2-digit',minute:'2-digit',second:'2-digit'})}`;}
  catch(error){if(root.contains(list)){status.textContent=error.message;status.classList.add('is-error');}}
  finally{busy=false;controls.forEach(element=>element.disabled=false);list.removeAttribute('aria-busy');}
 };
 root.querySelector('[data-entry-create]').onclick=onCreate;
 root.querySelector('.entry-logout').onclick=onLogout;
 root.querySelector('.entry-security').onclick=event=>{
  if(event.button||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||!onSecurity)return;
  if(busy){event.preventDefault();return;}
  if(onSecurity(event.currentTarget.href))event.preventDefault();
 };
}
