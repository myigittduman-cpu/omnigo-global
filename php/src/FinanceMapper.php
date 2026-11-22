<?php
declare(strict_types=1);
namespace Omnigo;

/** Only marketplace-confirmed net amounts. A payout is not a bank receipt. */
final class FinanceMapper
{
    private static function cents(mixed $value): int
    {
        if(!is_int($value)&&!is_float($value)&&!is_string($value))throw new SyncFailure(422);
        if(!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/D',(string)$value))throw new SyncFailure(422);
        $negative=str_starts_with((string)$value,'-');$parts=explode('.',ltrim((string)$value,'-'));
        return ((int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0'))*($negative?-1:1);
    }
    public static function date(mixed $value,bool $optional=false): string
    {
        if($optional&&($value===null||$value===''))return '';
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/D',$value))throw new SyncFailure(422);
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){throw new SyncFailure(422);}
        return $date->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
    }
    public static function pazarama(array $raw): array
    {
        $id=PazaramaSalesMapper::id($raw['trxId']??null);
        $status=PazaramaSalesMapper::text($raw['status']??null,500);
        $net=self::cents($raw['allowanceAmount']??null);$date=self::date($raw['transactionDate']??null);
        // trxId is reused for sales, refunds and account adjustments. Reconcile complete date
        // windows by movement identity; changed movements replace their prior snapshot rows.
        $key=hash('sha256',json_encode([$id,$raw['trxCode']??null,$raw['transactionDate'],$status,$raw['amount']??null,$net,$raw['installmentNumber']??null],JSON_THROW_ON_ERROR));
        $lower=mb_strtolower($status);$sent=str_contains($lower,'hakediş satıcıya gönderildi')||str_contains($lower,'ödeme yapıldı')||str_contains($lower,'tahsil edildi');
        return ['key'=>$key,'transactionDate'=>$date,'expectedDate'=>self::date($raw['transferredDate']??null,true),'netCents'=>$net,'state'=>$sent?'sent':'pending'];
    }
    public static function hepsiburada(array $raw): ?array
    {
        if(!is_string($raw['id']??null)||$raw['id']===''||strlen($raw['id'])>200||!in_array($raw['status']??null,['Paid','WillBePaid'],true)||!is_bool($raw['isIncome']??null))throw new SyncFailure(422);
        if(!in_array($raw['amount']['currencyCode']??null,['949','TRY'],true))throw new SyncFailure(422);
        // The API already signs each movement. netAmount excludes VAT, so using it
        // would understate the receivable. Commission/tax movements are separate.
        $amount=self::cents($raw['amount']['value']??null);
        $due=self::date($raw['dueDate']??null);$paid=self::date($raw['paymentDate']??null,true);
        if($raw['status']==='Paid'&&$paid==='')throw new SyncFailure(422);
        // TotalPayment is the signed account debit for an actual marketplace transfer.
        // Paid component movements are already included in it: never add both.
        if(($raw['transactionType']??null)==='TotalPayment'){
            if($raw['status']!=='Paid'||$amount>0)throw new SyncFailure(422);
            return ['key'=>hash('sha256',$raw['id']),'transactionDate'=>$due,'expectedDate'=>$paid,'netCents'=>-$amount,'state'=>'sent'];
        }
        if($raw['status']==='Paid')return null;
        if(($amount>0&&!$raw['isIncome'])||($amount<0&&$raw['isIncome']))throw new SyncFailure(422);
        return ['key'=>hash('sha256',$raw['id']),'transactionDate'=>$due,'expectedDate'=>$due,'netCents'=>$amount,'state'=>'pending'];
    }
    public static function n11(array $raw):array
    {
        $day=N11FinanceClient::day($raw['settlementDate']??null);
        $date=N11FinanceClient::day($raw['remittanceDate']??null,true);
        $status=$raw['status']??null;
        if(!in_array($status,['Success','Wait_Remittance','Confirmed','NotConfirmed','Failed'],true)||($status==='Success'&&$date===''))throw new SyncFailure(422);
        $net=self::cents($raw['settlementAmount']??null);
        if(self::cents($raw['paymentAmount']??null)-self::cents($raw['deductionAmount']??null)!==$net)throw new SyncFailure(422);
        // The service identifies the daily settlement by its settlement date. Keep this
        // stable when the remittance date/status changes; duplicate dates fail the scan.
        return ['key'=>hash('sha256','n11:'.$day),'transactionDate'=>$day,'expectedDate'=>$date,'netCents'=>$net,'state'=>$status==='Success'?'sent':(in_array($status,['Confirmed','Wait_Remittance'],true)?'pending':'estimated')];
    }
    public static function koctas(array $raw): array
    {
        if(($raw['currency_iso_code']??null)!=='TRY'||!is_string($raw['id']??null)||strlen($raw['id'])>180||$raw['id']==='')throw new SyncFailure(422);
        $payout=$raw['pay_out']??null;if(!is_array($payout)||!is_string($payout['state']??null))throw new SyncFailure(422);
        return ['key'=>hash('sha256',$raw['id']),'transactionDate'=>self::date($raw['date_created']??null),'expectedDate'=>self::date($payout['transaction_date']??null,true),'netCents'=>self::cents($raw['amount_transferred_to_seller']??null),'state'=>$payout['state']==='PAID'?'sent':'pending'];
    }
}
