import {marketplaceLogo} from './marketplace-branding.js';
import {esc} from './ui.js';

export function productMarketplaces(providers=[]){
 if(!providers.length)return '';
 return `<span class="product-marketplaces" role="list" aria-label="Ürünün bağlı olduğu pazaryerleri">${providers.map(provider=>
  `<span role="listitem" title="${esc(provider)}" aria-label="${esc(provider)}">${marketplaceLogo(provider)}</span>`).join('')}</span>`;
}
