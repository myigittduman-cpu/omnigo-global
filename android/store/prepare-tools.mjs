import fs from 'node:fs/promises';
import {createWriteStream,createReadStream} from 'node:fs';
import {pipeline} from 'node:stream/promises';
import {Readable} from 'node:stream';
import crypto from 'node:crypto';
import path from 'node:path';
import {toolchain,soleDirectory,run} from './runtime.mjs';
const lock=JSON.parse(await fs.readFile(new URL('./toolchain-lock.json',import.meta.url)));
const jdk=await soleDirectory(path.join(toolchain,'jdk-21'));
async function hash(file,algorithm){const hash=crypto.createHash(algorithm);for await(const part of createReadStream(file))hash.update(part);return hash.digest('hex');}
for(const artifact of Object.values(lock)){
 const algorithm=artifact.sha256?'sha256':'sha1',expected=artifact[algorithm];
 const archive=path.join(toolchain,artifact.file||artifact.folder+'.zip');
 let valid=false;try{valid=await hash(archive,algorithm)===expected;}catch{}
 if(!valid){
  const response=await fetch(artifact.url,{signal:AbortSignal.timeout(180000)});
  if(!response.ok)throw new Error('Araç indirilemedi: HTTP '+response.status);
  await pipeline(Readable.fromWeb(response.body),createWriteStream(archive+'.partial'));
  if(await hash(archive+'.partial',algorithm)!==expected)throw new Error('Araç doğrulaması başarısız.');
  await fs.rename(archive+'.partial',archive);
 }
 if(artifact.folder){const destination=path.join(toolchain,artifact.folder);await fs.mkdir(destination,{recursive:true});await run(path.join(jdk,'bin/jar.exe'),['xf',archive],{cwd:destination});}
 console.log('Doğrulandı: '+(artifact.folder||artifact.file));
}
