import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog} from './ui.js';

export function completeAdminMfa(challenge,{settings=false,container=null,signal}={}){
 const endpoint=settings?'/account/security/email':'/login/mfa';
 return new Promise((resolve,reject)=>{
  const markup=i18nHtml`<form class="mfa-form"><p><strong>${esc(challenge.email)}</strong> adresine doğrulama kodu sıraya alındı. Gelen kutunuzu ve gereksiz posta klasörünü kontrol edin.</p><label>6 haneli kod<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><small>Kod talep edildiği andan itibaren 10 dakika geçerli. Yeniden gönderirseniz yalnız son kodu kullanın.</small><p role="status" data-mfa-message></p><button type="submit" class="btn primary">Doğrula ve devam et</button><button type="button" class="btn" data-mfa-resend>Yeni kod gönder</button>${container?i18nHtml('<button type="button" class="btn" data-mfa-cancel>Vazgeç</button>'):''}</form>`;
  const dialog=container?document.createElement('section'):openDialog(i18nT('E-posta doğrulaması'),markup,{className:'mfa-dialog'});
  if(container){dialog.innerHTML=markup;container.append(dialog);}
  const form=dialog.querySelector('form'),message=form.querySelector('[data-mfa-message]'),resend=form.querySelector('[data-mfa-resend]'),close=dialog.querySelector(container?'[data-mfa-cancel]':'.close');
  let busy=false,done=false,resendAt=challenge.resendAt;
  const update=()=>{const seconds=Math.max(0,resendAt-Math.floor(Date.now()/1000));resend.disabled=busy||seconds>0;resend.textContent=seconds?i18nText`Yeni kod için ${seconds} sn`:i18nT('Yeni kod gönder');};
  const controls=()=>{form.querySelector('[type=submit]').disabled=busy;close.disabled=busy;update();};
  const timer=setInterval(update,1000);update();form.elements.code.focus({preventScroll:true});
  function finish(){
   clearInterval(timer);form.reset();signal?.removeEventListener('abort',finish);
   if(container)dialog.remove();
   if(!done)reject(new Error(i18nT('Doğrulama iptal edildi.')));
  }
  if(container){close.onclick=()=>{if(!busy)finish();};signal?.addEventListener('abort',finish,{once:true});}
  form.onsubmit=async event=>{
   event.preventDefault();if(busy)return;busy=true;controls();message.textContent=i18nT('Doğrulanıyor…');
   try{const result=await api(endpoint+'/verify','POST',{challenge:challenge.challenge,code:form.elements.code.value.trim()});done=true;resolve(result);if(container)finish();else dialog.close();}
   catch(error){message.textContent=error.message;form.elements.code.select();}
   finally{busy=false;controls();}
  };
  resend.onclick=async()=>{
   if(busy)return;busy=true;controls();
   try{const result=await api(endpoint+'/resend','POST',{challenge:challenge.challenge});resendAt=result.resendAt;form.elements.code.value='';message.textContent=i18nT('Yeni kod sıraya alındı. Önceki kod geçersiz.');}
   catch(error){message.textContent=error.message;}finally{busy=false;controls();}
  };
  if(container)return;
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  // Ignore backdrop dismissal during the transaction to avoid an orphaned new session.
  dialog.onclick=null;dialog.onpointerdown=null;
  dialog.addEventListener('close',finish,{once:true});
 });
}
