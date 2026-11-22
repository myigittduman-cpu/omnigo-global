import fs from 'node:fs/promises';import crypto from 'node:crypto';import assert from 'node:assert/strict';import {spawnSync} from 'node:child_process';
import {CpanelClient,remoteRoot} from '../operations/CpanelClient.mjs';import {PortalFileTransfer} from '../operations/PortalFileTransfer.mjs';
const c=await CpanelClient.connect(),folder='data/store-review',helper='store-review-features.php',command=`/usr/local/bin/php ${remoteRoot}/${helper} >/dev/null 2>&1`;
const worker=`/usr/local/bin/php ${remoteRoot}/store-review/runtime/review-push-worker.php >/dev/null 2>&1`;
const sha=value=>crypto.createHash('sha256').update(value).digest('hex');
const normalize=jobs=>jobs.map(j=>JSON.stringify([j.minute,j.hour,j.day,j.month,j.weekday,j.command])).sort();
if(process.argv[2]==='start'){
 const tests=JSON.parse(await fs.readFile('verification/store-review/local-tests.json','utf8'));assert(tests.checks>=87&&tests.results.every(r=>r.passed));
 const mapping={'src/StoreReviewGateway.php':'php/src/StoreReviewGateway.php','store-review/runtime/src/StoreReviewPolicy.php':'php/src/StoreReviewPolicy.php','store-review/runtime/src/AccountDeletionRequest.php':'php/src/AccountDeletionRequest.php','store-review/runtime/src/StoreReviewDeletionRequest.php':'php/src/StoreReviewDeletionRequest.php','store-review/runtime/review-push-worker.php':'tools/store/review-push-worker.php'};
 const plan={files:{}},before={};
 for(const [remote,local]of Object.entries(mapping)){
  const dir=remote.slice(0,remote.lastIndexOf('/')),name=remote.slice(remote.lastIndexOf('/')+1);
  const entries=await c.uapi('Fileman','list_files',{dir:remoteRoot+'/'+dir,include_mime:0,include_hash:0});
  const prior=entries.some(item=>item.file===name)?(await c.readPortalFile(remote)).content:null;before[remote]=prior;
  const content=await fs.readFile(local);plan.files[remote]={before:prior===null?null:sha(prior),sha256:sha(content),content:content.toString('base64')};
 }
 const key=crypto.randomBytes(32),iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv),plain=Buffer.from(JSON.stringify(before)),encrypted=Buffer.concat([cipher.update(plain),cipher.final()]);
 const protectedKey=spawnSync('powershell.exe',['-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File','tools/backup/protect.ps1','-Mode','Protect'],{input:key.toString('base64'),encoding:'utf8',windowsHide:true});assert.equal(protectedKey.status,0);
 await fs.writeFile(folder+'/features-before.ogsource',Buffer.concat([iv,cipher.getAuthTag(),encrypted]),{flag:'wx'});await fs.writeFile(folder+'/features-key.dpapi',Buffer.from(protectedKey.stdout.trim(),'base64'),{flag:'wx'});
 const decode=crypto.createDecipheriv('aes-256-gcm',key,iv);decode.setAuthTag(cipher.getAuthTag());assert.deepEqual(Buffer.concat([decode.update(encrypted),decode.final()]),plain);key.fill(0);
 const transfer=new PortalFileTransfer(c,['storage/store-review-feature-plan.json',helper]);await transfer.write('storage/store-review-feature-plan.json',JSON.stringify(plan));await transfer.write(helper,await fs.readFile('tools/store/enable-review-features.php'));
 await c.api2('Cron','add_line',{minute:'*',hour:'*',day:'*',month:'*',weekday:'*',command});console.log('Yedek doğrulandı; yalnız örnek bildirim ve talep alanı hazırlanıyor.');
}else if(process.argv[2]==='finish'){
 const result=JSON.parse((await c.readPortalFile('storage/store-review-features.json')).content);
 for(const job of(await c.api2('Cron','listcron')).filter(j=>j.command===command))await c.api2('Cron','remove_line',{line:job.count});
 for(const name of ['storage/store-review-features.json','storage/store-review-features.lock'])await c.api2('Fileman','fileop',{op:'unlink',sourcefiles:remoteRoot+'/'+name,doubledecode:0});
 assert.equal(result.state,'enabled',result.error);
 const jobs=await c.api2('Cron','listcron');assert.deepEqual(normalize(jobs),normalize(JSON.parse(await fs.readFile(folder+'/crons-before.json','utf8'))));
 await c.api2('Cron','add_line',{minute:'*',hour:'*',day:'*',month:'*',weekday:'*',command:worker});
 const after=await c.api2('Cron','listcron');assert(after.some(j=>j.command===worker));assert.equal(after.length,jobs.length+1);
 for(const [file,hash]of Object.entries(result.hashes))assert.equal(sha((await c.readPortalFile(file)).content),hash);
 await fs.writeFile(folder+'/features-deployment.json',JSON.stringify({...result,temporaryTaskRemoved:true,isolatedPushWorkerAdded:true,existingCronsPreserved:true},null,2));
 console.log(JSON.stringify({state:result.state,androidReady:result.androidReady,newCredentialCreated:false,temporaryTaskRemoved:true,isolatedPushWorkerAdded:true,existingCronsPreserved:true}));
}else throw Error('start veya finish seçin.');
