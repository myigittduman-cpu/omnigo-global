import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {remoteRoot} from './CpanelClient.mjs';
export const sha256=value=>crypto.createHash('sha256').update(value).digest('hex');

export class PortalFileTransfer {
 constructor(client,paths){this.client=client;this.paths=new Set(paths);}
 async write(relativePath,content){
  assert.ok(this.paths.has(relativePath)&&!relativePath.includes('..')&&!relativePath.startsWith('/'),'Yükleme yolu izinli değil.');
  const boundary=`Omnigo${crypto.randomBytes(16).toString('hex')}`;
  const body=Buffer.concat([
   Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="dir"\r\n\r\n${path.posix.join(remoteRoot,path.posix.dirname(relativePath))}\r\n`),
   Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="overwrite"\r\n\r\n1\r\n`),
   Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="file-1"; filename="${path.posix.basename(relativePath)}"\r\nContent-Type: application/octet-stream\r\n\r\n`),
   Buffer.from(content),Buffer.from(`\r\n--${boundary}--\r\n`),
  ]);
  const result=await this.client.request('/execute/Fileman/upload_files',{},body,`multipart/form-data; boundary=${boundary}`);
  assert.equal(Number(result.status),1,`Yükleme başarısız: ${relativePath}`);
  assert.equal(sha256((await this.client.readPortalFile(relativePath)).content),sha256(content),`Yüklenen dosya farklı: ${relativePath}`);
 }
}
