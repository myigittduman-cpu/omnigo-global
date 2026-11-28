import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {managementHome,managementModules} from '../public/application-management.js';

test('uygulama yönetimindeki üç alanın hedefi tekil ve açıklayıcıdır',()=>{
 const supported=new Set(['design','customers','notifications']);
 assert.deepEqual(new Set(managementModules.map(item=>item[3])),supported);
 assert.equal(managementModules.length,supported.size);
 for(const [id,title,description,target,actionLabel] of managementModules){
  assert.ok(id&&title&&description&&actionLabel,`${id} alanı eksik`);
  assert.ok(supported.has(target),`${id} hedefi tanımsız`);
 }
 const markup=managementHome();
 for(const [id,,,target,actionLabel] of managementModules){
  assert.match(markup,new RegExp(`data-management-module="${id}"`));
  assert.match(markup,new RegExp(`data-management-action="${target}"`));
  assert.match(markup,new RegExp(actionLabel));
 }
});

test('kaldırılan ayarlar girişleri yoktur; diğer kategoriler korunur',async()=>{
 assert.doesNotMatch(managementHome(),/data-management-(?:action|panel)="(?:release|navigation|tables|transfers|history)"/);
 const settingsSource=await readFile(new URL('../public/settings.js',import.meta.url),'utf8');
 assert.doesNotMatch(settingsSource,/pageBuilder|openApplicationPageBuilder|data-open-page-builder/);
 assert.match(settingsSource,/management:\['Uygulama Yönetimi'/);
 assert.match(settingsSource,/history:\['Değişiklik geçmişi'/);
 assert.match(settingsSource,/transfers:\['Veri Aktarımı'/);
});
