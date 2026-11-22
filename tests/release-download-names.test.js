import test from 'node:test';
import assert from 'node:assert/strict';
import {releaseFilename,releaseDisposition,releaseHeaderRules,androidDownloadRules,dispositionFilename} from '../tools/operations/ReleaseDownloadNames.mjs';
test('Türkçe kurulum adları doğru çözülür; sunucu için iç tırnak kaçışı gerekmez',()=>{
 for(const role of ['admin','client'])for(const type of ['apk','setup']){
  const expected='OG '+(role==='admin'?'Yönetici Portalı':'İş Ortağım')+(type==='setup'?'.exe':'.apk');
  const header=releaseDisposition(role,type);assert.equal(releaseFilename(role,type),expected);assert.equal(dispositionFilename(header),expected);
  assert(!header.includes('\\'));assert(!header.includes('"'));assert.equal((header.match(/filename\*=/g)||[]).length,1);
  assert(releaseHeaderRules(role,type).includes('"'+header+'"'));
 }
 assert.notEqual(releaseFilename('admin','apk'),releaseFilename('client','apk'));
 assert.throws(()=>dispositionFilename('attachment; filename=\\"bad.apk\\"; filename*=UTF-8\'\'bad.apk'));
 assert.throws(()=>releaseFilename('constructor','apk'));
 const rules=androidDownloadRules();assert(rules.includes('AddType application/vnd.android.package-archive .apk'));
 assert.equal((rules.match(/Content-Disposition/g)||[]).length,2);
});
