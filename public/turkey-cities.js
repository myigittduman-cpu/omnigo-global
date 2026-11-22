export const turkeyCities=Object.freeze(["Adana","Adıyaman","Afyonkarahisar","Ağrı","Amasya","Ankara","Antalya","Artvin","Aydın","Balıkesir","Bilecik","Bingöl","Bitlis","Bolu","Burdur","Bursa","Çanakkale","Çankırı","Çorum","Denizli","Diyarbakır","Edirne","Elazığ","Erzincan","Erzurum","Eskişehir","Gaziantep","Giresun","Gümüşhane","Hakkari","Hatay","Isparta","Mersin","İstanbul","İzmir","Kars","Kastamonu","Kayseri","Kırklareli","Kırşehir","Kocaeli","Konya","Kütahya","Malatya","Manisa","Kahramanmaraş","Mardin","Muğla","Muş","Nevşehir","Niğde","Ordu","Rize","Sakarya","Samsun","Siirt","Sinop","Sivas","Tekirdağ","Tokat","Trabzon","Tunceli","Şanlıurfa","Uşak","Van","Yozgat","Zonguldak","Aksaray","Bayburt","Karaman","Kırıkkale","Batman","Şırnak","Bartın","Ardahan","Iğdır","Yalova","Karabük","Kilis","Osmaniye","Düzce"]);
export const defaultCities=Object.freeze(['Ankara','Kırıkkale','Kırşehir','Nevşehir','Kayseri','Eskişehir']);
export const workspaceCities=state=>state.data?.deliveryCities??defaultCities;
const preferredProductCities=['Kayseri','Kırşehir','Nevşehir','Eskişehir','Kırıkkale'];
export function cityProductCities(available){
 const configured=new Set(available.filter(city=>turkeyCities.includes(city)));
 return [...new Set([...preferredProductCities.filter(city=>configured.has(city)),...configured])];
}
