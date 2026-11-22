import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash,randomUUID} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {CpanelClient} from '../operations/CpanelClient.mjs';
import {root,recovery,ensureRecoveryKey,unwrapKey} from './recovery-key.mjs';
import {validateSourceStatus,verifiedLocalCopy} from './backup-state.mjs';
import {acquireBackupLock} from './process-lock.mjs';
const sha=value=>createHash('sha256').update(value).digest('hex');
await fs.mkdir(recovery,{recursive:true});
const releaseLock=await acquireBackupLock(root);
let temporary;
try{
 const client=await CpanelClient.connect();
 const status=JSON.parse((await client.readPortalFile('storage/backup-status.json')).content);
 validateSourceStatus(status);
 const destination=path.join(recovery,status.file);
 let receipt;try{receipt=JSON.parse(await fs.readFile(path.join(recovery,'status.json'),'utf8'));}catch{}
 if(await verifiedLocalCopy(destination,status,receipt)){
  await fs.writeFile(path.join(recovery,'status.json'),JSON.stringify({...receipt,checkedAt:new Date().toISOString(),sourceCreatedAt:status.lastSuccess},null,2));
  await fs.rm(path.join(recovery,'last-error.json'),{force:true});
  console.log(JSON.stringify({state:'verified',reused:true,bytes:status.bytes}));
 }else{
 const manifest=JSON.parse((await client.readPortalFile('storage/backups/'+status.transport)).content);
 assert.equal(manifest.sha256,status.sha256);assert.equal(manifest.file,status.file);assert.equal(manifest.bytes,status.bytes);
 assert.equal(manifest.recovery.algorithm,'RSA-OAEP-SHA1');assert.equal(sha(await ensureRecoveryKey()),manifest.recovery.keyId,'Bu bilgisayarın kurtarma anahtarı farklı.');
 temporary=destination+'.'+randomUUID()+'.tmp';
 const output=await fs.open(temporary,'wx');const digest=createHash('sha256');let total=0;
 try{
  for(let i=0;i<manifest.parts.length;i++){
   const part=manifest.parts[i];assert.equal(part.file,status.file+'.part-'+String(i).padStart(6,'0')+'.json');
   const encoded=JSON.parse((await client.readPortalFile('storage/backups/'+part.file)).content);
   const bytes=Buffer.from(encoded.data,'base64');assert.equal(bytes.length,part.bytes);assert.equal(sha(bytes),part.sha256);
   let offset=0;while(offset<bytes.length){const written=await output.write(bytes,offset);assert.ok(written.bytesWritten);offset+=written.bytesWritten;}
   digest.update(bytes);total+=bytes.length;
  }
  await output.sync();
 }finally{await output.close();}
 assert.equal(total,status.bytes);assert.equal(digest.digest('hex'),status.sha256);
 const key=await unwrapKey(manifest.recovery.envelope);
 let verification;
 try{
  const php=process.env.PHP_BINARY||path.resolve(root,'../work/php-runtime/php.exe');
  const check=spawnSync(php,[root+'/php/bin/verify-backup.php',temporary],{input:key.toString('base64'),encoding:'utf8',windowsHide:true,timeout:300000,maxBuffer:1e6});
  assert.equal(check.status,0,'İndirilen yedeğin şifreli içerik/evrak doğrulaması başarısız.');verification=JSON.parse(check.stdout);
 }finally{key.fill(0);}
 await fs.rename(temporary,destination);temporary=null;
 await fs.writeFile(destination+'.transport.json',JSON.stringify(manifest),{mode:0o600});
 await fs.writeFile(path.join(recovery,'status.json'),JSON.stringify({state:'verified',checkedAt:new Date().toISOString(),sourceCreatedAt:status.lastSuccess,file:status.file,bytes:status.bytes,sha256:status.sha256,verification},null,2));
 await fs.rm(path.join(recovery,'last-error.json'),{force:true});
 console.log(JSON.stringify({state:'verified',bytes:status.bytes,documents:verification.documents,tables:Object.keys(verification.rows).length}));
 }
}catch(error){
 await fs.writeFile(path.join(recovery,'last-error.json'),JSON.stringify({state:'failed',checkedAt:new Date().toISOString(),message:'Yedek kopyası doğrulanamadı. Son başarılı kopya korundu.'}));
 throw error;
}finally{try{if(temporary)await fs.rm(temporary,{force:true});}finally{await releaseLock();}}
