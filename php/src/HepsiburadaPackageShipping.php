<?php
declare(strict_types=1);
namespace Omnigo;

/** Claim status and physical package delivery are independent source facts. GET-only, bounded by worker deadline. */
final class HepsiburadaPackageShipping
{
    public static function map(array $raw,HepsiburadaReadClient $reader,HepsiburadaCredentials $credentials,int $deadline,array &$state,?HepsiburadaShippingLabelCache $labels=null):array
    {
        $number=(string)($raw['orderNumber']??'');
        $labels??=new HepsiburadaShippingLabelCache(0,$credentials->merchantId);
        $mapped=HepsiburadaOrderMapper::map($raw,$credentials->merchantId,$number,[],$labels); // Validate tenant and every line before package reads.
        $packages=[];$seen=[];
        foreach($raw['items'] as $line){
            if(!in_array($line['status']??'', ['ClaimCreated','InTransit','Packaged'],true))continue;
            $package=(string)($line['packageNumber']??'');if(!preg_match('/^[0-9]{1,30}$/D',$package)||isset($seen[$package]))continue;
            $seen[$package]=true;
            $cached=$state['packageShipping'][$package]??null;
            if(!$cached||(int)$cached['checkedAt']<time()-60){
                if(time()>=$deadline-3)throw new SyncFailure(503,60);
                try{$items=$reader->packageShipping($credentials,$package,$deadline);}
                catch(SyncFailure $e){if($e->httpStatus!==404)throw $e;$items=[];}
                $statuses=[];$barcodes=[];
                foreach($items as $item){
                    if(!is_array($item)||(string)($item['packageNumber']??$item['PackageNumber']??'')!==$package)throw new SyncFailure(422);
                    if(array_key_exists('barcode',$item)||array_key_exists('Barcode',$item)){
                        $sourceMerchant=$item['merchantId']??$item['MerchantId']??$credentials->merchantId;
                        $sourceOrder=(string)($item['orderNumber']??$item['OrderNumber']??$number);
                        if(strtolower((string)$sourceMerchant)!==$credentials->merchantId||$sourceOrder!==$number)continue;
                        $code=DeliveryShippingLabels::code($item['barcode']??$item['Barcode']??null);
                        $barcodes[]=$code;
                    }
                    $status=match(strtolower((string)($item['status']??$item['Status']??''))){'delivered'=>'delivered','intransit','shipped'=>'shipped','undelivered'=>'undelivered',default=>''};
                    if($status!=='')$statuses[$status]=true;
                }
                if($barcodes){
                    $distinct=array_unique($barcodes,SORT_STRING);
                    $labels->put($number,$package,count($distinct)===1?reset($distinct):null);
                }
                $cached=['status'=>count($statuses)===1?array_key_first($statuses):'','checkedAt'=>time()];
                $state['packageShipping'][$package]=$cached;
                if(count($state['packageShipping'])>2000){uasort($state['packageShipping'],static fn($a,$b)=>$b['checkedAt']<=>$a['checkedAt']);$state['packageShipping']=array_slice($state['packageShipping'],0,2000,true);}
            }
            if($cached['status']!=='')$packages[$package]=$cached['status'];
        }
        return $seen?HepsiburadaOrderMapper::map($raw,$credentials->merchantId,$number,$packages,$labels):$mapped;
    }
    /** Revisit old review snapshots even when the order feed itself has not changed. */
    public static function schedule(Database $db,int $company,array &$state):void
    {
        if(count($state['pendingOrders']??[])>=20)return;
        $cursor=(int)($state['packageReviewCursor']??0);
        $rows=$db->all("SELECT id,external_id,payload FROM recent_orders WHERE company_id=? AND provider='hepsiburada' AND id>? AND payload LIKE ? ORDER BY id LIMIT 5",[$company,$cursor,'%"shippingState":"review"%']);
        foreach($rows as $row)$state['pendingOrders'][(string)$row['external_id']]=true;
        $state['packageReviewCursor']=count($rows)===5?(int)end($rows)['id']:0;
    }
}
