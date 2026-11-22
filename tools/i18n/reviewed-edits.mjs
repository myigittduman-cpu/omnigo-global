// Apply only exact, individually reviewed presentation edits. No inference.
import fs from 'node:fs/promises';
import path from 'node:path';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
export async function edit(file,pairs){
 const target=path.join('public',file),original=await fs.readFile(target,'utf8');
 let source=original;
 for(const [before,after]of pairs){
  if(!source.includes(before))throw new Error(`Missing exact source in ${file}: ${before}`);
  source=source.replaceAll(before,after);
 }
 parse(source,{ecmaVersion:'latest',sourceType:'module'});
 const directory='verification/localization-20261025/reviewed-edits';await fs.mkdir(directory,{recursive:true});
 await fs.writeFile(path.join(directory,`${file}.${Date.now()}.bak`),original,{flag:'wx'});
 await fs.writeFile(target,source);
 console.log(file);
}
