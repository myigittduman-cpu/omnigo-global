import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import {withPreviewMetadata} from './branding/link-preview.mjs';
import {portalLoginView} from '../public/portal-login-view.js';
import {welcomeScreenView} from '../public/welcome-screen-view.js';
import {webUpdateMarkup} from '../public/web-update-view.js';

// Safari can use the initial HTML while adding a site to the Home Screen.
// Keep both entry documents identical except for their installation identity.
const touchIcons='<link rel="apple-touch-icon" sizes="180x180" href="/assets/app-client-mobile-180-v3.png"><link rel="apple-touch-icon" sizes="1024x1024" href="/assets/app-client-mobile-1024-v3.png">';
const entry = withPreviewMetadata((await fs.readFile(new URL('../public/index.html', import.meta.url), 'utf8')).replace(/(?:<link[^>]*rel="apple-touch-icon"[^>]*>)+/,touchIcons),'client');
assert(entry.includes('<title>OG İş Ortağım</title>'));
assert(entry.includes('href="client.webmanifest"'));
const admin = entry.replaceAll('OG İş Ortağım', 'OG Yönetici Portalı')
  .replaceAll('app-client-mobile-','app-admin-mobile-')
  .replaceAll('OG İş&nbsp;Ortağım', 'OG Yönetici&nbsp;Portalı')
  .replace('/assets/app-client-180-v3.png', '/assets/app-admin-180-v3.png')
  .replace('/assets/app-client-apple-1024-v3.png','/assets/app-admin-apple-1024-v3.png')
  .replace('/assets/app-client-192-v4.png', '/assets/app-admin-192-v4.png')
  .replace('href="client.webmanifest"', 'href="admin.webmanifest"');
function firstFrame(source,role){
 const start=source.indexOf('<div id="app">'),end=source.indexOf('<dialog id="dialog"');
 assert(start>=0&&end>start);
 let result=source.slice(0,start)+welcomeScreenView(role)+'<div id="app">'+portalLoginView({role,initial:true})+'</div>'+source.slice(end);
 result=result.replace(/<div id="omnigoWelcome"[\s\S]*?(?=<div id="omnigoWelcome")/,'');
 for(const href of ['admin-login.css','login-visibility.css','control-theme.css?v=0.9.187','corporate-design.css?v=0.9.211','welcome-screen.css'])if(!result.includes(`href="${href}"`))result=result.replace('<link rel="stylesheet" href="portal-login.css">',`<link rel="stylesheet" href="${href}"><link rel="stylesheet" href="portal-login.css">`);
 if(!result.includes('src="login-first-frame.js"'))result=result.replace('<script type="module" src="install.js">','<script src="welcome-screen.js"></script><script src="login-first-frame.js"></script><script type="module" src="install.js">');
 result=result.replace(/<dialog id="webUpdateDialog"[\s\S]*?<\/dialog>/g,'').replace(/<script src="web-update-bootstrap.js"><\/script>/g,'');
 result=result.replace('<script src="welcome-screen.js">',webUpdateMarkup()+'<script src="web-update-bootstrap.js"></script><script src="welcome-screen.js">');
 return result;
}
await fs.writeFile(new URL('../public/index.html', import.meta.url), firstFrame(entry,'client'), 'utf8');
await fs.writeFile(new URL('../public/admin-index.html', import.meta.url), firstFrame(withPreviewMetadata(admin,'admin'),'admin'), 'utf8');
console.log('Yönetici ana ekran adı ilk HTML yanıtına yerleştirildi.');
