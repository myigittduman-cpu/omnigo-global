import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
const sharp=require(process.env.SHARP_MODULE||path.join(os.homedir(),'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/sharp'));
const root=path.resolve(import.meta.dirname,'../..');
const release=process.env.OMNIGO_STORE_RELEASE||'20261016';
if(!/^\d{8}$/.test(release))throw new Error('Geçersiz yayın tarihi.');
const output=path.resolve(root,'../outputs/Omnigo-Stores/google-play-'+release);
const manifest=JSON.parse(await fs.readFile(path.join(output,'manifest.json'),'utf8'));
const result={checkedAt:new Date().toISOString(),files:[],packages:[],sourceUnchanged:true,sourceChanges:[]};
for(const [role,app] of Object.entries(manifest.apps)){
 const entries=[{file:'icon-512.png',width:512,height:512},app.feature,...app.screenshots];
 for(const item of entries){
  const file=path.join(output,role,item.file),bytes=await fs.readFile(file);
  // Browser screenshots can return JPEG bytes. Encode real PNGs without changing their pixels or dimensions.
  const encoded=await (item.file==='icon-512.png'?sharp(bytes).ensureAlpha():sharp(bytes).removeAlpha()).png().toBuffer();
  const meta=await sharp(encoded).metadata();
  if(meta.width!==item.width||meta.height!==item.height)throw new Error(role+'/'+item.file+': beklenmeyen ölçü '+meta.width+'x'+meta.height);
  if(item.file==='icon-512.png'&&encoded.length>1024*1024)throw new Error('Simge 1 MB sınırını aşıyor.');
  if(item.file!=='icon-512.png'&&meta.hasAlpha)throw new Error('Mağaza görselinde alfa kanalı olmamalı.');
  await fs.writeFile(file,encoded);
  result.files.push({file:role+'/'+item.file,width:meta.width,height:meta.height,format:meta.format,bytes:encoded.length,hasAlpha:meta.hasAlpha,sha256:crypto.createHash('sha256').update(encoded).digest('hex')});
 }
 const artifactRoot=path.resolve(root,'../outputs/Omnigo-Stores/android-push',role);
 const validation=JSON.parse(await fs.readFile(path.join(artifactRoot,'validation.json'),'utf8'));
 for(const extension of ['aab','apk']){
  const files=(await fs.readdir(artifactRoot)).filter(f=>f.endsWith(`-${validation.version}-play.${extension}`));
  if(files.length!==1)throw new Error('Android paketi belirsiz.');
  const file=path.join(artifactRoot,files[0]);
  const hash=crypto.createHash('sha256').update(await fs.readFile(file)).digest('hex');
  if(hash!==(extension==='aab'?validation.sha256:validation.apkSha256))throw new Error('İmzalı paket özeti değişmiş.');
  result.packages.push({role,extension,path:file,version:validation.version,versionCode:validation.versionCode,sha256:hash,unchanged:true});
 }
}
const baseline=JSON.parse(await fs.readFile(path.join(root,'work',process.env.OMNIGO_REVIEW_DIR||'store-review','source-before.json'),'utf8'));
for(const [name,hash] of Object.entries(baseline.files)){
 if(name.startsWith('store/'))continue;
 const current=crypto.createHash('sha256').update(await fs.readFile(path.join(root,name))).digest('hex');
 if(current!==hash)result.sourceChanges.push(name);
}
result.sourceUnchanged=result.sourceChanges.length===0;
if(!result.sourceUnchanged)throw new Error('Uygulama kaynaklarında değişiklik var: '+result.sourceChanges.join(', '));
await fs.writeFile(path.join(output,'asset-validation.json'),JSON.stringify(result,null,2));
const gallery=`<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Omnigo Google Play hazırlık paketi</title><style>body{background:#0e1724;color:#f5f5f7;font-family:Segoe UI,Arial;margin:0;padding:38px}h1{margin:0;color:#d0ba7e}p{color:#c0cad7;max-width:1100px}a{color:#d0ba7e}.covers{display:grid;grid-template-columns:1fr 1fr;gap:24px}.covers img{width:100%;border:1px solid #405066;border-radius:12px}.shots{display:flex;gap:18px;align-items:flex-start;margin:22px 0 40px}.shots a{flex:1;min-width:0}.shots img{width:100%;border:1px solid #405066;border-radius:10px}.landscape{display:block;max-width:750px;margin:24px auto}.landscape img{width:100%}@media(max-width:800px){.covers{grid-template-columns:1fr}.shots{display:grid;grid-template-columns:1fr 1fr}}small{color:#aab9cd}</style><h1>Google Play mağaza paketi</h1><p>Müşteri ve yönetici için ayrı Türkçe metinler, simgeler ve görseller. Ekranlar yerel portalın gerçek arayüzü ve yalnızca örnek verilerle hazırlandı; fiziksel Android ekran kaydı değildir. Yayın öncesi son Android görünümüyle eşleştirilmelidir.</p><div class="covers">${['client','admin'].map(r=>`<a href="${r}/feature-1024x500.png"><img src="${r}/feature-1024x500.png"></a>`).join('')}</div>${Object.entries(manifest.apps).map(([r,a])=>`<h2>${a.title}</h2><p><a href="${r}/full-description.txt">Mağaza açıklaması</a> · <a href="${r}/short-description.txt">Kısa açıklama</a> · <a href="${r}/icon-512.png">Uygulama simgesi</a></p><div class="shots">${a.screenshots.filter(s=>s.height>s.width).map(s=>`<a href="${r}/${s.file}"><img alt="${s.alt}" src="${r}/${s.file}"></a>`).join('')}</div>${a.screenshots.filter(s=>s.width>s.height).map(s=>`<a class="landscape" href="${r}/${s.file}"><img alt="${s.alt}" src="${r}/${s.file}"></a>`).join('')}`).join('')}<small>16 Ekim 2026 · Henüz Google Play'e yüklenmedi. Ayrıntılar: YAYIN-KONTROLU.txt</small></html>`;
await fs.writeFile(path.join(output,'ONIZLEME.html'),gallery.replace('16 Ekim 2026',`${release.slice(6,8)} Ekim ${release.slice(0,4)}`));
console.log(JSON.stringify({assets:result.files.length,packageHashes:result.packages.length,sourceUnchanged:result.sourceUnchanged,output}));
