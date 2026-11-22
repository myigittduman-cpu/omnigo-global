import fs from 'node:fs/promises';
import path from 'node:path';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';

const root=process.cwd(),folder=path.join(root,'verification/localization-20261025');
const technical=new Set(['tr-TR','sv-SE','Türkiye','Türkçe','Ankara','Eskişehir','Kayseri','Kırıkkale','Kırşehir','Nevşehir','Koçtaş','İdefix','Hepsiburada','PTT AVM','PttAVM','Pazarama','Trendyol','Teknosa','Amazon','n11','idefix','İptal Edildi','Sevk Edildi','Faturası Kesildi','Sipariş Tamamlandı','Sevk bekliyor']);
const candidates=new Map(),editsByFile={};
const prior=JSON.parse(await fs.readFile(path.join(folder,'catalog-source.json'),'utf8').catch(()=>'[]'));
for(const row of prior)candidates.set(row.source,new Set(row.files));
function collect(value,file){
 const key=value.trim();
 if(!key||!/[\p{L}]/u.test(key)||technical.has(key)||/^[\W\d_{}]+$/u.test(key))return;
 if(!candidates.has(key))candidates.set(key,new Set());candidates.get(key).add(file);
}
function collectMarkup(source,file){
 for(const token of source.match(/<[^>]*>|[^<]+/g)||[]){
  if(token.startsWith('<'))for(const match of token.matchAll(/\b(?:title|aria-label|placeholder|alt)=(['"])(.*?)\1/g))collect(match[2],file);
  else collect(token,file);
 }
}
const human=value=>candidates.has(value.trim())||/[çğıİöşüÇĞÖŞÜ]/.test(value)||/\b(Kaydet|Vazgeç|Tarih|Tutar|Adet|Hesap|Adres|Liste|Form|Telefon|Destek|Panel|Yeni|Evet|Hayır|Sil|Aktif|Pasif|Durum|Rapor|Mesaj|PDF|Excel|Analiz|raporlar|Bekleniyor|Devam|Ocak|Mart|Nisan|Haziran|Temmuz|Ekim|Pzt|Sal|Per|Cum|Cmt|Paz)\b/.test(value);
function visit(node,parent,ancestors,source,file,edits){
 if(!node||typeof node!=='object')return;
 const inFunction=ancestors.some(n=>/Function/.test(n.type));
 const ignored=['ImportDeclaration','ExportNamedDeclaration'].includes(parent?.type)&&node.type==='Literal';
 if(node.type==='Literal'&&typeof node.value==='string'&&!ignored){
  const value=node.value,markup=/<\/?[a-z][^>]*>/i.test(value);
  const protectedValue=technical.has(value)||parent?.key===node||parent?.type==='BinaryExpression'||parent?.type==='ImportDeclaration'||parent?.type==='MemberExpression'||parent?.type==='SwitchCase';
  if(markup){collectMarkup(value,file);edits.push({start:node.start,end:node.end,value:`i18nHtml(${source.slice(node.start,node.end)})`,kind:'html'});}
  else if(human(value)&&!protectedValue&&inFunction&&!/^(?:https?:|\/|\^|\[data-)/.test(value)){
   collect(value,file);edits.push({start:node.start,end:node.end,value:`i18nT(${source.slice(node.start,node.end)})`,kind:'text'});
  }else if(human(value)&&!protectedValue)collect(value,file);
 }
 if(node.type==='TemplateLiteral'&&parent?.type!=='TaggedTemplateExpression'){
  const combined=node.quasis.map((q,i)=>q.value.cooked+(i<node.expressions.length?`{${i}}`:'')).join('');
  const markup=/<\/?[a-z][^>]*>/i.test(combined);
  if(markup||human(combined)){
   if(markup)collectMarkup(combined,file);else collect(combined,file);
   edits.push({start:node.start,end:node.start,value:markup?'i18nHtml':'i18nText',kind:'tag'});
  }
 }
 for(const [key,value]of Object.entries(node)){
  if(['start','end','loc','raw'].includes(key))continue;
  if(Array.isArray(value))for(const child of value)visit(child,node,[...ancestors,node],source,file,edits);
  else if(value&&typeof value==='object')visit(value,node,[...ancestors,node],source,file,edits);
 }
}
await fs.mkdir(folder,{recursive:true});
for(const file of await fs.readdir(path.join(root,'public'))){
 if(!file.endsWith('.js')||file==='i18n.js'||file==='sw.js'||file==='push-worker.js')continue;
 const source=await fs.readFile(path.join(root,'public',file),'utf8'),edits=[];
 visit(parse(source,{ecmaVersion:'latest',sourceType:'module'}),null,[],source,file,edits);
 if(edits.length)editsByFile[file]=edits;
}
await fs.writeFile(path.join(folder,'proposed-edits.json'),JSON.stringify(editsByFile,null,2));
await fs.writeFile(path.join(folder,'catalog-source.json'),JSON.stringify([...candidates].map(([source,files])=>({source,files:[...files]})),null,2));
console.log(JSON.stringify({files:Object.keys(editsByFile).length,edits:Object.values(editsByFile).reduce((n,e)=>n+e.length,0),messages:candidates.size}));
