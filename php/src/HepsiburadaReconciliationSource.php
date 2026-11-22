<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent API evidence. Backfill never repairs local orders or blocks their import. */
final class HepsiburadaReconciliationSource
{
    public static function discover(array &$state,string $feed,int $offset,array $page,string $merchant):void
    {
        $source=&$state['reconciliationSource'];$source??=[];
        foreach(HepsiburadaOrderDiscovery::numbers($page['items'],$merchant) as $number){
            if(!isset($source['orders'][ReconciliationReference::key($number)]))$source['pending'][$number]=true;
        }
        if($offset===0)$source['started'][$feed]=true;
        if(!$page['hasMore']&&!empty($source['started'][$feed]))$source['feeds'][$feed]=true;
    }
    public static function capture(array &$state,array $mapped):void
    {
        $source=&$state['reconciliationSource'];$source??=[];$order=$mapped['order'];
        // No recipient, address, phone, tax data or credentials belong in reconciliation evidence.
        $order['products']=array_map(static fn($line)=>array_intersect_key($line,array_flip(['lineId','quantity','grossCents','cancelCents','cancelledQuantity'])),$order['products']);
        $order=array_intersect_key($order,array_flip(['externalId','createdAt','orders','grossCents','cancelCents','returnCents','products']));
        $key=ReconciliationReference::key($order['externalId']);
        $draft=[];ReconciliationReference::page($draft,$mapped['deliveries'],true);
        $value=['order'=>$order,...$draft['reconciliationDraft']];
        if(($source['orders'][$key]??null)!==$value){$source['orders'][$key]=$value;$source['version']=($source['version']??0)+1;}
        unset($source['pending'][$order['externalId']]);
    }
    public static function backfill(array &$state,HepsiburadaReadClient $reader,HepsiburadaCredentials $credentials,int $deadline,int $budget):void
    {
        $source=&$state['reconciliationSource'];$source??=[];
        if(empty($source['pending'])){unset($source['error'],$source['nextAttemptAt']);return;}
        if(($source['nextAttemptAt']??0)>time())return;
        $attempts=0;
        foreach(array_keys($source['pending']??[]) as $number){
            if($attempts++>=$budget||time()>=$deadline-18)break;
            try{
                $number=(string)$number;
                self::capture($state,HepsiburadaPackageShipping::map($reader->order($credentials,$number,$deadline-15),$reader,$credentials,$deadline-15,$state));
                unset($source['error'],$source['nextAttemptAt']);
            }catch(\Throwable $error){
                $source['error']=['at'=>gmdate('c'),'code'=>$error instanceof SyncFailure?$error->httpStatus:500];
                $source['nextAttemptAt']=time()+max(60,$error instanceof SyncFailure?$error->retryAfter:60);
                break;
            }
        }
    }
    public static function ready(array $state):bool
    {
        $source=$state['reconciliationSource']??[];
        return count($source['feeds']??[])===count(HepsiburadaOrderDiscovery::FEEDS)&&empty($source['pending'])&&empty($source['error'])&&empty($state['pendingOrders'])&&empty($state['isolatedOrderCount'])&&($state['phase']??'')==='live';
    }
    public static function publish(array &$state,string $from,string $through,array $refunds):void
    {
        if(!self::ready($state))return;
        $source=$state['reconciliationSource'];$orders=[];$draft=['deliveries'=>[],'shipments'=>[]];$byOrder=[];
        foreach($refunds as $claim)$byOrder[$claim['orderNumber']][]=$claim;
        foreach($source['orders']??[] as $value){
            $order=$value['order'];$created=strtotime($order['createdAt']);
            if($created<strtotime($from)||$created>strtotime($through))continue;
            $order=HepsiburadaClaims::apply($order,$byOrder[$order['externalId']]??[]);
            $order['month']=substr(SalesCalendar::day($order['createdAt']),0,7);$orders[]=$order;
            foreach(['deliveries','shipments'] as $area)$draft[$area]=array_replace($draft[$area],$value[$area]);
        }
        $stage=['reconciliationDraft'=>$draft];ReconciliationReference::complete($stage,$orders,$from,$through);
        $state['reconciliationReference']=[...$stage['reconciliationReference'],'sourceVersion'=>$source['version']??0,'refunds'=>$byOrder];
    }
    public static function current(array $state):bool
    {
        return self::ready($state)&&empty($state['reconciliationSource']['publishError'])&&empty($state['financial']['scan'])&&empty($state['financial']['error'])
            &&isset($state['reconciliationReference'])
            &&($state['reconciliationReference']['sourceVersion']??-1)===($state['reconciliationSource']['version']??0)
            &&($state['reconciliationReference']['asOf']??null)===($state['financial']['through']??null);
    }
}
