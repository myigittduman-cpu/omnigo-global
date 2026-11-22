<?php
declare(strict_types=1);
namespace Omnigo;
final class PushSubscriptionValidator
{
 public static function validate(array $value):array{
  $endpoint=$value['endpoint']??null;
  if(!is_string($endpoint)||strlen($endpoint)>2048||preg_match('/[\\x00-\\x20]/',$endpoint))Http::fail(400,'Bildirim adresi geçersiz.');
  $url=parse_url($endpoint);$host=strtolower($url['host']??'');
  $allowed=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)
   || (str_ends_with($host,'.notify.windows.com')&&preg_match('/^[a-z0-9.-]+$/D',$host));
  if(!$allowed||($url['scheme']??'')!=='https'||isset($url['user'],$url['pass'])||isset($url['user'])||isset($url['port'])||isset($url['fragment'])||!str_starts_with($url['path']??'','/'))Http::fail(400,'Bu bildirim sağlayıcısı desteklenmiyor.');
  $keys=(array)($value['keys']??[]);
  foreach(['p256dh'=>65,'auth'=>16] as $key=>$size){
   $encoded=$keys[$key]??null;
   if(!is_string($encoded)||!preg_match('/^[A-Za-z0-9_-]+={0,2}$/D',$encoded))Http::fail(400,'Bildirim anahtarı geçersiz.');
   $bytes=base64_decode(strtr($encoded,'-_','+/'),true);
   if($bytes===false||strlen($bytes)!==$size||($key==='p256dh'&&ord($bytes[0])!==4))Http::fail(400,'Bildirim anahtarı geçersiz.');
  }
  return ['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']],'contentEncoding'=>'aes128gcm'];
 }
}

