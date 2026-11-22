<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{ShipmentEditingPresence,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,int $code):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('İşlem engellenmeliydi.');}
$folder=sys_get_temp_dir().'/omnigo-presence-'.bin2hex(random_bytes(6));$now=1000;
$presence=new ShipmentEditingPresence($folder,static function()use(&$now){return $now;});
$admin=['id'=>1,'role'=>'admin','token'=>'session-a'];$adminOther=['id'=>2,'role'=>'admin','token'=>'session-b'];
$client=['id'=>3,'role'=>'client','company_id'=>1];$other=['id'=>4,'role'=>'client','company_id'=>2];
$a=str_repeat('a',48);$b=str_repeat('b',48);
try{
 check(!$presence->status($client,1)['editing'],'Başlangıçta kilit yok.');
 denied(fn()=>$presence->update($client,1,['token'=>$a,'action'=>'renew']),403);
 denied(fn()=>$presence->status($other,1),403);
 $presence->update($admin,1,['token'=>$a,'action'=>'renew']);
 check($presence->status($client,1)['editing']&&!$presence->status($other,2)['editing'],'Kilit yalnız aynı firmada görünmeli.');
 denied(fn()=>$presence->holdClientWrite($client,1),423);
 $presence->update($adminOther,1,['token'=>$a,'action'=>'release']);
 check($presence->status($client,1)['editing'],'Başka yönetici kilidi kaldıramamalı.');
 $presence->update($adminOther,1,['token'=>$b,'action'=>'renew']);
 $presence->update($admin,1,['token'=>$a,'action'=>'release']);
 check($presence->status($client,1)['editing'],'Son yönetici bitirmeden müşteri açılmamalı.');
 $now+=91;check(!$presence->status($client,1)['editing'],'Kopan bağlantı kalıcı kilit bırakmamalı.');
 $guard=new ShipmentEditingPresence($folder,static function()use(&$now){return $now;});
 $guard->holdClientWrite($client,1);
 denied(fn()=>$presence->update($admin,1,['token'=>$a,'action'=>'renew']),503);
 unset($guard);
 $presence->update($admin,1,['token'=>$a,'action'=>'renew']);
 $now+=60;$presence->update($admin,1,['token'=>$a,'action'=>'renew']);$now+=60;
 check($presence->status($client,1)['editing'],'Kalp atışı oturumu canlı tutmalı.');
 $presence->update($admin,1,['token'=>$a,'action'=>'release']);
 check(!$presence->status($client,1)['editing'],'Kaydetme sonrası bekleme kalkmalı.');
 echo "Düzenleme kilidi: firma/rol/sekme ayrımı, çoklu yönetici, yenileme, süre aşımı ve eşzamanlı yazma engeli geçti.\n";
}finally{unset($presence);foreach(glob($folder.'/*.json')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}
