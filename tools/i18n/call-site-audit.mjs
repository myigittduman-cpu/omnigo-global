import fs from 'node:fs/promises';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
for(const file of await fs.readdir('public')){
 if(!file.endsWith('.js'))continue;
 const source=await fs.readFile('public/'+file,'utf8');
 const walk=(n,parent)=>{if(!n||typeof n!=='object')return;
  if(n.type==='CallExpression'&&n.callee?.name==='i18nT'&&parent?.type==='CallExpression'&&!['esc','i18nT','icon','toast','i18nHtml','label','input','field','textField','select','button','toggle','title','empty','cell','stat','entry','renderEmpty','info'].includes(parent.callee?.name))console.log(file+':'+source.slice(0,n.start).split('\n').length+' '+source.slice(parent.start,parent.end).slice(0,260).replaceAll('\n',' '));
  for(const [k,v]of Object.entries(n)){if(['start','end'].includes(k))continue;if(Array.isArray(v))v.forEach(c=>walk(c,n));else if(v&&typeof v==='object')walk(v,n);}
 };walk(parse(source,{ecmaVersion:'latest',sourceType:'module'}));
}
