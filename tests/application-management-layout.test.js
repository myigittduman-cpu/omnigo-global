import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

test('uygulama yönetimi ve oluşturucu geniş, sabit ve responsive bir pencere kullanır',()=>{
 const settings=fs.readFileSync(new URL('../public/settings-layout.css',import.meta.url),'utf8');
 const builder=fs.readFileSync(new URL('../public/application-page-builder.css',import.meta.url),'utf8');
 assert.match(settings,/width:min\(1400px,calc\(100vw - 32px\)\)/);
 assert.match(settings,/transition:none;transform:none/);
 assert.match(builder,/grid-template-rows:auto minmax\(0,1fr\) auto/);
 assert.match(builder,/\[data-page-editor-host\]\{[^}]*overflow:auto/);
 assert.match(builder,/@media\(max-width:760px\)/);
});

test('masaüstü ayar menüsü açıklamalarıyla birlikte pencereye sığar',()=>{
 const settings=fs.readFileSync(new URL('../public/settings-layout.css',import.meta.url),'utf8');
 assert.match(settings,/grid-template-columns:clamp\(225px,23vw,268px\) minmax\(0,1fr\)/);
 assert.match(settings,/\.settings-nav button\{\s*height:auto!important;min-height:48px!important;padding:7px 12px;gap:3px/);
 assert.match(settings,/\.settings-nav :is\(strong,small\)\{\s*white-space:normal;overflow:visible;overflow-wrap:anywhere/);
 assert.match(settings,/\.dialog-body:has\(>\.settings-layout\)\{display:grid;grid-template-rows:auto minmax\(0,1fr\);overflow:hidden\}/);
 assert.match(settings,/\.settings-content\{min-height:0;height:100%;overflow-y:auto/);
});
