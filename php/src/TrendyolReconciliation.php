<?php
declare(strict_types=1);
namespace Omnigo;

/** Builds an order reference from API packages, independently of locally published orders. */
final class TrendyolReconciliation
{
    public static function initialize(array &$state,int $now):void
    {
        if(isset($state['auditFrom']))return;
        $state['auditFrom']=max($state['coverageStart'],$now-89*86400000);
        if(!empty($state['ordersPublished'])){
            $state['auditNext']=$state['auditFrom'];
            $state['auditEnd']=$now;
        }
    }

    public static function observe(array &$state,array $packages):void
    {
        foreach($packages as $package){
            $mapped=TrendyolOrderMapper::merge($package);
            if(TrendyolOrderMapper::timestamp($package['orderDate'])<$state['auditFrom'])continue;
            $order=$mapped['order'];
            $key=ReconciliationReference::key($order['externalId']);
            $entry=$state['auditOrders'][$key]??['reference'=>$order['externalId'],'month'=>$order['month'],'units'=>[]];
            foreach($mapped['delivery']['trendyolSourceItems'] as $id=>$unit){
                $old=$entry['units'][$id]??null;
                if(!TrendyolOrderMapper::acceptUnit($old,$unit['package'],$unit['origins'],$unit['modified']))continue;
                $entry['units'][$id]=['package'=>$unit['package'],'modified'=>$unit['modified'],
                    'origins'=>array_values(array_unique([...$unit['origins'],...($old['origins']??[])])),
                    'amount'=>$unit['product']['grossCents'],'state'=>$unit['product']['shippingState'],'label'=>$unit['product']['status']];
            }
            $money=SalesCalendar::ZERO;$money['orders']=1;$labels=[];
            ksort($entry['units'],SORT_STRING);
            foreach($entry['units'] as $unit){
                $money['grossCents']+=$unit['amount'];
                if(in_array($unit['state'],['cancelled','undelivered'],true))$money['cancelCents']+=$unit['amount'];
                if($unit['state']==='returned')$money['returnCents']+=$unit['amount'];
                $labels[$unit['state']]??=$unit['label'];
            }
            $entry['money']=ReconciliationReference::money($money);
            $entry['state']=count($labels)===1?reset($labels):'Birden fazla işlem durumu';
            $state['auditOrders'][$key]=$entry;
        }
    }

    public static function publish(array &$state):void
    {
        if(isset($state['auditNext'],$state['auditEnd'])||isset($state['recentScan'])||isset($state['historyScan'])||
           ($state['phase']??'')!=='ready'||empty($state['watermark'])||$state['recentThrough']<$state['auditFrom'])return;
        $orders=[];foreach($state['auditOrders']??[] as $key=>$entry)$orders[$key]=array_diff_key($entry,['units'=>true]);
        $state['reconciliationReference']=['version'=>1,'from'=>gmdate('c',(int)ceil($state['auditFrom']/1000)),
            'asOf'=>$state['watermark'],'capturedAt'=>gmdate('c'),'orders'=>$orders,'deliveries'=>[],'shipments'=>[]];
    }

    public static function current(array $state,int $now):bool
    {
        $ref=$state['reconciliationReference']??[];
        return isset($state['auditFrom'],$ref['asOf'])&&!isset($state['auditNext'])&&!isset($state['recentScan'])&&!isset($state['historyScan'])
            &&($state['phase']??null)==='ready'&&($state['watermark']??null)===$ref['asOf']&&strtotime($ref['asOf'])>=$now-300;
    }
}
