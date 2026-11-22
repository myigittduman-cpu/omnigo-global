export function webUpdateMarkup(admin=false){return `<dialog id="webUpdateDialog" class="mandatory-update-dialog${admin?' omnigo-welcome-admin':''}" aria-labelledby="webUpdateTitle" aria-describedby="webUpdateMessage"><section class="web-update-card omnigo-welcome-card">
 <div class="omnigo-welcome-glow" aria-hidden="true"></div>
 <svg class="omnigo-welcome-frame" viewBox="0 0 380 380" aria-hidden="true"><rect x="1" y="1" width="378" height="378" rx="19" pathLength="100"/></svg>
 <div class="omnigo-welcome-logo"><img src="assets/omnigo-logo.png" alt="Omnigo Global · E-Ticaret Danışmanlığı" width="1672" height="941" loading="lazy"><span aria-hidden="true"></span></div>
 <h2 id="webUpdateTitle">Yeni güncelleme hazır</h2>
 <p id="webUpdateMessage" class="omnigo-welcome-slogan" role="status" aria-live="polite">Devam etmek için uygulamanızı güncelleyin.</p>
 <div class="web-update-indicator">
  <div class="web-update-progress" role="progressbar" aria-label="Güncelleme ilerlemesi" hidden><span></span></div>
  <svg class="web-update-check" viewBox="0 0 32 32" aria-hidden="true" hidden><circle cx="16" cy="16" r="14"/><path d="m9 16 5 5 9-10"/></svg>
  <button type="button" class="btn" data-web-update-apply>Şimdi güncelle</button>
 </div>
 </section></dialog>`;}
export function updateView(){
 let dialog=document.getElementById('webUpdateDialog');
 if(!dialog){
  const admin=document.querySelector('[data-login-role=admin], #omnigoWelcome.omnigo-welcome-admin')!==null
   ||document.querySelector('meta[name=application-name]')?.content.startsWith('OG Yönetici');
  document.body.insertAdjacentHTML('beforeend',webUpdateMarkup(admin));dialog=document.getElementById('webUpdateDialog');
 }
 if(!dialog.dataset.bound){dialog.dataset.bound='true';dialog.addEventListener('cancel',event=>event.preventDefault());}
 if(window.__OMNIGO_DESKTOP__)dialog.dataset.desktopUpdate='true';
 const title=dialog.querySelector('h2'),message=dialog.querySelector('[role=status]'),button=dialog.querySelector('button'),progress=dialog.querySelector('[role=progressbar]'),fill=progress.firstElementChild,check=dialog.querySelector('.web-update-check');
 const open=()=>{if(!dialog.open)dialog.showModal();};
 return {dialog,button,open,
  waiting(){dialog.dataset.phase='waiting';open();title.textContent='Yeni güncelleme hazır';message.textContent='Devam etmek için uygulamanızı güncelleyin.';button.hidden=false;button.disabled=false;button.textContent='Şimdi güncelle';progress.hidden=true;check.hidden=true;},
  loading(){
   dialog.dataset.phase='loading';
   document.documentElement.dataset.omnigoWebUpdating='true';
   title.textContent='Uygulamanız güncelleniyor';
   message.textContent='Güncel sürüm hazırlanıyor. Lütfen bekleyin.';
   button.hidden=true;check.hidden=true;progress.hidden=false;
   progress.removeAttribute('aria-valuenow');progress.classList.add('is-loading');fill.style.width='28%';
   open();
  },
  progress(value){progress.classList.remove('is-loading');progress.setAttribute('aria-valuenow',String(Math.round(value)));progress.setAttribute('aria-valuemin','0');progress.setAttribute('aria-valuemax','100');fill.style.width=value+'%';message.textContent='Son kontroller tamamlanıyor.';},
  success(){dialog.dataset.phase='success';title.textContent='Güncelleme tamamlandı';message.textContent='Uygulamanız güncel ve kullanıma hazır.';progress.hidden=true;check.hidden=false;},
  error(error){dialog.dataset.phase='error';open();title.textContent='Güncelleme tamamlanamadı';message.textContent=error?.message||'Bağlantınızı kontrol edip yeniden deneyin.';progress.hidden=true;check.hidden=true;button.hidden=false;button.disabled=false;button.textContent='Tekrar dene';button.focus();},
  close(){dialog.close();document.documentElement.removeAttribute('data-omnigo-web-updating');}
 };
}
