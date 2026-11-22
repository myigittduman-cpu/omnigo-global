import {esc} from './ui.js';

export function marketplaceStockAmount(item){
 return Number.isSafeInteger(item.marketplaceQuantity)&&item.marketplaceQuantity>=0&&['current','reference'].includes(item.marketplaceHealth)?item.marketplaceQuantity:null;
}

export function inventoryStockForm(item,storeSale=false,{horoz=false,canReturn=true}={}){
 const first=item.quantity===null,mode=storeSale?'store_sale':first?'initialize':'set';
 const label=storeSale?(horoz?'Adet':'Mağazada satılan adet'):horoz?'Güncel ortak stok adedi':first?'Başlangıç mağaza stok adedi':'Güncel mağaza stok adedi',pool=horoz?'ortak':'mağaza';
 const returnsVisible=canReturn&&item.sensitiveVisibility?.returns!==false;
 const modeField=storeSale?`<label>İşlem<select name="mode"><option value="store_sale">Mağaza satışı</option>${returnsVisible?'<option value="store_return">Mağaza satış iadesi</option>':''}</select></label>`:`<input type="hidden" name="mode" value="${mode}">`;
 const sourceAmount=marketplaceStockAmount(item),sameButton=storeSale?'':`<button type="button" class="btn secondary" data-stock-use-marketplace ${sourceAmount===null?'disabled':''}>Pazaryeri stoğuyla aynı${sourceAmount===null?'':` · ${sourceAmount} adet`}</button>`;
 return `<form class="form-grid" data-stock-form><p><strong>${esc(item.title)}</strong></p><p class="muted">Mevcut ${pool} stoğu: ${first?'Henüz girilmedi':esc(item.quantity)}</p>${modeField}<label>${label}<input name="amount" type="text" inputmode="numeric" autocomplete="off" maxlength="10" required></label>${sameButton}<p class="form-help">${storeSale?`Satış adedi ${pool} stoğundan düşer ve satış detaylarına eklenir. ${returnsVisible?'Satış iadesi seçilirse kayıtlı mağaza satışı kadar stok geri eklenir.':''}`:'Elinizdeki güncel adedi yazın. Kaydettiğiniz sayı '+pool+' stoğunun yeni değeri olur.'}</p><output data-stock-preview></output><p class="muted">İşlem, tarih ve kullanıcı bilgisiyle stok hareketlerine kaydedilir.</p><p data-stock-result role="status"></p><p class="form-error" role="alert"></p><button type="submit" class="btn primary">Kaydet</button></form>`;
}

export function parseStockAmount(value,storeSale=false){
 const raw=String(value).trim();
 if(!/^-?\d+$/.test(raw))throw new Error('Adedi tam sayı olarak yazın.');
 const amount=Number(raw);
 if(!Number.isSafeInteger(amount)||Math.abs(amount)>100000000||(storeSale&&amount<1))throw new Error(storeSale?'Satılan adet 1 ile 100.000.000 arasında olmalı.':'Stok adedi -100.000.000 ile 100.000.000 arasında olmalı.');
 return amount;
}

export function stockPreview(item,value,storeSale=false,{horoz=false,mode='store_sale'}={}){
 try{const amount=parseStockAmount(value,storeSale);return `Kaydedilecek ${horoz?'ortak':'mağaza'} stoğu: ${storeSale?Number(item.quantity)+(mode==='store_return'?amount:-amount):amount} adet`;}
 catch{return '';}
}
