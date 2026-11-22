import fs from 'node:fs/promises';
import {catalog} from '../../public/locales/catalog.js';
const rows=[];
for(const file of await fs.readdir('public')){
 if(!file.endsWith('.html'))continue;
 const source=(await fs.readFile('public/'+file,'utf8')).replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'').replace(/<style\b[^>]*>[\s\S]*?<\/style>/g,'');
 for(const match of source.matchAll(/>([^<>]+)</g)){
  const text=match[1].trim();if(!text||catalog[text]||!/[a-zA-ZçğıöşüÇĞİÖŞÜ]/.test(text)||/^admin@omnigoglobal\.com$/.test(text))continue;
  if(!rows.some(row=>row.text===text))rows.push({file,text});
 }
}
await fs.writeFile('verification/localization-20261025/static-missing.json',JSON.stringify(rows,null,2));
rows.forEach((row,index)=>console.log(index+' '+row.file+' '+row.text));
