import {t as i18nT} from './i18n.js';
const soundUrl='/assets/notification-chime-v2.wav';
let context,buffer,loading,lastPlayed=-Infinity;
export async function unlockNotificationSound(){
 if(window.__OMNIGO_ORDER_NOTIFY__)return false;
 try{
  const Audio=window.AudioContext||window.webkitAudioContext;if(!Audio)return false;
  context??=new Audio();
  if(context.state==='suspended')await context.resume();
  loading??=fetch(soundUrl).then(response=>{if(!response.ok)throw new Error('Ses yüklenemedi.');return response.arrayBuffer();}).then(bytes=>context.decodeAudioData(bytes)).then(decoded=>buffer=decoded).catch(()=>{loading=null;});
  await loading;return Boolean(buffer&&context.state==='running');
 }catch{return false;}
}
export function playNotificationSound(preview=false){
 if(!buffer||context?.state!=='running')return false;
 const now=Date.now();if(!preview&&now-lastPlayed<1500)return true;
 try{const source=context.createBufferSource();source.buffer=buffer;source.connect(context.destination);source.onended=()=>source.disconnect();source.start();lastPlayed=now;return true;}catch{return false;}
}
export async function previewNotificationSound(){
 if(!await unlockNotificationSound()||!playNotificationSound(true))throw new Error(i18nT('Ses çalınamadı. Cihazınızın ses ayarlarını kontrol edin.'));
}
document.addEventListener('pointerdown',()=>void unlockNotificationSound(),{passive:true});
document.addEventListener('keydown',()=>void unlockNotificationSound(),{passive:true});
navigator.serviceWorker?.addEventListener('message',event=>{
 if(event.data?.type!=='omnigo-notification-sound'||!event.ports?.[0])return;
 const played=!document.hidden&&Date.now()<=event.data.deadline&&playNotificationSound();
 event.ports[0].postMessage({played});
});
