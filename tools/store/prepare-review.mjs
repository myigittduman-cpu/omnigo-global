import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';

const root=path.resolve(import.meta.dirname,'../..');
const runtime=path.join(root,'work',process.env.OMNIGO_REVIEW_DIR||'store-review');
if(path.dirname(runtime)!==path.join(root,'work'))throw new Error('Yerel inceleme yolu geçersiz.');
await fs.mkdir(runtime,{recursive:true});
if(await fs.stat(path.join(runtime,'config.php')).catch(()=>null))throw new Error('Mevcut inceleme ortamının üzerine yazılmaz.');
const snapshot={createdAt:new Date().toISOString(),files:{}};
async function walk(folder){for(const item of await fs.readdir(path.join(root,folder),{withFileTypes:true})){
 const relative=path.join(folder,item.name);if(item.isDirectory()){if(!['target','build','node_modules','.gradle','.git'].includes(item.name))await walk(relative);}
 else snapshot.files[relative.replaceAll('\\','/')]=crypto.createHash('sha256').update(await fs.readFile(path.join(root,relative))).digest('hex');
}}
for(const folder of ['public','php/src','android','desktop','store'])await walk(folder);
await fs.writeFile(path.join(runtime,'source-before.json'),JSON.stringify(snapshot,null,2));
await fs.cp(path.join(root,'store'),path.join(runtime,'store-before'),{recursive:true});
await fs.cp(path.join(root,'public'),path.join(runtime,'public'),{recursive:true});
for(const folder of ['src','vendor'])await fs.cp(path.join(root,'php',folder),path.join(runtime,folder),{recursive:true});
await fs.copyFile(path.join(root,'php/bootstrap.php'),path.join(runtime,'bootstrap.php'));
await fs.copyFile(path.join(root,'php/public/api.php'),path.join(runtime,'public/api.php'));
await fs.mkdir(path.join(runtime,'storage'),{recursive:true});
await fs.writeFile(path.join(runtime,'storage/backup.key'),crypto.randomBytes(32));
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
await fs.writeFile(path.join(runtime,'config.php'),`<?php return ['origin'=>'http://127.0.0.1:8793','portals'=>['client'=>'http://127.0.0.1:8793','admin'=>'http://127.0.0.1:8794'],'database'=>['dsn'=>${literal('sqlite:'+path.join(runtime,'review.sqlite'))}],'setup_token_hash'=>''];`);
await fs.writeFile(path.join(runtime,'router.php'),`<?php $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if(str_starts_with($path,'/api/')){require __DIR__.'/public/api.php'; return true;} return false;`);
const php=path.resolve(root,'../work/php-runtime/php.exe');
const result=spawnSync(php,['-d',`extension_dir=${path.dirname(php)}/ext`,'-d','extension=fileinfo',path.join(root,'tools/store/seed-review.php'),runtime,root],{encoding:'utf8'});
if(result.status!==0)throw new Error(result.stderr+result.stdout);
console.log(result.stdout.trim());
console.log(JSON.stringify({runtime,client:'http://127.0.0.1:8793',admin:'http://127.0.0.1:8794',productionChanged:false}));
