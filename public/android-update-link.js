import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const channels={
 admin:{host:'yonetim.omnigoglobal.com',prefix:'Yonetici-Portali',title:'OG Yönetici Portalı'},
 client:{host:'portal.omnigoglobal.com',prefix:'Musteri-Portali',title:'OG İş Ortağım'},
};
export function androidRelease(channel,version){
 const entry=Object.hasOwn(channels,channel)?channels[channel]:null;
 if(!entry||typeof version!=='string'||!/^\d+\.\d+\.\d+$/.test(version))throw new Error(i18nT('Android güncelleme bilgisi doğrulanamadı.'));
 // Historical binary URLs are immutable. Rename current packages without breaking older handoffs.
 const [major,minor,patch]=version.split('.').map(Number);
 const legacyAdmin=channel==='admin'&&(major<1||major===1&&minor===0&&patch<27);
 const prefix=legacyAdmin?'Yonetici-Paneli':channel==='client'&&major===1&&minor===0&&patch>=32?'Is-Ortagim':entry.prefix;
 return {title:entry.title,url:`https://${entry.host}/downloads/android/Omnigo-Global-${prefix}-${version}.apk`};
}
export function androidBrowserHandoff(channel,url){
 const version=typeof url==='string'?url.match(/-(\d+\.\d+\.\d+)\.apk$/)?.[1]:null;
 if(androidRelease(channel,version).url!==url)throw new Error(i18nT('Android güncelleme adresi doğrulanamadı.'));
 // Older APKs keep own-origin downloads inside WebView. The other first-party
 // portal opens in the browser; the requested role remains explicit and fixed.
 const host=channels[channel==='admin'?'client':'admin'].host;
 return `https://${host}/android-install.html?app=${channel}&version=${version}`;
}
