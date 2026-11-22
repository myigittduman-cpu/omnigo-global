// Only code-owned interface text is transformed. Names, documents and data stay intact.
let customer=false;
export function setCustomerLanguage(role){customer=role==='client';}
export function customerLanguage(source){
 if(typeof source!=='string')return source;
 return source.split(/((?:OG|Omnigo Global) Yönetici[ \u00a0]Portalı|(?:OG|Omnigo Global) Müşteri[ \u00a0]Portalı)/g).map((part,index)=>index%2?part:customerWording(part)).join('');
}
function customerWording(source){
 const text=customer?source.replace(/Pazaryeri \/ Hakediş Bildirimleri/g,'Hakediş Bildirimleri'):source;
 return text
 .replace(/Omnigo operasyon yöneticileri/g,'Omnigo operasyon ekibi')
 .replace(/Sistem yöneticisi|sistem yöneticisi/g,'Omnigo teknik ekibi')
 .replace(/YÖNETİCİ/g,'OMNIGO')
 .replace(/Yönetici(?:ler)? tarafından|yönetici(?:ler)? tarafından/g,'Omnigo tarafından')
 .replace(/Yöneticinin|yöneticinin|Yöneticilerin|yöneticilerin/g,'Omnigo’nun')
 .replace(/Yöneticiye|yöneticiye/g,'Omnigo’ya')
 .replace(/Yöneticiden|yöneticiden/g,'Omnigo’dan')
 .replace(/Yöneticinizle|yöneticinizle/g,'Omnigo ile')
 .replace(/Yöneticinizin|yöneticinizin/g,"Omnigo’nun")
 .replace(/Yöneticinize|yöneticinize/g,"Omnigo’ya")
 .replace(/Yöneticiniz|yöneticiniz/g,'Omnigo')
 .replace(/Yönetici panelinden|yönetici panelinden/g,'Omnigo tarafından')
 .replace(/Yönetici paneli|yönetici paneli/g,'Omnigo paneli')
 .replace(/Yönetim ekibi|yönetim ekibi/g,'Omnigo')
 .replace(/Yöneticileri|yöneticileri|Yöneticiler|yöneticiler|Yönetici|yönetici/g,'Omnigo')
 .replace(/Ihrem Administrator|Ihren Administrator|Ihr Administrator|Verwaltungsteam/g,'Omnigo')
 .replace(/your administrator(?:s)?|your manager|the administrator(?:s)?|\badministrators?\b|management team/gi,'Omnigo');
}
