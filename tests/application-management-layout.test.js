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

test('sayfa oluşturucu ortak ayar penceresini ezmeden güvenli geçişle açılır',()=>{
 const source=fs.readFileSync(new URL('../public/settings.js',import.meta.url),'utf8');
 assert.match(source,/openPageBuilder:openBuilder/);
 assert.match(source,/onBack:\(\)=>sameWorkspace\(state,workspace\)&&openSettings\(state,\{\.\.\.options,initialSection,initialScroll\}\)/);
 assert.match(source,/sameWorkspace\(state,workspace\)&&openApplicationPageBuilder/);
});

test('masaüstü ayar menüsü açıklamalarıyla birlikte pencereye sığar',()=>{
 const settings=fs.readFileSync(new URL('../public/settings-layout.css',import.meta.url),'utf8');
 assert.match(settings,/@media\(min-width:900px\) and \(min-height:601px\)[\s\S]*?\.settings-nav button\{min-height:46px;padding:6px 10px;gap:2px/);
 assert.match(settings,/\.settings-nav small\{display:block;[^}]*font-size:11px;line-height:1\.25/);
 assert.match(settings,/\.dialog-body:has\(>\.settings-layout\)\{display:grid;grid-template-rows:auto minmax\(0,1fr\);overflow:hidden\}/);
 assert.match(settings,/\.settings-content\{min-height:0;height:100%;overflow-y:auto/);
});
