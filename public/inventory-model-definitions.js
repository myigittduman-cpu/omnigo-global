/** Public labels describe behavior; persisted model identifiers stay backward compatible. */
export const inventoryModelDefinitions=Object.freeze({
 horoz:{label:'Canlı stok + manuel işlem',summary:'Pazaryeri stoğu esas alınır. Mağaza satışı, iadesi ve sayımı aynı stoğu değiştirir.',description:'Pazaryerinden gelen canlı stok esas alınır. Omnigo’ya girilen mağaza satışı bu stoktan düşer; satış iadesi artırır. Sayım aynı stoğu düzeltir; ayrı mağaza stoğu tutulmaz.'},
 both:{label:'Canlı stok + ayrı mağaza stoğu',summary:'Pazaryeri stoğu canlı güncellenir. Mağaza stoğu ayrı tutulur ve elle yönetilir.',description:'Pazaryeri stoğu canlı güncellenir, mağaza stoğu elle yönetilir. Mağaza satışı ve iadesi yalnız mağaza stoğunu değiştirir. Toplam stok iki havuzun toplamıdır.'},
 store:{label:'Yalnız manuel stok',summary:'Stok elle girilir. Sayım, mağaza satışı ve iade miktarı günceller.',description:'Stok miktarı elle girilir ve sayımla güncellenir. Mağaza satışı stoğu azaltır, satış iadesi artırır. Pazaryerlerindeki stok değişimleri bu stoğa uygulanmaz.'},
 marketplace:{label:'Yalnız canlı stok',summary:'Pazaryeri adetleri otomatik aktarılır. Stok ekranı görüntüleme için kullanılır.',description:'Pazaryerlerinin bildirdiği güncel stok izlenir. Omnigo’dan manuel sayım, stok değişikliği veya mağaza satışı girilmez; adetler canlı aktarımla güncellenir.'}
});
export const inventoryModelDefinition=mode=>inventoryModelDefinitions[mode]||inventoryModelDefinitions.marketplace;
