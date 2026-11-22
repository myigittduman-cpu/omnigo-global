const titles={admin:'OG Yönetici Portalı',client:'OG İş Ortağım'};
export function releaseFilename(role,type){
 if(!Object.hasOwn(titles,role)||!['apk','setup'].includes(type))throw new Error('Geçersiz kurulum dosyası türü.');
 return titles[role]+(type==='setup'?'.exe':'.apk');
}
export function releaseDisposition(role,type){
 const name=releaseFilename(role,type);
 // Token fallback avoids nested quote escaping in Apache/LiteSpeed config.
 // RFC 6266 clients use filename* and retain the exact Turkish display name.
 const fallback=name.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replaceAll('ı','i').replaceAll(' ','-');
 return `attachment; filename=${fallback}; filename*=UTF-8''${encodeURIComponent(name)}`;
}
export function releaseHeaderRules(role,type){
 const pattern=type==='setup'?'\\.exe$':`^(Omnigo-Global|OG)-${role==='admin'?'Yonetici-(Paneli|Portali)':'(Musteri-Portali|Is-Ortagim)'}-[0-9.]+\\.apk$`;
 return `<FilesMatch "${pattern}">\nHeader always set Content-Disposition "${releaseDisposition(role,type)}"\nHeader always set X-Content-Type-Options "nosniff"\n</FilesMatch>\n`;
}
export function androidDownloadRules(){
 return 'Options -Indexes\nAddType application/vnd.android.package-archive .apk\n'+['admin','client'].map(role=>releaseHeaderRules(role,'apk')).join('');
}
export function dispositionFilename(header){
 const match=String(header).match(/^attachment; filename=([A-Za-z0-9.-]+); filename\*=UTF-8''([A-Za-z0-9.%_-]+)$/);
 if(!match)throw new Error('İndirme başlığı beklenen güvenli biçimde değil.');
 return decodeURIComponent(match[2]);
}
