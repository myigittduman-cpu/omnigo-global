import {html,text,t} from './i18n.js';
import {esc} from './ui.js';

const reasons={error:'bağlantı kontrolü gerekli',retry:'yeniden deneme bekliyor',stale:'son okuma güncel değil',missing_queue:'aktarımı henüz başlamadı'};
export function payoutSourceWarning(warnings=[]){
 if(!warnings.length)return '';
 const details=warnings.map(item=>text`${item.channel} · ${t(reasons[item.status]??'kontrol gerekiyor')}`).join(', ');
 return html`<p class="report-warning payout-source-warning" role="status">Hakediş API bağlantısı: ${esc(details)}</p>`;
}
