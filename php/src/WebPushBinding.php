<?php
declare(strict_types=1);
namespace Omnigo;

/** Non-secret installation context; never used to authorize delivery. */
final class WebPushBinding
{
 public static function context(mixed $input):array{
  if($input===null)return [];
  if(!is_array($input)&&!is_object($input))Http::fail(400,'Bildirim cihazı bilgisi geçersiz.');
  $data=(array)$input;$origin=$data['origin']??'';$parts=is_string($origin)?parse_url($origin):false;
  if(!$parts||strlen($origin)>255||!in_array($parts['scheme']??'',['https','http'],true)||empty($parts['host'])
   ||array_intersect(['user','pass','path','query','fragment'],array_keys($parts))
   ||!in_array($data['platform']??null,['ios','web'],true)||!is_bool($data['standalone']??null))Http::fail(400,'Bildirim cihazı bilgisi geçersiz.');
  return ['origin'=>$origin,'platform'=>$data['platform'],'standalone'=>$data['standalone']];
 }
 public static function unchanged(array $row,array $user,array $previous,array $subscription):bool{
  return (int)$row['user_id']===(int)$user['id']&&$row['kind']==='web'&&(int)$row['enabled']===1&&(int)$row['expires']>time()
   &&hash_equals($row['credential_tag'],PushDevices::tag($user))
   &&($previous['endpoint']??null)===$subscription['endpoint']&&($previous['keys']??null)===$subscription['keys'];
 }
}
