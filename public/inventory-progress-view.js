import {esc} from './ui.js';

const labels={queued:'Aktarım bekleniyor',running:'Aktarım sürüyor',retry:'Yeniden denenecek',complete:'Aktarım tamamlandı',incomplete:'Eksik bilgiler kontrol bekliyor'};
const initialLabels={queued:'İlk ürün aktarımı bekleniyor',running:'İlk ürün aktarımı sürüyor',retry:'İlk aktarım yeniden denenecek',incomplete:'İlk aktarımda eksik bilgiler var'};
const duration=seconds=>seconds<60?'Bir dakikadan az':`${Math.ceil(seconds/60)} dakika`;
const refreshIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 2M18 17a7 7 0 0 1-12 2l-2-2"/></svg>';

function firstImportProgress(initial){
 if(!initial||initial.complete)return '';
 const percent=initial.percent;
 return `<progress max="100" ${percent==null?'':`value="${percent}"`} aria-label="İlk ürün aktarım ilerlemesi"></progress><div class="inventory-import-status"><span>${percent==null?'İlerleme hesaplanıyor':`%${percent}`} · ${initial.completedProviders||0} / ${initial.providerCount||0} pazaryeri</span>${initial.estimatedSeconds==null?'':`<span>Tahmini kalan süre: ${duration(initial.estimatedSeconds)}</span>`}</div>`;
}

function connectionDetails(activation,progress){
 const providers=progress.providers||[];
 return `<details><summary>Pazaryeri bağlantıları ve aktarım durumu</summary><div class="inventory-import-sources">${(activation.connections||[]).map(connection=>{
  const job=providers.find(p=>p.provider===connection.provider||p.provider===connection.name);
  return `<div><strong>${esc(connection.name||connection.provider)}</strong><span>${esc(job?(labels[job.state]||job.state):(connection.message||connection.reason||connection.state||''))}</span>${job?`<small>${job.products||0} ürün · ${job.pages||0} sayfa${job.unknownQuantities?` · ${job.unknownQuantities} stok bilgisi eksik`:''}</small>${job.error?`<small class="inventory-history-note">${esc(job.error)}</small>`:''}`:''}</div>`;
 }).join('')}</div><p class="muted">Aktarım sunucuda sürer. Bu ekranı kapatsanız da işlem devam eder; bağlantı düzelince yeniden denenir.</p></details>`;
}

export function inventoryProgressView(data,admin){
 if(!data)return '';
 const activation=data.activation||{},progress=data.progress||{},initial=data.initialImport,manual=data.settings?.mode==='store';
 if(manual)return '<section class="inventory-import"><strong>Mağaza stoğu · Manuel takip</strong><p>Ürünleri ve mağaza giriş / satışlarını elle kaydedin. Pazaryeri takibi müşteri ayarlarından daha sonra açılabilir.</p></section>';
 const active=activation.marketplaceActive,showStart=admin&&activation.canStart&&(!active||data.persistentError);
 // Connection runs are separate from the customer's already completed first import.
 if(active&&initial?.complete&&!data.persistentError)return '';
 const title=!active?'Pazaryeri ürünleri':initial?.complete?'Stok bağlantısı kontrol gerektiriyor':(initialLabels[initial?.state]||'Ürün aktarımı kontrol ediliyor');
 const message=active&&initial?.complete?'Aktarımda devam eden bir bağlantı sorunu var. Son alınan stok bilgileri korunuyor.':(activation.message||'Bu müşterinin pazaryeri bağlantıları kontrol ediliyor.');
 const button=showStart?`<button type="button" class="btn secondary" data-inventory-start>${refreshIcon}<span>${active?'Yeniden kontrol et':'Canlı aktarımı başlat'}</span></button>`:'';
 const providers=progress.providers||[];
 const errors=providers.filter(job=>['incomplete','failed','error'].includes(job.state));
 const errorLines=errors.map(job=>`<p class="inventory-warning"><strong>${esc(job.provider)}</strong> · ${esc(job.error||labels[job.state]||'Aktarım tamamlanamadı')}</p>`).join('');
 if(active && initial?.state==='retry' && !initial.complete){
  const waiting=providers.filter(job=>job.state==='retry');
  return `${errorLines}<details class="inventory-retry-line"><summary>İlk aktarım yeniden denenecek · Ayrıntıları göster</summary>
   ${waiting.map(job=>`<p><strong>${esc(job.provider)}</strong>${job.error?' · '+esc(job.error):''}</p>`).join('')||'<p>Yeniden deneme bekleniyor.</p>'}${button}</details>`;
 }
 return `<section class="inventory-import" aria-label="Ürün aktarımı"><div class="inventory-import-heading"><div><strong>${esc(title)}</strong><p>${esc(message)}</p></div>${button}</div>${errorLines}${active&&initial?.state==='running'?firstImportProgress(initial):''}${connectionDetails(activation,progress)}</section>`;
}
