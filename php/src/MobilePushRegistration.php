<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushRegistration
{
    public static function validate(array $data, array $user): array
    {
        $kind=$data['kind']??'';
        $app='com.omnigoglobal.'.($user['role']==='admin'?'admin':'client');
        if($user['role']==='admin'&&$kind==='android'&&($data['appId']??'')==='com.omnigoglobal.admin.privateapp')$app=$data['appId'];
        if (!in_array($kind,['android','ios'],true) || ($data['appId']??'')!==$app
            || !is_bool($data['sound']??null)
            || !is_string($data['installationId']??null) || !preg_match('/^[a-f0-9]{64}$/D',$data['installationId'])) {
            Http::fail(400,'Mobil bildirim kaydı doğrulanamadı.');
        }
        $token=$data['token']??null;
        if (!is_string($token) || ($kind==='ios'
            ? !preg_match('/^[a-f0-9]{64,200}$/D',$token) || strlen($token)%2!==0
            : !preg_match('/^[A-Za-z0-9_:\-]{20,4096}$/D',$token))) Http::fail(400,'Bildirim belirteci geçersiz.');
        $environment=$data['environment']??'production';
        if (!in_array($environment,['production','sandbox'],true) || ($kind==='android' && $environment!=='production')) Http::fail(400,'Bildirim ortamı geçersiz.');
        $displayVersion=$data['displayVersion']??1;
        if(!in_array($displayVersion,[1,2],true))Http::fail(400,'Bildirim görünümü sürümü geçersiz.');
        return ['kind'=>$kind,'appId'=>$app,'token'=>$token,'environment'=>$environment,'displayVersion'=>$kind==='android'?$displayVersion:1,
            'installationId'=>$data['installationId'],'sound'=>true];
    }

    public static function tag(array $user): string
    {
        return hash('sha256',$user['password'].':'.$user['role'].':'.($user['company_id']??''));
    }
}
