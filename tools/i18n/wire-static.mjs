import fs from 'node:fs/promises';
import {edit} from './reviewed-edits.mjs';
import {catalog} from '../../public/locales/catalog.js';
for(const file of ['app.js','account-deletion.js','account-security.js','notification-settings.js','android-install.js','hepsiburada-setup.js']){
 const source=await fs.readFile('public/'+file,'utf8');
 await edit(file,[[source.split('\n')[0],"import './static-i18n.js';\n"+source.split('\n')[0]]]);
}
for(const file of ['privacy.html','support.html']){
 const target='public/'+file,source=await fs.readFile(target,'utf8');
 await fs.writeFile('verification/localization-20261025/reviewed-edits/'+file+'.bak',source,{flag:'wx'});
 await fs.writeFile(target,source.replace('</body>','<script type="module" src="/static-i18n.js"></script></body>'));
}
// The already-cached offline document must translate without a network/module fetch.
const offline='public/offline.html',source=await fs.readFile(offline,'utf8');
const messages=Object.fromEntries([...source.matchAll(/>([^<>]+)</g)].map(m=>m[1].trim()).filter(key=>catalog[key]).map(key=>[key,catalog[key]]));
const script=`<script>(()=>{let language='tr';try{language=localStorage.getItem('omnigo.language')||'tr';}catch{}if(!['en','de'].includes(language))return;const messages=${JSON.stringify(messages)};document.documentElement.lang=language;for(const element of document.querySelectorAll('title,h1,p,a')){const translated=messages[element.textContent.trim()]?.[language];if(translated)element.textContent=translated;}})();</script>`;
await fs.writeFile('verification/localization-20261025/reviewed-edits/offline.html.bak',source,{flag:'wx'});
await fs.writeFile(offline,source.replace('</html>',script+'</html>'));
