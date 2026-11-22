<?php
declare(strict_types=1);
namespace Omnigo;

/** Order-scoped accounting postings. These are components, never the final remittance. */
final class HepsiburadaProfitabilityLedger
{
    private const TYPES = ['Commission','CampaignDiscount','PaymentServiceCostReflection','ProcessingFeeExpense'];

    public function __construct(private ?QuestionReadTransport $transport=null) {}

    /** The existing profit worker grants a separate provider slot for each of the two reads. */
    public function observe(array $settings,array $numbers,int $deadline,?array &$checkpoint,callable $readOrder): array
    {
        $number=(string)array_key_first($numbers);
        if(count($numbers)!==1||!preg_match('/^\d{1,30}$/D',$number)){
            $checkpoint=null;
            return $readOrder();
        }
        if(($checkpoint['number']??null)!==$number||!is_array($checkpoint['order']??null)){
            $orders=$readOrder();
            $order=$orders[$number]??null;
            $checkpoint=$order===null?null:['number'=>$number,'order'=>$order,'startedAt'=>time()];
            return $orders;
        }
        $order=$checkpoint['order'];
        if(time()-(int)($checkpoint['startedAt']??0)<900){
            try{
                $payable=null;
                $items=$this->read(HepsiburadaCredentials::fromArray($settings),$number,$deadline,$payable);
                if($payable!==null)$order['reportedPayoutCents']=$payable;
                if($items)$order['statementItems']=$items;
            }catch(SyncFailure $error){
                // Missing accounting scope cannot block the separate order feed.
                // Share active quota/backoff with every other caller of the provider.
                if($error->httpStatus===429||$error->httpStatus>=500)throw $error;
            }
        }
        $checkpoint=null;
        return [$number=>$order];
    }

    public function read(HepsiburadaCredentials $credentials,string $number,int $deadline,?int &$payable=null): array
    {
        if(!preg_match('/^\d{1,30}$/D',$number))return [];
        $query=http_build_query(['OrderNumber'=>$number,'Offset'=>0,'Limit'=>100]);
        $raw=($this->transport??new QuestionReadTransport())->read(
            'https://mpfinance-external.hepsiburada.com/transactions/merchantid/'.$credentials->merchantId.'?'.$query,
            ['Accept: application/json','Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey),
                'User-Agent: '.$credentials->userAgent],$deadline);
        try{$page=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(\JsonException){throw new SyncFailure(502);}
        $payable=is_array($page)?self::scheduledPayout($page,$credentials->merchantId,$number):null;
        return is_array($page)?self::items($page,$credentials->merchantId,$number):[];
    }

    public static function scheduledPayout(array $page,string $merchant,string $number): ?int
    {
        $rows=$page['items']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>=100||count($rows)!==($page['count']??null))return null;
        $seen=[];$hasPayment=false;$sum=0;
        foreach($rows as $row){
            if(!is_array($row)||strtolower((string)($row['merchantId']??''))!==$merchant
                ||(string)($row['orderNumber']??'')!==$number||!in_array($row['status']??null,['Paid','WillBePaid'],true))return null;
            $id=$row['id']??null;
            $amount=self::money($row['amount']??null);
            if(!is_string($id)||$id===''||isset($seen[$id])||$amount===null||!is_bool($row['isIncome']??null)
                ||($row['isIncome']?$amount<0:$amount>0))return null;
            $seen[$id]=true;
            $hasPayment=$hasPayment||(($row['transactionType']??null)==='Payment'&&$amount>0);
            $sum+=$amount;
        }
        return $hasPayment&&$sum>=0?$sum:null;
    }

    public static function items(array $page,string $merchant,string $number): array
    {
        $rows=$page['items']??null;
        // One exact-order request. Large/incomplete snapshots are not published.
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>100||($page['count']??null)!==count($rows))return [];
        $items=[];$seen=[];
        foreach($rows as $row){
            if(!is_array($row)||!is_string($row['merchantId']??null)
                ||strtolower($row['merchantId'])!==$merchant||(string)($row['orderNumber']??'')!==$number)return [];
            $key=$row['id']??null;
            if(!is_string($key)||$key===''||strlen($key)>200||isset($seen[$key]))return [];
            $seen[$key]=true;
            $type=$row['transactionType']??null;
            if(!in_array($type,self::TYPES,true))continue;
            $line=$row['orderItemNumber']??null;
            if(($row['transactionTypeCategory']??null)!=='OrderBased'||(!is_string($line)&&!is_int($line))
                ||trim((string)$line)===''||!in_array($row['status']??null,['Paid','WillBePaid'],true))return [];
            $gross=self::money($row['amount']??null);$net=self::money($row['netAmount']??null);$tax=self::money($row['taxAmount']??null);
            if($gross===null||$net===null||$tax===null||$gross!==$net+$tax||!is_bool($row['isIncome']??null)
                ||($row['isIncome']?min($gross,$net,$tax)<0:max($gross,$net,$tax)>0))return [];
            try{$due=FinanceMapper::date($row['dueDate']??null);}catch(SyncFailure){return [];}
            $items[$key]=['type'=>$type,'status'=>$row['status'],'dueDate'=>$due,
                'grossCents'=>$gross,'netCents'=>$net,'taxCents'=>$tax];
        }
        // Stable details across polling even if identical postings arrive in another API order.
        ksort($items,SORT_STRING);
        return array_values($items);
    }

    private static function money(mixed $value): ?int
    {
        if(!is_array($value)||!in_array($value['currencyCode']??null,['949','TRY'],true))return null;
        $number=$value['value']??null;
        if((!is_int($number)&&!is_float($number)&&!is_string($number))
            ||!preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D',(string)$number))return null;
        return ProfitabilityFinancialEvidence::cents($number);
    }
}
