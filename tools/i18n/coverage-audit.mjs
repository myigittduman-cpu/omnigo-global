import fs from 'node:fs/promises';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
import {catalog} from '../../public/locales/catalog.js';
const result=[];
for(const file of await fs.readdir('public')){
 if(!file.endsWith('.js')||['i18n.js'].includes(file))continue;
 const source=await fs.readFile('public/'+file,'utf8');
 function walk(node,ancestors=[]){
  if(!node||typeof node!=='object')return;
  const parent=ancestors.at(-1);
  if(node.type==='Literal'&&typeof node.value==='string'&&catalog[node.value.trim()]&&parent?.key!==node){
   const translated=ancestors.some(a=>a.type==='CallExpression'&&['i18nT','i18nHtml','localizedLabels','labelMap','labelRows','labelList'].includes(a.callee?.name));
   if(!translated)result.push({file,line:source.slice(0,node.start).split('\n').length,text:node.value,parent:parent?.type,context:source.slice(Math.max(0,node.start-25),Math.min(source.length,node.end+25))});
  }
  for(const [key,value]of Object.entries(node)){if(['start','end','raw'].includes(key))continue;if(Array.isArray(value))value.forEach(child=>walk(child,[...ancestors,node]));else if(value&&typeof value==='object')walk(value,[...ancestors,node]);}
 }
 walk(parse(source,{sourceType:'module',ecmaVersion:'latest'}));
}
await fs.writeFile('verification/localization-20261025/coverage-audit.json',JSON.stringify(result,null,2));
for(const item of result)console.log(`${item.file}:${item.line} [${item.parent}] ${JSON.stringify(item.text)} ${item.context.replaceAll('\n',' ')}`);
