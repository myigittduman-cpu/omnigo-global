import fs from 'node:fs/promises';
import {createReadStream} from 'node:fs';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';

export function validateSourceStatus(status, now=Date.now()) {
 const created=Date.parse(status.lastSuccess);
 assert.equal(status.state,'verified','Sunucudaki son yedek başarılı değil.');
 assert.ok(Number.isFinite(created)&&now-created<36*3600000&&created<=now+300000,'Yedek tarihi geçersiz veya 36 saatten eski.');
 assert.match(status.file,/^portal-\d{8}-\d{6}-[a-f0-9]{8}\.ogbackup$/);
 assert.match(status.sha256,/^[a-f0-9]{64}$/);
 assert.ok(Number.isSafeInteger(status.bytes)&&status.bytes>0,'Yedek boyutu geçersiz.');
 assert.equal(status.transport,status.file+'.transport.json');
}

// A matching receipt alone is insufficient: verify the stored bytes before reusing it.
export async function verifiedLocalCopy(destination,status,receipt) {
 if(receipt?.state!=='verified'||receipt.file!==status.file||receipt.sha256!==status.sha256||!receipt.verification)return false;
 try {
  const metadata=await fs.stat(destination);
  if(!metadata.isFile()||metadata.size!==status.bytes)return false;
  const hash=createHash('sha256');let bytes=0;
  for await(const chunk of createReadStream(destination)){bytes+=chunk.length;hash.update(chunk);}
  return bytes===status.bytes&&hash.digest('hex')===status.sha256;
 }catch{return false;}
}
