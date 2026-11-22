import test from 'node:test';
import assert from 'node:assert/strict';
import {mobileSetupError} from '../public/mobile-notifications.js';
test('Mobil bildirimin cihaz sürümü ve sunucu kurulumu ayrı doğrulanır',()=>{
 const current={kind:'android',configured:true};
 assert.equal(mobileSetupError({android:true},current),null);
 assert.match(mobileSetupError({android:true},{...current,configured:false}),/sürümünün/);
 assert.match(mobileSetupError({android:false},current),/kurulumu/);
 assert.equal(mobileSetupError({android:false,status:{android:{message:'Sunucu kurulumu bekliyor.'}}},current),'Sunucu kurulumu bekliyor.');
 assert.match(mobileSetupError({android:true},{kind:'ios',configured:true}),/kurulumu/);
});
