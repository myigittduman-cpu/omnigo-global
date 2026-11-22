import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {installationManifest} from './installation-icons.js';
const admin = location.hostname === 'yonetim.omnigoglobal.com';
const role=admin?'admin':'client';
for(const icon of document.querySelectorAll('link[rel="apple-touch-icon"]')){const size=icon.sizes.value==='1024x1024'?1024:180;icon.href=`/assets/app-${role}-mobile-${size}-v3.png`;}
document.title = i18nT(admin ? 'OG Yönetici Portalı' : 'OG İş Ortağım');
const homeScreenTitle = document.querySelector('meta[name="apple-mobile-web-app-title"]') || document.head.appendChild(document.createElement('meta'));
homeScreenTitle.name = 'apple-mobile-web-app-title'; homeScreenTitle.content = admin ? 'OG Yönetici Portalı' : 'OG İş Ortağım';
document.querySelector('link[rel="manifest"]').href = installationManifest(admin?'admin':'client',navigator);
const installed = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
let installPrompt;
const button = document.createElement('button');
button.className = 'install-control';
button.textContent = i18nT('Uygulamayı yükle');
window.addEventListener('omnigo-language-change',()=>{button.textContent=i18nT('Uygulamayı yükle');document.title=i18nT(admin?'OG Yönetici Portalı':'OG İş Ortağım');});
button.hidden = Boolean(installed);
// Keep the control in normal page flow, including after navigation redraws #app.
function placeInstallControl() {
 const container = document.querySelector('#app .main, #app .login-panel > div') || document.body;
 if (button.parentElement !== container) container.append(button);
}
placeInstallControl();
const app = document.getElementById('app');
if (app) new MutationObserver(placeInstallControl).observe(app, {childList: true});
window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); installPrompt = event; });
window.addEventListener('appinstalled', () => { button.hidden = true; installPrompt = null; });
button.onclick = async () => {
 if (installPrompt) {
  await installPrompt.prompt();
  const choice = await installPrompt.userChoice;
  if (choice.outcome === 'accepted') button.hidden = true;
  installPrompt = null;
  return;
 }
 const dialog = document.createElement('dialog'); dialog.className = 'install-help';
 const text = document.createElement('p');
 text.textContent = /iPad|iPhone/.test(navigator.userAgent)
  ? i18nT('Safari’de Paylaş menüsünü açın ve Ana Ekrana Ekle seçeneğine dokunun.')
  : i18nT('Tarayıcınızın menüsünde Uygulamayı yükle veya Ana ekrana ekle seçeneğini kullanın. Bu seçenek görünmüyorsa Chrome, Edge veya Safari ile açın.');
 const close = document.createElement('button'); close.textContent = i18nT('Tamam'); close.onclick = () => dialog.close();
 dialog.onclose = () => dialog.remove(); dialog.append(text, close); document.body.append(dialog); dialog.showModal();
};
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
