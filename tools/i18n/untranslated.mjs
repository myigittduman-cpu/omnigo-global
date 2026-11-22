import fs from 'node:fs/promises';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
const rows=[];
for(const file of await fs.readdir('public')){
 if(!file.endsWith('.js')||['i18n.js','sw.js','push-worker.js'].includes(file))continue;
 const source=await fs.readFile('public/'+file,'utf8');
 function visit(node,parent,ancestors=[]){
  if(!node||typeof node!=='object')return;
  if(node.type==='Literal'&&typeof node.value==='string'&&/[çğıİöşüÇĞÖŞÜ]|Kaydediliyor|Sorumlu|Bildirilmedi|Belirtilmedi|Dakika|Saniye/.test(node.value)&&!(parent?.type==='CallExpression'&&parent.callee?.name==='i18nT')&&parent?.key!==node){
   rows.push({file,start:node.start,value:node.value,parent:parent?.type,context:ancestors.slice(-3).map(n=>n.type)});
  }
  for(const [key,value]of Object.entries(node)){if(['start','end','raw','loc'].includes(key))continue;if(Array.isArray(value))value.forEach(child=>visit(child,node,[...ancestors,node]));else if(value&&typeof value==='object')visit(value,node,[...ancestors,node]);}
 }
 visit(parse(source,{ecmaVersion:'latest',sourceType:'module'}));
}
await fs.writeFile('verification/localization-20261025/untranslated-literals.json',JSON.stringify(rows,null,2));
console.log(JSON.stringify({total:rows.length,unique:new Set(rows.map(row=>row.value)).size}));
const unique=[...new Set(rows.map(row=>row.value))];console.log(JSON.stringify(unique.filter(value=>value.length<500),null,1));
