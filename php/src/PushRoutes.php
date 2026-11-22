<?php
declare(strict_types=1);
namespace Omnigo;
final class PushRoutes
{
 public function __construct(private Database $db){}
 public function handle(string $path,string $method,array $user):void{
  if(!str_starts_with($path,'/api/push/'))return;
  if(!in_array($user['role'],['admin','client'],true))Http::fail(403,'Bildirim erişiminiz yok.');$devices=self::devices($this->db);
  if($path==='/api/push/config'&&$method==='GET'){
   $file=dirname(__DIR__).'/storage/push-vapid.json';$keys=is_file($file)?json_decode(file_get_contents($file),true):[];
   Http::json(['enabled'=>!empty($keys['publicKey']),'publicKey'=>$keys['publicKey']??null]);
  }
  if($path==='/api/push/register'&&$method==='POST'){
   $data=Http::body();Http::json($devices->save($user,$data['kind']??'', $data));
  }
  if(preg_match('#^/api/push/devices/(\\d+)(/test)?$#D',$path,$m)){
   $id=(int)$m[1];
   if($method==='GET'){$d=$devices->current($user,$id);Http::json(['enabled'=>$devices->usable($d),'sound'=>(bool)$d['sound']]);}
   if($method==='POST'&&isset($m[2])){$devices->test($user,$id);Http::json(['queued'=>true]);}
   if($method==='POST')Http::json($devices->change($user,$id,Http::body()));
  }
  if(preg_match('#^/api/push/devices/(\d+)/attach$#D',$path,$m)&&$method==='POST'){
   $device=$devices->current($user,(int)$m[1]);
   if(!$devices->usable($device))Http::json(['enabled'=>false,'sound'=>(bool)$device['sound']]);
   $devices->attachLanguage($user,$device);
   $this->db->run('UPDATE notification_devices SET session_token=?,last_seen=? WHERE id=? AND user_id=?',[$user['token'],time(),(int)$m[1],(int)$user['id']]);
   Http::json(['enabled'=>true,'sound'=>(bool)$device['sound']]);
  }
  Http::fail(405,'Bildirim işlemi desteklenmiyor.');
 }
 public static function devices(Database $db):PushDevices{return new PushDevices($db,new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));}
}

