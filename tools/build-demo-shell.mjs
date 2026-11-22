import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(import.meta.dirname,'..');
export async function currentDemoShell(){
 const production=await fs.readFile(path.join(root,'public/app.js'),'utf8');
 const branch="else if(me.user.role==='admin'||me.user.role==='client'||loginUpdates)loginScreen(false,'',me.user);";
 assert.equal(production.split(branch).length,2,'Giriş akışı değişti; demo üretimini güncelleyin.');
 const demo=production.replace(branch,"else if(false)loginScreen(false,'',me.user);").replaceAll("from './","from '/").replaceAll("import './","import '/");
 await fs.writeFile(path.join(root,'public/demo/app.js'),'// Generated from the current shared shell. Demo-only bootstrap; production authentication is unchanged.\n'+demo);
 const source=await fs.readFile(path.join(root,'public/index.html'),'utf8');
 const css=[...source.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*href="([^"]+)"[^>]*>/g)].map(match=>match[1]);
 assert(css.includes('interface-consistency.css'),'Ortak arayüz stili bulunamadı.');
 return css.map(file=>'<link rel="stylesheet" href="/'+file.replace(/^\//,'')+'">').join('');
}
