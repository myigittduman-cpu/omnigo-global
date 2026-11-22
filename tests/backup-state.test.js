import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {validateSourceStatus,verifiedLocalCopy} from '../tools/backup/backup-state.mjs';

test('Yedek tazeliği: eski, hatalı ve gelecekteki kayıtlar kabul edilmez',()=>{
 const now=Date.parse('2026-10-16T09:00:00Z'),file='portal-20261016-080000-1234abcd.ogbackup';
 const status={state:'verified',lastSuccess:'2026-10-16T08:00:00Z',file,transport:file+'.transport.json',bytes:12,sha256:'a'.repeat(64)};
 assert.doesNotThrow(()=>validateSourceStatus(status,now));
 for(const patch of [{lastSuccess:'2026-10-14T08:00:00Z'},{lastSuccess:'invalid'},{lastSuccess:'2026-10-17T08:00:00Z'},{state:'failed'},{bytes:-1},{file:'../private'}])assert.throws(()=>validateSourceStatus({...status,...patch},now));
});

test('Saatlik kontrol eksik veya bozulan kopyayı yeniden indirir; doğru kopyayı korur',async()=>{
 const folder=await fs.mkdtemp(path.join(os.tmpdir(),'omnigo-backup-state-')),file=path.join(folder,'sample.ogbackup'),bytes=Buffer.from('encrypted test archive');
 const status={file:'sample.ogbackup',bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')};
 const receipt={...status,state:'verified',verification:{rows:{sample:1}}};
 try{
  assert.equal(await verifiedLocalCopy(file,status,receipt),false);
  await fs.writeFile(file,bytes);assert.equal(await verifiedLocalCopy(file,status,receipt),true);
  assert.equal(await verifiedLocalCopy(file,status,{...receipt,verification:null}),false);
  const corrupted=Buffer.from(bytes);corrupted[0]^=1;await fs.writeFile(file,corrupted);
  assert.equal(await verifiedLocalCopy(file,status,receipt),false);
 }finally{const resolved=await fs.realpath(folder);assert.equal(path.dirname(resolved),await fs.realpath(os.tmpdir()));assert.ok(path.basename(resolved).startsWith('omnigo-backup-state-'));await fs.rm(resolved,{recursive:true,force:true});}
});
