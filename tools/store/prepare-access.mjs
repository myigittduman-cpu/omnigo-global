import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';

export const root=path.resolve(import.meta.dirname,'../..');
const area=path.join(root,'work/store-review-access'),runtime=path.join(area,'store-review/runtime');
const privateFolder=path.join(root,'data/store-review');
const php=path.resolve(root,'../work/php-runtime/php.exe');
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
if(await fs.stat(path.join(runtime,'review.sqlite')).catch(()=>null))throw Error('Mevcut inceleme ortamı korunuyor.');
await fs.mkdir(runtime,{recursive:true});await fs.mkdir(privateFolder,{recursive:true});
const credentials={admin:{email:'review-admin@omnigoglobal.com',password:crypto.randomBytes(24).toString('base64url')},client:{email:'review-client@omnigoglobal.com',password:crypto.randomBytes(24).toString('base64url')}};
await fs.writeFile(path.join(privateFolder,'access.json'),JSON.stringify(credentials),{flag:'wx',mode:0o600});
await fs.writeFile(path.join(privateFolder,'INCELEME-GIRISLERI.txt'),`ÖZEL — Yalnızca mağazanın App Access / App Review Information alanına girin.\nBu dosyayı mağaza görsellerine veya herkese açık bir bağlantıya eklemeyin.\n\nYÖNETİCİ\nAdres: https://yonetim.omnigoglobal.com/\nGiriş ekranında “E-posta ile giriş” seçin.\nE-posta: ${credentials.admin.email}\nŞifre: ${credentials.admin.password}\n\nMÜŞTERİ\nAdres: https://portal.omnigoglobal.com/\nE-posta: ${credentials.client.email}\nŞifre: ${credentials.client.password}\n\nİki hesap da yalnız örnek verili ayrı ortamı açar. İki aşamalı doğrulama gerekmez.\n`,{flag:'wx',mode:0o600});
for(const target of [area,runtime]){
 for(const folder of ['src','vendor'])await fs.cp(path.join(root,'php',folder),path.join(target,folder),{recursive:true});
 await fs.copyFile(path.join(root,'php/bootstrap.php'),path.join(target,'bootstrap.php'));
 await fs.mkdir(path.join(target,'storage'),{recursive:true});
 await fs.writeFile(path.join(target,'storage/backup.key'),crypto.randomBytes(32));
}
await fs.cp(path.join(root,'public'),path.join(area,'public'),{recursive:true});
await fs.copyFile(path.join(root,'php/public/api.php'),path.join(area,'public/api.php'));
await fs.copyFile(path.join(root,'tools/store/review-entry.php'),path.join(runtime,'entry.php'));
const portals="['client'=>'http://127.0.0.1:8823','admin'=>'http://127.0.0.1:8824']";
await fs.writeFile(path.join(runtime,'config.php'),`<?php return ['origin'=>'http://127.0.0.1:8823','portals'=>${portals},'store_review'=>true,'database'=>['dsn'=>${literal('sqlite:'+path.join(runtime,'review.sqlite'))}],'setup_token_hash'=>''];`);
await fs.writeFile(path.join(area,'store-review/gateway.php'),`<?php return ['accounts'=>['${credentials.admin.email}','${credentials.client.email}'],'origins'=>${portals}];`);
const seed=spawnSync(php,['-d','extension=fileinfo','tools/store/seed-review.php',runtime,root],{cwd:root,encoding:'utf8'});assert.equal(seed.status,0,seed.stderr);
const finish=`<?php require ${literal(path.join(runtime,'bootstrap.php'))}; $db=new \\Omnigo\\Database((require ${literal(path.join(runtime,'config.php'))})['database']);$access=json_decode(file_get_contents(${literal(path.join(privateFolder,'access.json'))}),true);foreach(['admin'=>1,'client'=>2] as $role=>$id)$db->run('UPDATE users SET email=?,name=?,password=? WHERE id=?',[$access[$role]['email'],$role==='admin'?'Örnek İnceleme Yöneticisi':'Örnek İnceleme Müşterisi',\\Omnigo\\Security::hashPassword($access[$role]['password']),$id]);$db->run('UPDATE users SET password=? WHERE id=3',[\\Omnigo\\Security::hashPassword(bin2hex(random_bytes(32)))]);$db->run('DELETE FROM sessions');$db->run('DELETE FROM jobs');$db->run('DELETE FROM mail_outbox');$db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');echo 'İnceleme hesapları hazır.';`;
await fs.writeFile(path.join(area,'finalize.php'),finish);
const finished=spawnSync(php,[path.join(area,'finalize.php')],{encoding:'utf8'});assert.equal(finished.status,0,finished.stderr);
await fs.writeFile(path.join(area,'config.php'),`<?php return ['origin'=>'http://127.0.0.1:8823','portals'=>${portals},'database'=>['dsn'=>${literal('sqlite:'+path.join(area,'production-canary.sqlite'))}],'setup_token_hash'=>''];`);
await fs.writeFile(path.join(area,'canary.php'),`<?php require __DIR__.'/bootstrap.php';$db=new \\Omnigo\\Database((require __DIR__.'/config.php')['database']);$db->pdo->exec(file_get_contents(${literal(path.join(root,'php/tests/schema.sqlite.sql'))}));(new \\Omnigo\\Repository($db))->createCompany('ÜRETİM SINIRI TEST KAYDI');$db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,NULL)',['canary@example.invalid','Sınır Testi',\\Omnigo\\Security::hashPassword('Local-Canary-Password-123'),'admin']);`);
const canary=spawnSync(php,[path.join(area,'canary.php')],{encoding:'utf8'});assert.equal(canary.status,0,canary.stderr);
await fs.writeFile(path.join(area,'router.php'),`<?php $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(str_starts_with($path,'/api/')){require __DIR__.'/public/api.php';return true;}return false;`);
console.log(JSON.stringify({prepared:true,isolatedDatabase:true,credentialsPrinted:false,area}));
