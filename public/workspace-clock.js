import {html} from './i18n.js';
const timeFormat=new Intl.DateTimeFormat('tr-TR',{timeZone:'Europe/Istanbul',hour:'2-digit',minute:'2-digit',second:'2-digit'});
const dateFormat=new Intl.DateTimeFormat('tr-TR',{timeZone:'Europe/Istanbul',day:'numeric',month:'numeric',year:'numeric'});
export function workspaceClock(){return html`<div class="workspace-clock" aria-label="Türkiye saati ve tarih"><time data-workspace-time></time><span data-workspace-date></span></div>`;}
function update(){const now=new Date();document.querySelectorAll('[data-workspace-time]').forEach(el=>{el.textContent=timeFormat.format(now);el.dateTime=now.toISOString();});document.querySelectorAll('[data-workspace-date]').forEach(el=>el.textContent=dateFormat.format(now));}
if(typeof document!=='undefined'){setInterval(()=>{if(!document.hidden)update();},1000);document.addEventListener('visibilitychange',update);}
export const refreshWorkspaceClock=update;
