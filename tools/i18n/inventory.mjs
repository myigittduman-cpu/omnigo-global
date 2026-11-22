import fs from 'node:fs/promises';
import path from 'node:path';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';

const root=process.cwd();
const records=[];
const visit=(node,parent,file)=>{
 if(!node||typeof node!=='object')return;
 if(node.type==='Literal'&&typeof node.value==='string'&&/[\p{L}]/u.test(node.value)){
  records.push({file,start:node.start,end:node.end,kind:'literal',parent:parent?.type,key:parent?.key===node,value:node.value});
 }
 if(node.type==='TemplateLiteral')records.push({file,start:node.start,end:node.end,kind:'template',parent:parent?.type,value:node.quasis.map((q,i)=>q.value.cooked+(i<node.expressions.length?`{${i}}`:'')).join('')});
 for(const [key,value]of Object.entries(node)){
  if(['start','end','loc','raw'].includes(key))continue;
  if(Array.isArray(value))for(const child of value)visit(child,node,file);
  else if(value&&typeof value==='object')visit(value,node,file);
 }
};
for(const file of await fs.readdir(path.join(root,'public'))){
 if(!file.endsWith('.js'))continue;
 const source=await fs.readFile(path.join(root,'public',file),'utf8');
 visit(parse(source,{ecmaVersion:'latest',sourceType:'module'}),null,file);
}
await fs.mkdir('verification/localization-20261025',{recursive:true});
await fs.writeFile('verification/localization-20261025/source-inventory.json',JSON.stringify(records,null,2));
const visible=records.filter(r=>/[çğıİöşüÇĞÖŞÜ]/.test(r.value));
console.log(JSON.stringify({modules:new Set(records.map(r=>r.file)).size,total:records.length,turkish:visible.length,unique:new Set(visible.map(r=>r.value)).size}));
