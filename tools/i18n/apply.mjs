import fs from 'node:fs/promises';
import path from 'node:path';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
const folder='verification/localization-20261025';
const edits=JSON.parse(await fs.readFile(`${folder}/proposed-edits.json`,'utf8'));
const before=`${folder}/before-codemod`;
await fs.mkdir(before,{recursive:true});
const parseSource=source=>parse(source,{ecmaVersion:'latest',sourceType:'module'});
function normalized(node){
 if(Array.isArray(node))return node.filter(x=>!(x?.type==='ImportDeclaration'&&x.source.value==='./i18n.js')).map(normalized);
 if(!node||typeof node!=='object')return node;
 if(node.type==='CallExpression'&&['i18nT','i18nHtml'].includes(node.callee?.name)&&node.arguments.length===1)return normalized(node.arguments[0]);
 if(node.type==='TaggedTemplateExpression'&&['i18nText','i18nHtml'].includes(node.tag?.name))return normalized(node.quasi);
 return Object.fromEntries(Object.entries(node).filter(([key])=>!['start','end','loc','raw'].includes(key)).map(([key,value])=>[key,normalized(value)]));
}
const report=[];
for(const [file,changes]of Object.entries(edits)){
 const target=path.join('public',file),source=await fs.readFile(target,'utf8');
 if(source.includes('i18nHtml as')||source.includes('html as i18nHtml'))throw new Error(`Already localized: ${file}`);
 await fs.writeFile(path.join(before,file),source,{flag:'wx'});
 let result=source;let boundary=source.length;
 for(const change of [...changes].sort((a,b)=>b.start-a.start||b.end-a.end)){
  if(change.end>boundary)throw new Error(`Overlapping changes: ${file}`);
  result=result.slice(0,change.start)+change.value+result.slice(change.end);boundary=change.start;
 }
 result="import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';\n"+result;
 if(JSON.stringify(normalized(parseSource(source)))!==JSON.stringify(normalized(parseSource(result))))throw new Error(`Non-presentation AST change: ${file}`);
 await fs.writeFile(target,result);report.push({file,edits:changes.length,astEquivalent:true});
}
await fs.writeFile(`${folder}/codemod-audit.json`,JSON.stringify(report,null,2));
console.log(JSON.stringify({files:report.length,allBusinessSyntaxUnchanged:true}));
