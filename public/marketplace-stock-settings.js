import {api} from './api.js';
import {esc} from './ui.js';

const sourceStates={verified:'Güncel tarama doğrulandı',unverified:'Güncellik doğrulanamadı',unavailable:'Güncel tarama kaydı yok'};
const connectionStates={complete:'Son kontrol başarılı',running:'Kontrol ediliyor',queued:'Kontrol bekliyor',failed:'Kontrol gerekiyor',retry:'Tekrar denenecek',not_enabled:'Etkin değil',unsupported:'Desteklenmiyor'};
const stockWriters=new Set(['hepsiburada','n11','trendyol']);
const activeReaders=new Set([...stockWriters,'koctas','pttavm']);

export function stockSettingsReports(runs){
 const latest=new Map();
 for(const report of runs||[]){
  if(!report?.provider)continue;
  const saved=latest.get(report.provider);
  if(!saved||Date.parse(report.updatedAt||report.startedAt)>Date.parse(saved.updatedAt||saved.startedAt))latest.set(report.provider,report);
 }
 if(!latest.size)return '<p>Bu mağaza için bağlantı ve katalog raporu bulunamadı.</p>';
 const rows=[...latest.values()].sort((a,b)=>(a.providerLabel||a.provider).localeCompare(b.providerLabel||b.provider,'tr'));
 return `<div class="orders-table-wrap corporate-table-viewport" tabindex="0" aria-label="Stok aktarımı kapsamı"><table class="corporate-table pilot-stock-coverage">
  <thead><tr><th scope="col">Pazaryeri</th><th scope="col">Bağlantı</th><th scope="col">Katalog</th><th scope="col">İlan işlemi</th></tr></thead><tbody>
  ${rows.map(report=>`<tr><th scope="row">${esc(report.providerLabel||report.provider)}</th><td>${esc(connectionStates[report.stages?.connection?.status]||'Doğrulanamadı')}</td>
   <td>${esc(sourceStates[report.currentCatalogSummary?.sourceState]||'Güncel tarama kaydı yok')}</td>
   <td>${stockWriters.has(report.provider)?'Uygun ilanlarda stok gönderimi ve fiyat güncelleme':activeReaders.has(report.provider)?'Yalnız görüntüleme':'Aktif satış durumu doğrulanamıyor'}</td></tr>`).join('')}
  </tbody></table></div>`;
}

export function mountStockPilotSettings(anchor,{company,current,openList}){
 const panel=document.createElement('section');
 panel.className='permission-card pilot-stock-settings';panel.setAttribute('data-pilot-stock-settings','');
 panel.innerHTML=`<h4>Pazaryeri stok aktarımı</h4>
  <p>Bu mağazada pazaryeri stok aktarımı açık. Liste, son 30 dakikada tamamlanan katalog taramasında satışa açık olduğu doğrulanan ilanları gösterir.</p>
  <p>Stok Yönetimi’ne elle eklenen ürünler ve stok hareketleri mağaza kaydında kalır. Bu tablo ilan adetlerini doğrudan pazaryerlerinden okur.</p>
  <p>Bu ekrandan girilen adet veya satış fiyatı, aynı mağazanın eşleşen aktif ilanlarına gönderilir. Her pazaryerinin sonucu ayrı doğrulanır.</p>
  <p>Sonuç, adet pazaryerinden yeniden okununca kesinleşir. Belirsiz sonuçta yeniden kontrol işlemi yalnız son adedi okur. Bu kurallar sabittir.</p>
  <div data-stock-coverage aria-live="polite"></div><button type="button" class="btn secondary" data-open-stock-list>Pazaryeri Stokları tablosunu aç</button>`;
 anchor.after(panel);
 const slot=panel.querySelector('[data-stock-coverage]');
 panel.querySelector('[data-open-stock-list]').onclick=()=>{if(current())void openList();};
 let request=0;
 async function refresh(){
  if(!current())return;
  const seq=++request;
  if(!slot.hasChildNodes())slot.textContent='Bağlantı durumları okunuyor…';
  try{
   const data=await api('/inventory?'+new URLSearchParams({company,page:0,pageSize:15}));
   if(seq===request&&current()){
    const active=data.activation?.marketplaceActive;
    const setting=typeof active==='boolean'?'<p><strong>Stok Yönetimi canlı aktarımı</strong> · '+(active?'Açık':'Kapalı')+'</p>':'';
    slot.innerHTML=setting+stockSettingsReports(data.connectionImports);
   }
  }catch(failure){if(seq===request&&current())slot.textContent=failure.message||'Bağlantı durumları okunamadı.';}
 }
 return {refresh};
}
