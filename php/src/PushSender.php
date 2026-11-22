<?php
declare(strict_types=1);
namespace Omnigo;
final class PushSender
{
 public function send(array $subscription,array $payload):array{
  require_once dirname(__DIR__).'/vendor/push/autoload.php';
  $keys=json_decode(file_get_contents(dirname(__DIR__).'/storage/push-vapid.json'),true,16,JSON_THROW_ON_ERROR);
  $sender=new \Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>'https://yonetim.omnigoglobal.com','publicKey'=>$keys['publicKey'],'privateKey'=>$keys['privateKey']]],['TTL'=>86400,'urgency'=>'high'],15,['connect_timeout'=>5,'allow_redirects'=>false,'verify'=>true,'proxy'=>'']);
  $report=$sender->sendOneNotification(\Minishlink\WebPush\Subscription::create($subscription),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  $status=$report->getResponse()?->getStatusCode()??0;
  $retry=$report->getResponse()?->getHeaderLine('Retry-After')??'';
  return ['success'=>$report->isSuccess(),'expired'=>$report->isSubscriptionExpired(),'status'=>$status,'retryAfter'=>ctype_digit($retry)?min(86400,(int)$retry):0];
 }
}

