import './static-i18n.js';
import {t as i18nT,text as i18nText} from './i18n.js';
import {androidRelease} from './android-update-link.js';
const status=document.querySelector('#installStatus'),link=document.querySelector('#downloadApk');
try{
 const params=new URLSearchParams(location.search),release=androidRelease(params.get('app'),params.get('version'));
 document.title=i18nT(release.title)+i18nT(' · Android güncellemesi');
 document.querySelector('#appTitle').textContent=i18nT(release.title);
 const logo=document.querySelector('.app-logo');
 if(logo)logo.src=`/assets/app-${params.get('app')==='admin'?'admin':'client'}-192-v1.png`;
 document.querySelector('#apkFilename').textContent=release.title+'.apk';
 link.textContent=i18nText`${i18nT(release.title)} indir`;
 link.href=release.url;link.hidden=false;
 if(/OmnigoAndroid\//.test(navigator.userAgent)){
  status.textContent=i18nT('Bu bağlantıyı telefonunuzun Chrome veya başka bir tarayıcısında açın.');
 }else{
  status.textContent=i18nT('APK indirmesi başlatılıyor. Başlamazsa aşağıdaki indirme düğmesine dokunun.');
  location.replace(release.url);
 }
}catch(error){status.textContent=error.message;}
