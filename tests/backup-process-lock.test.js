import test from 'node:test';
import assert from 'node:assert/strict';
import os from 'node:os';
import path from 'node:path';
import {randomUUID} from 'node:crypto';
import {acquireBackupLock} from '../tools/backup/process-lock.mjs';
test('Yedek süreç kilidi ikinci işlemi engeller ve hata sonrası tekrar açılır',{skip:process.platform!=='win32'},async()=>{
 const root=path.join(os.tmpdir(),'omnigo-lock-test-'+randomUUID());
 const release=await acquireBackupLock(root);
 try{await assert.rejects(()=>acquireBackupLock(root),/kilidi|sürüyor/);}finally{await release();}
 const after=await acquireBackupLock(root);await after();await after();
});
