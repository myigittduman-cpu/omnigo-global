<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushSender
{
    private const APPLE_VIEW = 'view=(?:orders|shipments|deliveries|connections|support|inventory|payments|returns)';
    private const APPLE_WORKSPACE = 'orderCompany=[1-9][0-9]{0,9}';
    private const APPLE_DETAIL = '(?:&noticeType=(?:order|shipment|operation|support|user)&noticeId=[1-9][0-9]{0,9})?';
    private const APPLE_CHAT_ROUTE = '~\A/\?chatThread=[1-9][0-9]{0,14}\z~';

    private MobilePushHttp $http;
    private MobilePushTokens $tokens;
    public function __construct(private ?array $config=null,?MobilePushHttp $http=null) {$this->http=$http??new MobilePushHttp();$this->tokens=new MobilePushTokens($this->http);}
    public static function result(string $kind,array $response): array
    {
        $status=(int)$response['status'];$expired=false;
        if ($kind==='ios') $expired=in_array($response['body']['reason']??'',['Unregistered','BadDeviceToken'],true);
        else foreach ($response['body']['error']['details']??[] as $detail) {
            if (($detail['@type']??'')==='type.googleapis.com/google.firebase.fcm.v1.FcmError' && ($detail['errorCode']??'')==='UNREGISTERED') $expired=true;
        }
        return ['success'=>$status>=200&&$status<300,'expired'=>$expired,'status'=>$status,
            'permanent'=>!$expired&&in_array($status,[400,401,403,404],true),'retryAfter'=>(int)($response['retryAfter']??0)];
    }
    public function send(array $subscription,array $message,string $language='tr'): array
    {
        $config=$this->config??MobilePushConfig::read();$kind=$subscription['kind'];
        if (!MobilePushConfig::ready($kind,$config)) return ['success'=>false,'expired'=>false,'status'=>503,'retryAfter'=>300];
        if ($kind==='ios') {
            $host=$subscription['environment']==='sandbox'?'api.sandbox.push.apple.com':'api.push.apple.com';
            $payload=$this->applePayload($subscription['appId'],$message,$language);
            $response=$this->http->post('https://'.$host.'/3/device/'.$subscription['token'],[
                'authorization: bearer '.$this->tokens->apns($config['apns']),'apns-topic: '.$subscription['appId'],
                'apns-push-type: alert','apns-priority: 10','apns-expiration: '.(time()+3600),
                'apns-collapse-id: '.$payload['tag'],'Content-Type: application/json'],
                json_encode($payload,JSON_THROW_ON_ERROR),true);
        } else {
            $payload=['token'=>$subscription['token'],
                'data'=>['url'=>$message['url'],'tag'=>$message['tag'],'title'=>$message['title'],'body'=>$message['body']],
                'android'=>['priority'=>'HIGH','ttl'=>'3600s','restricted_package_name'=>$subscription['appId']]];
            // New APKs draw one consistent branded notification in foreground and background.
            // Older APKs keep FCM's existing automatic background display until they are updated.
            if(($subscription['displayVersion']??1)<2){
                $payload['notification']=['title'=>$message['title'],'body'=>$message['body']];
                $payload['android']['notification']=['channel_id'=>$message['sound']?'omnigo_updates':'omnigo_updates_silent',
                    'tag'=>$message['tag'],'icon'=>'ic_notification','default_sound'=>$message['sound']];
            }
            $response=$this->http->post('https://fcm.googleapis.com/v1/projects/'.$config['fcm']['projectId'].'/messages:send',[
                'Authorization: Bearer '.$this->tokens->fcm($config['fcm']),'Content-Type: application/json'],
                json_encode(['message'=>$payload],JSON_THROW_ON_ERROR));
        }
        return self::result($kind,$response);
    }

    private function applePayload(string $appId,array $message,string $language): array
    {
        $title=$appId==='com.omnigoglobal.admin'?'Omnigo Global Yönetici Portalı':'Omnigo Global İş Ortağım';
        $tag=preg_match('~\A[a-f0-9]{64}\z~',$message['tag'])===1 ? $message['tag'] : hash('sha256',$message['tag']);
        $url=$message['url'];
        $chat=preg_match(self::APPLE_CHAT_ROUTE,$url)===1;
        $body=match(true){
            $chat=>'Yeni sohbet mesajınız var.',
            $url==='/' => NotificationLanguage::text('Bildirim denemesi; mobil bildirim bağlantısı çalışıyor.',$language),
            default=>NotificationLanguage::text('Yeni bir bildiriminiz var. Ayrıntıları uygulamada görüntüleyebilirsiniz.',$language),
        };

        // Preserve internal navigation while blocking arbitrary values from the Apple payload.
        $route='~\A/\?(?:'.self::APPLE_VIEW.'(?:&'.self::APPLE_WORKSPACE.')?'.self::APPLE_DETAIL
            .'|'.self::APPLE_WORKSPACE.'(?:&'.self::APPLE_VIEW.self::APPLE_DETAIL.')?)\z~';
        $aps=['alert'=>['title'=>$title,'body'=>$body]];
        if ($message['sound']) $aps['sound']='default';
        return [
            'aps'=>$aps,
            'url'=>$chat || preg_match($route,$url)===1 ? $url : '/',
            'tag'=>$tag,
        ];
    }
}
