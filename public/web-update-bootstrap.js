/* Restore the fixed update shell before the first application frame. No draft or credential data. */
(()=>{
 try{
  const pending=JSON.parse(sessionStorage.getItem('omnigo.web-update.v1'));
  if(!/^\d+\.\d+\.\d+$/.test(pending?.version)||!Number.isFinite(pending?.createdAt)||Date.now()-pending.createdAt<0||Date.now()-pending.createdAt>=900000)return;
  const dialog=document.getElementById('webUpdateDialog');if(!dialog)return;
  if(window.__OMNIGO_DESKTOP__)dialog.dataset.desktopUpdate='true';
  dialog.dataset.phase='loading';
  document.documentElement.dataset.omnigoWebUpdating='true';
  document.getElementById('omnigoWelcome')?.remove();
  dialog.querySelector('h2').textContent='Uygulamanız güncelleniyor';
  dialog.querySelector('[role=status]').textContent='Güncel sürüm hazırlanıyor. Lütfen bekleyin.';
  dialog.querySelector('button').hidden=true;
  const progress=dialog.querySelector('[role=progressbar]');progress.hidden=false;progress.classList.add('is-loading');
  dialog.addEventListener('cancel',event=>event.preventDefault());dialog.showModal();
  setTimeout(()=>{
   if(dialog.dataset.controllerReady==='true'||!dialog.open)return;
   dialog.dataset.phase='error';
   dialog.querySelector('h2').textContent='Güncelleme henüz yüklenemedi';
   dialog.querySelector('[role=status]').textContent='Bağlantınızı kontrol edip yeniden deneyin.';
   progress.hidden=true;const button=dialog.querySelector('button');button.hidden=false;button.textContent='Tekrar dene';button.onclick=()=>location.reload();button.focus();
  },30000);
 }catch{/* Unavailable storage keeps the ordinary launch path intact. */}
})();
