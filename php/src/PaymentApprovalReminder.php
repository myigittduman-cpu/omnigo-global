<?php
declare(strict_types=1);
namespace Omnigo;

/** Schedules an admin-only reminder through the existing payout notification feed. */
final class PaymentApprovalReminder
{
    public const OPERATION='approval_overdue';
    public const MESSAGE='Hakedişiniz hâlâ yatmadı mı? Hesaba geçiş onayı bekleniyor. Lütfen banka hareketlerini kontrol edin.';

    public function __construct(private Database $db){}

    public function run(?\DateTimeImmutable $now=null): int
    {
        $local=($now??new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Istanbul'));
        if((int)$local->format('G')<16)return 0;
        $day=$local->format('Y-m-d');
        $created=$local->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        $date="JSON_EXTRACT(r.payload,'$.expectedDate')";
        if($this->db->mysql())$date="JSON_UNQUOTE($date)";
        $key=$this->db->mysql()?"CONCAT('payment-approval:',r.id,':',?)":"'payment-approval:' || r.id || ':' || ?";
        $after=0;$sent=0;
        do{
            $rows=$this->db->all("SELECT r.id,r.company_id,r.payload FROM records r
                WHERE r.kind='payments' AND r.id>? AND $date=? AND NOT EXISTS(
                    SELECT 1 FROM order_notifications n
                    WHERE n.company_id=r.company_id AND n.provider='portal' AND n.order_key=($key)
                ) ORDER BY r.id LIMIT 100",[$after,$day,$day]);
            foreach($rows as $row){
                $after=(int)$row['id'];
                if(self::due(json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),$day)){
                    $sent+=(int)$this->publish((int)$row['company_id'],$after,$day,$created);
                }
            }
        }while(count($rows)===100);
        return $sent;
    }

    private static function due(array $payment,string $day): bool
    {
        return ($payment['expectedDate']??null)===$day
            &&in_array($payment['status']??null,['pending','sent','paid'],true)
            &&empty($payment['archivedAt'])&&empty($payment['integration'])
            &&empty($payment['paymentStateUnknown'])&&empty($payment['excludedFromTotals'])
            &&MarketplaceVisibility::visible((string)($payment['channel']??''))
            &&!PaymentApproval::isApproved($payment);
    }

    private function publish(int $company,int $record,string $day,string $created): bool
    {
        return TransactionRetry::run($this->db,function()use($company,$record,$day,$created){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='payments' AND id=?".$this->db->lock(),[$company,$record]);
            if(!$row)return false;
            $payment=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            if(!self::due($payment,$day))return false;

            $payload=['audience'=>'admin','events'=>['payment_updated'],'operation'=>self::OPERATION,
                'view'=>'payments','targetId'=>$record,'payoutDate'=>$day,'payoutChannel'=>$payment['channel']??''];
            $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            $added=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
                [$company,'portal',"payment-approval:$record:$day",'operation',json_encode($payload,JSON_THROW_ON_ERROR),$created]);
            if($added->rowCount()!==1)return false;
            PushRoutes::devices($this->db)->enqueue($this->db->insertId());
            return true;
        });
    }
}
