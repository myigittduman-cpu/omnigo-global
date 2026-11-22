// Exact manufacturer/model matches keep curated photography stable across order syncs.
// Original WebP files are hosted locally; the portal does not scrape product sites at runtime.
export const productMediaCatalog = Object.freeze({
 CGA242X3TR: Object.freeze({
  brand:'Profilo',imageUrl:'/assets/products/profilo/cga242x3tr.webp',
  source:'https://www.profilo.com/tr/tr/product/beyaz-esya/camasir-ve-kurutma-makineleri/camasir-makineleri/CGA242X3TR',
  original:'https://media3.bsh-group.com/Product_Shots/27234030_CGA242X3TR_STP_def.webp',
  width:2426,height:3360
 }),
 BD2176WEXN: Object.freeze({
  brand:'Profilo',imageUrl:'/assets/products/profilo/bd2176wexn.webp',
  source:'https://www.profilo.com/tr/tr/product/beyaz-esya/buzdolabi-ve-derin-dondurucular/buzdolaplari/BD2176WEXN',
  original:'https://media3.bsh-group.com/Product_Shots/26345713_BD2176WEXN_STP_def.webp',
  width:1273,height:3200
 }),
 BMS623V1: Object.freeze({
  brand:'Profilo',imageUrl:'/assets/products/profilo/bms623v1.webp',
  verifiedTitles:Object.freeze(['BMS623V1 6 Programlı Bulaşık Makinesi']),
  source:'https://www.profilo.com/tr/tr/product/beyaz-esya/bulasik-makineleri/BMS623V1',
  original:'https://media3.bsh-group.com/Product_Shots/26441916_BMS623V1_STP_def.webp',
  width:2029,height:2861
 }),
 BM6380MA: Object.freeze({
  brand:'Profilo',imageUrl:'/assets/products/profilo/bm6380ma.webp',
  source:'https://www.profilo.com/tr/tr/product/beyaz-esya/bulasik-makineleri/BM6380MA',
  original:'https://media3.bsh-group.com/Product_Shots/24597111_BM6380MA_STP_def.webp',
  width:2062,height:2861
 }),
 BD3076WEVN: Object.freeze({
  brand:'Profilo',imageUrl:'/assets/products/profilo/bd3076wevn.webp',
  source:'https://www.profilo.com/tr/tr/product/beyaz-esya/buzdolabi-ve-derin-dondurucular/buzdolaplari/BD3076WEVN',
  original:'https://media3.bsh-group.com/Product_Shots/20545495_BD3076WEVN_STP_def.webp',
  width:1623,height:3568
 })
});

export function curatedProductImage(product) {
 const model=String(product.sku??'').trim().toUpperCase();
 if(!Object.hasOwn(productMediaCatalog,model))return null;
 const media=productMediaCatalog[model];
 const brand=String(product.brand??'').trim(),title=String(product.title??'').trim();
 const verifiedTitle=!brand&&media.verifiedTitles?.includes(title);
 return /\bprofilo\b/i.test(brand+' '+title)||verifiedTitle?media:null;
}
