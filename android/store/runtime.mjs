import fs from 'node:fs/promises';
import path from 'node:path';
import {spawn} from 'node:child_process';
export const root=path.resolve(import.meta.dirname,'../..');
export const toolchain=path.join(root,'work/android-toolchain');
export async function soleDirectory(folder){
 const dirs=(await fs.readdir(folder,{withFileTypes:true})).filter(item=>item.isDirectory());
 if(dirs.length!==1)throw new Error('Araç klasörü belirsiz: '+folder);
 return path.join(folder,dirs[0].name);
}
export async function run(command,args,{cwd=root,env={},quiet=false}={}){
 return new Promise((resolve,reject)=>{
  const child=spawn(command,args,{cwd,windowsHide:true,env:{...process.env,...env},stdio:['ignore',quiet?'pipe':'inherit','pipe']});
  let output='',error='';child.stdout?.on('data',part=>output+=part);child.stderr.on('data',part=>error+=part);
  child.on('error',reject);child.on('exit',code=>{
   if(code!==0)reject(new Error(path.basename(command)+' başarısız: '+code+'\n'+error));
   else {if(!quiet&&error)process.stderr.write(error);resolve(output);}
  });
 });
}
export async function files(folder,extension){
 const result=[];
 for(const entry of await fs.readdir(folder,{withFileTypes:true})){
  const file=path.join(folder,entry.name);
  if(entry.isDirectory())result.push(...await files(file,extension));else if(file.endsWith(extension))result.push(file);
 }
 return result;
}
