import fs from 'node:fs/promises';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {generateKeyPairSync,createPublicKey,privateDecrypt,constants} from 'node:crypto';
export const root=path.resolve(import.meta.dirname,'../..');
export const recovery=path.join(root,'data/recovery');
function protect(mode,value){
 const result=spawnSync('powershell.exe',['-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',path.join(import.meta.dirname,'protect.ps1'),'-Mode',mode],{input:value.toString('base64'),encoding:'utf8',windowsHide:true,timeout:15000});
 if(result.status!==0)throw new Error('Windows kurtarma anahtarı açılamadı veya korunamadı.');
 return Buffer.from(result.stdout.trim(),'base64');
}
export async function ensureRecoveryKey(){
 await fs.mkdir(recovery,{recursive:true});
 const file=path.join(recovery,'private-key.dpapi');
 try{await fs.access(file);}catch{
  const pair=generateKeyPairSync('rsa',{modulusLength:3072,publicKeyEncoding:{type:'spki',format:'pem'},privateKeyEncoding:{type:'pkcs8',format:'der'}});
  await fs.writeFile(file,protect('Protect',pair.privateKey),{flag:'wx',mode:0o600});pair.privateKey.fill(0);
 }
 const privateKey=protect('Unprotect',await fs.readFile(file));
 try{return createPublicKey({key:privateKey,type:'pkcs8',format:'der'}).export({type:'spki',format:'pem'});}finally{privateKey.fill(0);}
}
export async function unwrapKey(envelope){
 const privateKey=protect('Unprotect',await fs.readFile(path.join(recovery,'private-key.dpapi')));
 try{return privateDecrypt({key:privateKey,type:'pkcs8',format:'der',padding:constants.RSA_PKCS1_OAEP_PADDING,oaepHash:'sha1'},Buffer.from(envelope,'base64'));}finally{privateKey.fill(0);}
}
