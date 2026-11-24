import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
// Original artwork is kept intact. Wide marks use a separate presentation frame.
const brands={
 'Hepsiburada':{file:'hepsiburada-080.png',shape:'square'},
 'n11':{file:'n11-080.jpg',shape:'square'},
 'idefix':{file:'idefix-080.png',shape:'square'},
 'PTT AVM':{file:'pttavm-official-09118.svg',shape:'wide'},
 'Pazarama':{file:'pazarama-wordmark-20261031.jpg',shape:'wide'},
 'Koçtaş':{file:'koctas-classic-20261031.svg',shape:'wide'},
 'Teknosa':{file:'teknosa-080.jpg',shape:'wide'},
 'Trendyol':{file:'trendyol-provided-09144.webp',shape:'square',framing:'trendyol-app'},
};
export const marketplaceBrand=name=>Object.hasOwn(brands,name)?brands[name]:null;
export function marketplaceLogo(name){
 const brand=marketplaceBrand(name);
 return brand?i18nHtml`<span class="marketplace-mark marketplace-mark--${brand.shape}${brand.framing?` marketplace-mark--${brand.framing}`:''}"><img src="assets/${brand.file}" alt="${name}" title="${name}" decoding="async"></span>`:'';
}
