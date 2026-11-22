<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer alerts contain only Omnigo service updates, never the merchant event feed. */
final class CustomerServiceNotifications
{
    public static function payment(Database $db,array $actor,int $company,int $record,int $audit,bool $updated,bool $deleted=false): void
    {
        if(($actor['role']??'')!=='admin')return;
        if(!$db->pdo->inTransaction())throw new \LogicException('Bildirim kayıtla birlikte kaydedilmeli.');
        $insert=$db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $change=['events'=>['payment_updated'],'operation'=>$deleted?'deleted':($updated?'updated':'created'),'targetId'=>$record];
        foreach(['client'=>'payment:','admin'=>'admin-payment:'] as $audience=>$prefix){
            $payload=['audience'=>$audience,...$change];
            $added=$db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
                [$company,'portal',hash('sha256',$prefix.$audit),'operation',json_encode($payload,JSON_THROW_ON_ERROR),gmdate('c')]);
            if($added->rowCount()===1)PushRoutes::devices($db)->enqueue($db->insertId());
        }
    }
    public static function paymentMessage(array $detail): string
    {
        return match($detail['operation']??''){'deleted'=>'Omnigo, hakediş takviminden bir kaydı kaldırdı.','updated'=>'Omnigo, hakediş takvimindeki bilgileri güncelledi.',default=>'Omnigo, hakediş takvimine ekleme yaptı.'};
    }
}
