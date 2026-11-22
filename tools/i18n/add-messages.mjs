import fs from 'node:fs/promises';
import path from 'node:path';
const input=process.argv[2];if(!input)throw new Error('A reviewed message file is required');
const target='verification/localization-20261025/catalog-source.json';
const source=JSON.parse(await fs.readFile(target,'utf8')),rows=[];
const ids=new Map(source.map((row,id)=>[row.source,id]));
for(const [key,en,de]of JSON.parse(await fs.readFile(input,'utf8'))){
 if(ids.has(key))continue;
 const id=source.length;source.push({source:key,files:['reviewed-presentation']});ids.set(key,id);rows.push([id,en,de]);
}
await fs.writeFile(target,JSON.stringify(source,null,2));
await fs.writeFile('tools/i18n/translations/'+path.basename(input),JSON.stringify(rows,null,1),{flag:'wx'});
console.log(JSON.stringify({added:rows.length,total:source.length}));
