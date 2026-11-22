import fs from 'node:fs/promises';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
import {catalog} from '../../public/locales/catalog.js';
const found=new Map();
function add(source,file){const key=source.trim();if(!key||catalog[key]||!/[\p{L}]/u.test(key))return;if(!found.has(key))found.set(key,new Set());found.get(key).add(file);}
function markup(value,file){for(const part of value.match(/<[^>]*>|[^<]+/g)||[]){if(part.startsWith('<')){for(const m of part.matchAll(/\b(?:title|aria-label|placeholder|alt|data-label)=(['"])(.*?)\1/g))add(m[2],file);}else add(part,file);}}
for(const file of await fs.readdir('public')){
 if(!file.endsWith('.js')||file==='i18n.js')continue;
 const source=await fs.readFile('public/'+file,'utf8');
 function walk(n){if(!n||typeof n!=='object')return;
  if(n.type==='TaggedTemplateExpression'&&['i18nText','i18nHtml'].includes(n.tag.name)){const value=n.quasi.quasis.map((q,i)=>q.value.cooked+(i<n.quasi.expressions.length?`{${i}}`:'')).join('');if(n.tag.name==='i18nHtml')markup(value,file);else add(value,file);}
  if(n.type==='CallExpression'&&['i18nT','i18nHtml'].includes(n.callee.name)&&typeof n.arguments[0]?.value==='string'){if(n.callee.name==='i18nHtml')markup(n.arguments[0].value,file);else add(n.arguments[0].value,file);}
  for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(walk);else if(v&&typeof v==='object')walk(v);}
 }walk(parse(source,{ecmaVersion:'latest',sourceType:'module'}));
}
const rows=[...found].map(([source,files])=>({source,files:[...files]}));
await fs.writeFile('verification/localization-20261025/missing-messages.json',JSON.stringify(rows,null,2));
rows.forEach((row,id)=>console.log(id+' '+row.source));
const errors=new Map();
for(const file of await fs.readdir('php/src')){
 if(!file.endsWith('.php'))continue;const source=await fs.readFile('php/src/'+file,'utf8');
 for(const match of source.matchAll(/Http::fail\(\s*\d+\s*,\s*'((?:[^'\\]|\\.)*)'\s*\)/g)){const text=match[1].replaceAll("\\'","'");if(!catalog[text]&&!errors.has(text))errors.set(text,file);}
}
await fs.writeFile('verification/localization-20261025/server-errors.json',JSON.stringify([...errors].map(([source,file])=>({source,file})),null,2));
console.log('Server errors needing translation: '+errors.size);
