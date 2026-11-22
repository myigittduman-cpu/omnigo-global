import test from 'node:test';
import assert from 'node:assert/strict';
import {pazaramaStatus} from '../public/pazarama-status.js';

test('Duran kontroller planlanmış veya sırada olarak gösterilmez',()=>{
 for(const state of ['failed','blocked']) {
  const status=pazaramaStatus({configured:true,job:{state,next_run:1791400779,error:'Veri alınamadı'}});
  assert.equal(status.scheduled,false);
  assert.match(status.heading,/durdu|doğrulanamadı/);
 }
 const retry=pazaramaStatus({configured:true,job:{state:'pending',error:'Veri alınamadı'}});
 assert.equal(retry.scheduled,true);
 assert.match(retry.heading,/yeniden denenecek/);
 const running=pazaramaStatus({configured:true,job:{state:'running'}});
 assert.equal(running.scheduled,false);
 assert.match(running.heading,/sürüyor/);
 const ready=pazaramaStatus({configured:true,sync:{phase:'ready',salesPublished:true},job:{state:'pending'}});
 assert.equal(ready.scheduled,true);
 assert.match(ready.heading,/aktarımı etkin/);
 assert.equal(pazaramaStatus({configured:false}).scheduled,false);
});
