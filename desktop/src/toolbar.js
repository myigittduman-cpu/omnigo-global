(() => {
 if (window !== window.top || location.origin !== '__PORTAL_ORIGIN__' || window.__OMNIGO_TOOLBAR__) return;
 Object.defineProperty(window, '__OMNIGO_TOOLBAR__', {value:true});
 function mount() {
  const toolbar=document.createElement('div');
  toolbar.id='omnigo-desktop-toolbar'; toolbar.hidden=true;
  const icon=path=>`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
  const refresh=icon('<path d="M20 7v5h-5M4 17v-5h5"/><path d="M6 7a7 7 0 0 1 11.5-1L20 9M4 15l2.5 3A7 7 0 0 0 18 17"/>');
  const maximizeIcon=icon('<rect x="5" y="5" width="14" height="14" rx="1"/>');
  const restoreIcon=icon('<path d="M9 5V3h12v12h-2"/><rect x="3" y="9" width="12" height="12" rx="1"/>');
  const admin=location.hostname==='yonetim.omnigoglobal.com';
  toolbar.innerHTML=`<div class="desktop-bar-brand"><span class="desktop-bar-logo"><img src="/assets/app-${admin?'admin':'client'}-192-v6.png" alt="" width="40" height="40" draggable="false"></span><div class="desktop-bar-copy"><small>OMNIGO GLOBAL</small><span>${admin?'OG Yönetici Portalı':'OG İş Ortağım'}</span></div></div><span class="desktop-bar-status" role="status" aria-live="polite"></span><div class="desktop-bar-actions" role="group" aria-label="Uygulama denetimleri"><button type="button" data-desktop-update title="Güncellemeleri kontrol et" aria-label="Güncellemeleri kontrol et">${refresh}<span>Güncellemeler</span></button></div><div class="desktop-window-controls" role="group" aria-label="Pencere denetimleri" hidden><button type="button" data-window-action="minimize" title="Simge durumuna küçült" aria-label="Simge durumuna küçült">${icon('<path d="M5 12h14"/>')}</button><button type="button" data-window-action="toggle_maximize" title="Pencereyi büyüt" aria-label="Pencereyi büyüt">${maximizeIcon}</button><button type="button" data-window-action="close" title="Kapat" aria-label="Kapat">${icon('<path d="m6 6 12 12M6 18 18 6"/>')}</button></div>`;
  const status=toolbar.querySelector('[role=status]'),update=toolbar.querySelector('[data-desktop-update]');
  const controls=toolbar.querySelector('.desktop-window-controls'),maximize=toolbar.querySelector('[data-window-action="toggle_maximize"]');
  const invoke=(action,detail={})=>window.__TAURI_INTERNALS__.invoke('desktop_action',{action,...detail});
  let ready=false,chromeReady=false,contentAccepted=false,contentProbe,resizeTimer;
  function setWindowState(maximized){
   const label=maximized?'Önceki boyuta dön':'Pencereyi büyüt';
   maximize.title=label;maximize.setAttribute('aria-label',label);
   maximize.innerHTML=maximized?restoreIcon:maximizeIcon;
  }
  async function syncWindowState(){
   if(!ready)return;
   try{setWindowState(await invoke('window_state'));}catch{/* Keep the last known state during navigation. */}
  }
  async function revealContent(){
   if(contentAccepted||!chromeReady||document.documentElement.dataset.omnigoApplicationReady!=='true')return;
   clearTimeout(contentProbe);
   try{
    contentAccepted=await invoke('content_ready',{viewport:{width:innerWidth,height:innerHeight,scale:devicePixelRatio}});
    if(contentAccepted)await syncWindowState();else contentProbe=setTimeout(revealContent,80);
   }catch{/* Older shells keep their existing reveal path. */}
  }
  window.addEventListener('omnigo-application-ready',revealContent);
  async function run(button,action){
   if(button.disabled)return;
   button.disabled=true; status.textContent='';
   if(action==='check_updates'){update.classList.add('checking');status.textContent='Güncellemeler kontrol ediliyor…';}
   try{const maximized=await invoke(action);if(action!=='close')setWindowState(maximized);status.textContent='';}
   catch{status.textContent='İşlem tamamlanamadı. Yeniden deneyin.';}
   finally{button.disabled=false;update.classList.remove('checking');}
  }
  update.addEventListener('click',()=>run(update,'check_updates'));
  controls.addEventListener('click',event=>{
   const button=event.target.closest('button[data-window-action]');
   if(ready&&button)run(button,button.dataset.windowAction);
  });
  toolbar.addEventListener('mousedown',event=>{
   if(!ready||event.button!==0||event.target.closest('button'))return;
   event.preventDefault();
   // Native drag handling preserves Windows edge snapping. A second press toggles size.
   if(event.detail===2)run(maximize,'toggle_maximize');
   else invoke('start_dragging').catch(()=>{status.textContent='Pencere taşınamadı. Yeniden deneyin.';});
  });
  window.addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{syncWindowState();revealContent();},80);});
  window.addEventListener('focus',syncWindowState);
  document.body.prepend(toolbar);
  const stylesheet=document.createElement('link');
  stylesheet.rel='stylesheet'; stylesheet.href='/desktop-toolbar.css?v=027';
  stylesheet.addEventListener('load',async()=>{
   document.documentElement.classList.add('omnigo-desktop-shell');toolbar.hidden=false;
   // An old/cached stylesheet must not replace the system caption with unstyled buttons.
   if(getComputedStyle(toolbar).getPropertyValue('--desktop-chrome-version').trim()!=='27')return;
   controls.hidden=false;
   try{setWindowState(await invoke('toolbar_ready'));ready=true;chromeReady=true;document.documentElement.dataset.omnigoDesktopChromeReady='true';window.dispatchEvent(new Event('omnigo-desktop-chrome-ready'));await revealContent();await syncWindowState();}
   catch{controls.hidden=true;}
  },{once:true});
  document.head.append(stylesheet);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();

