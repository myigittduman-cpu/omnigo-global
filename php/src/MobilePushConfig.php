<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushConfig
{
    public static function read(): array
    {
        $file=dirname(__DIR__).'/storage/mobile-push.json';
        if (!is_file($file)) return [];
        try { return json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR) ?: []; }
        catch (\Throwable) { return []; }
    }
    public static function ready(string $kind,?array $config=null): bool
    {
        return self::status($kind,$config)['ready'];
    }
    /** Public diagnostics contain no project IDs, private paths or credentials. */
    public static function status(string $kind,?array $config=null): array
    {
        $config??=self::read();
        $label=$kind==='android'?'Android':'iPhone';
        $missing=['ready'=>false,'code'=>'not_configured','message'=>$label.' bildirim hizmetinin kurulumu henüz tamamlanmadı.'];
        if(!in_array($kind,['android','ios'],true))return $missing;
        $settings=$config[$kind==='android'?'fcm':'apns']??[];
        if(($settings['enabled']??false)!==true)return $missing;
        $invalid=['ready'=>false,'code'=>'invalid_config','message'=>$label.' bildirim bağlantısı doğrulanamadı. Omnigo ekibiyle iletişime geçin.'];
        try{
            $file=$settings[$kind==='android'?'serviceAccountFile':'privateKeyFile']??'';
            if(!is_string($file)||!is_file($file)||!is_readable($file)||filesize($file)>65536)return $invalid;
            $content=file_get_contents($file);
            if($kind==='android'){
                $account=json_decode($content,true,16,JSON_THROW_ON_ERROR);
                if(($account['type']??'')!=='service_account'||!preg_match('/^[a-z][a-z0-9-]{4,62}$/D',$settings['projectId']??'')
                    ||($account['project_id']??null)!==$settings['projectId']||!str_ends_with($account['client_email']??'','.iam.gserviceaccount.com'))return $invalid;
                $content=$account['private_key']??'';
            }elseif(!preg_match('/^[A-Z0-9]{10}$/D',$settings['teamId']??'')||!preg_match('/^[A-Z0-9]{10}$/D',$settings['keyId']??''))return $invalid;
            $key=openssl_pkey_get_private($content);if($key===false)return $invalid;
            $details=openssl_pkey_get_details($key);
            if($kind==='android'&&(($details['type']??null)!==OPENSSL_KEYTYPE_RSA||($details['bits']??0)<2048))return $invalid;
            if($kind==='ios'&&(($details['type']??null)!==OPENSSL_KEYTYPE_EC||($details['ec']['curve_name']??'')!=='prime256v1'))return $invalid;
            return ['ready'=>true,'code'=>'configured','message'=>$label.' bildirim bağlantısı yapılandırıldı. Teslimatı deneme bildirimiyle kontrol edin.'];
        }catch(\Throwable){return $invalid;}
    }
}
