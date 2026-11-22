import fs from 'node:fs';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
const edits=JSON.parse(fs.readFileSync('verification/localization-20261025/proposed-edits.json'));
for(const [file,list]of Object.entries(edits)){
 const source=fs.readFileSync('public/'+file,'utf8'),tree=parse(source,{ecmaVersion:'latest',sourceType:'module'}),wanted=new Set(list.filter(e=>e.kind==='text').map(e=>e.start)),rows=[];
 function walk(node,parent,ancestors=[]){
  if(!node||typeof node!=='object')return;
  if(wanted.has(node.start)&&node.type==='Literal'&&node.value.length<100){
   const p=parent?.type==='Property'?'property:'+source.slice(parent.key.start,parent.key.end):parent?.type;
   rows.push([node.value,p,ancestors.slice(-3).map(x=>x.type).join('/')]);
  }
  for(const [k,v]of Object.entries(node))if(!['start','end','loc','raw'].includes(k)){if(Array.isArray(v))v.forEach(c=>walk(c,node,[...ancestors,node]));else if(v&&typeof v==='object')walk(v,node,[...ancestors,node]);}
 }
 walk(tree);if(rows.length)console.log(file+' '+JSON.stringify(rows));
}
