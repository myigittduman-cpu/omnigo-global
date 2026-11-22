<?php
declare(strict_types=1);
namespace Omnigo;

final class CustomerDashboardAccess
{
    public static function filter(Database $db,array $user,int $company,array $result): array
    {
        $policy=new CustomerPermissions($db,$company);$result['workspaceAccess']=$policy->access($user);
        if($user['role']==='admin')return $result;
        $access=$result['workspaceAccess'];
        $visible=static fn(string $page):bool=>!empty($access['grants'][$page]['view']);
        foreach(['payments','tasks','reports'] as $page)if(!$visible($page))$result[$page]=[];
        if(!$visible('payments')){
            $result['missingPaymentChannels']=[];
            foreach(['expectedCents','paidCents','sentCents','undatedCents','undatedCount'] as $key)$result['summary'][$key]=0;
        }
        if(!$visible('overview')&&!$visible('overviewSales')&&!$visible('analytics')){
            $result['sales']=[];$result['salesToday']=['channels'=>[]];$result['marketplaceSales']=['channels'=>[]];
            foreach([...array_keys(TurnoverAmounts::ZERO),'grossCents','cancelCents','returnCents','netCents','orders','salesOrders'] as $key)$result['summary'][$key]=0;
        }
        $salesSections=['summary'=>'overview','sales'=>'overviewSales','salesToday'=>'overview','marketplaceSales'=>'overviewSales'];
        $sales=[];
        foreach($salesSections as $section=>$page){
            $flags=CustomerTableVisibility::fromAccess($access,$page);
            $sales[$section]=CustomerSensitiveVisibility::redact(
                CustomerTurnoverDisplay::project($result[$section],$flags['afterCancellationTurnover']),$flags);
            unset($result[$section]);
            $result['tableSensitiveVisibility'][$page]=$flags;
        }
        $tableFlags=$result['tableSensitiveVisibility'];
        unset($result['tableSensitiveVisibility']);
        $result=[...CustomerSensitiveVisibility::financial($db,$user,$company,$result),...$sales,
            'turnoverDisplayBasis'=>CustomerTurnoverDisplay::BASIS,
            'sensitiveVisibility'=>$tableFlags['overview'],'tableSensitiveVisibility'=>$tableFlags];
        $returns=CustomerTableVisibility::fromAccess($access,'returns');
        if(!$tableFlags['overviewSales']['cancellations'])$result['hideMarketplaceSalesCancellations']=true;
        if($returns['cancellations']||$returns['returns']){
            $result['marketplaceRequestsPending']=(new ReturnRequestList($db))->pendingCount($company,
                includeCancellations:$returns['cancellations'],includeReturns:$returns['returns']);
        }
        if(!$visible('overviewSales')&&!$visible('analytics'))$result=self::overviewSummary($result);
        return CustomerTurnoverDisplay::canView($access)?$result:CustomerTurnoverDisplay::dashboardWithoutAmounts($result);
    }

    private static function overviewSummary(array $data): array
    {
        // Keep only the data used by the summary cards, charts and marketplace filter.
        $only=static fn(array $row,array $fields):array=>array_intersect_key($row,array_flip($fields));
        $amount=['turnoverCents','grossCents','amountCents','salesCents','turnoverDisplayBasis'];
        $data['channels']=array_map(fn(array $row)=>$only($row,['name','status','lastSyncAt']),$data['channels']);
        $data['sales']=array_map(fn(array $row)=>$only($row,['channel','month','integration','orders',...$amount]),$data['sales']);
        $data['salesToday']=['channels'=>[]];
        $data['marketplaceSales']['channels']=array_map(static function(array $row)use($only,$amount){
            return [
                ...$only($row,['channel','available']),
                'days'=>array_map(fn(array $day)=>$only($day,['date','quantity','orderedQuantity',...$amount]),$row['days']??[]),
            ];
        },$data['marketplaceSales']['channels']);
        return $data;
    }
}
