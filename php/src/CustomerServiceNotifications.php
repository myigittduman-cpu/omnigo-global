<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer alerts contain only Omnigo service updates, never the merchant event feed. */
final class CustomerServiceNotifications
{
    public const APPROVED='approved';
    public const APPROVED_MESSAGE='Hakedişiniz hesaba yattı.';

    public static function payment(Database $db,array $actor,int $company,int $record,int $audit,bool $updated,bool $deleted=false): void
    {
        if(($actor['role']??'')!=='admin')return;
        $change=['events'=>['payment_updated'],'operation'=>$deleted?'deleted':($updated?'updated':'created'),'targetId'=>$record];
        foreach(['client'=>'payment:','admin'=>'admin-payment:'] as $audience=>$prefix){
            self::publishPayment($db,$company,$prefix.$audit,['audience'=>$audience,...$change]);
        }
    }
    public static function paymentApproved(Database $db,int $company,int $record,int $audit,array $payment): void
    {
        self::publishPayment($db,$company,'payment-approved:'.$audit,[
            'audience'=>'client','events'=>['payment_updated'],'operation'=>self::APPROVED,'targetId'=>$record,
            'view'=>'payments','payoutChannel'=>$payment['channel'],'payoutDate'=>$payment['paidDate'],
        ]);
    }

    private static function publishPayment(Database $db,int $company,string $key,array $payload): void
    {
        if(!$db->pdo->inTransaction())throw new \LogicException('Bildirim kayıtla birlikte kaydedilmeli.');
        $insert=$db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $added=$db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
            [$company,'portal',hash('sha256',$key),'operation',json_encode($payload,JSON_THROW_ON_ERROR),gmdate('c')]);
        if($added->rowCount()===1)PushRoutes::devices($db)->enqueue($db->insertId());
    }

    public static function paymentMessage(array $detail): string
    {
        return match($detail['operation']??''){PaymentApprovalReminder::OPERATION=>PaymentApprovalReminder::MESSAGE,self::APPROVED=>self::APPROVED_MESSAGE,'deleted'=>'Omnigo, hakediş takviminden bir kaydı kaldırdı.','updated'=>'Omnigo, hakediş takvimindeki bilgileri güncelledi.',default=>'Omnigo, hakediş takvimine ekleme yaptı.'};
    }
}
