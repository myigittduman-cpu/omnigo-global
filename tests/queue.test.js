import test from 'node:test';
import assert from 'node:assert/strict';
import {openDatabase} from '../src/database.js';
import {createCompany} from '../src/repository.js';
import {SyncQueue} from '../src/sync-queue.js';
test('Kuyruk bağlantısız işi engeller; hız sınırında bekler ve tekrarlar',async()=>{
 const db=openDatabase(':memory:');const company=createCompany(db,'Test');let now=10000;const adapters=new Map();const queue=new SyncQueue(db,adapters,()=>now);
 try{queue.enqueue(company,'n11');await queue.runNext();assert.equal(db.prepare('SELECT state FROM jobs').get().state,'blocked');
 let calls=0;adapters.set('n11',{intervalMs:60000,async syncReadOnly(){calls++;if(calls===1)throw Object.assign(new Error('secret must not be logged'),{status:429,retryAfterMs:30000});}});
 queue.enqueue(company,'n11');await queue.runNext();assert.equal(db.prepare('SELECT next_run FROM jobs').get().next_run,40000);assert.equal(await queue.runNext(),false);now=40000;await queue.runNext();assert.equal(calls,2);assert.equal(db.prepare('SELECT attempts FROM jobs').get().attempts,0);
 }finally{db.close();}
});
