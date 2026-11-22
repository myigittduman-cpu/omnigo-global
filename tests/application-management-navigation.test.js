import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {managementHome,managementModules} from '../public/application-management.js';

test('uygulama yönetimindeki on alanın hedefi ve açıklayıcı eylemi vardır',()=>{
 assert.equal(managementModules.length,10);
 const supported=new Set(['design','navigation','tables','customers','transfers','notifications','release','history']);
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

test('sayfa oluşturucu kullanıcıya tam adıyla gösterilir',async()=>{
 assert.equal(managementModules.find(([id])=>id==='navigation')?.[4],'Sayfa ve Modül Oluşturucuyu aç');
 const settingsSource=await readFile(new URL('../public/settings.js',import.meta.url),'utf8');
 assert.match(settingsSource,/pageBuilder:\['Sayfa ve Modül Oluşturucu','Yeni sayfa, modül ve yayın taslakları'\]/);
 assert.match(settingsSource,/data-open-page-builder/);
});
