<?php
declare(strict_types=1);
namespace Omnigo;

/** Business alerts only. Authentication and account-security mail use separate paths. */
final class NotificationMailPolicy
{
    public static function allows(array $payload,?Database $db=null): bool
    {
        if(($payload['type']??'')==='integration')return IntegrationOutageMail::permitted($payload)||IntegrationRecoveryMail::permitted($payload);
        if(($payload['type']??'')!=='operation')return true;
        if(!$db)throw new \LogicException('Operasyon e-postası tercihi için veritabanı gerekiyor.');
        return (new OperationMailPreference($db))->enabled() && (bool)array_intersect($payload['detail']['events']??[],['new_order','cancelled','cancellation_requested','return_updated','document_uploaded','user_change','notification_test']);
    }
}
