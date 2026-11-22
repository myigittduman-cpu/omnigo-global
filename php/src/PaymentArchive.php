<?php
declare(strict_types=1);
namespace Omnigo;

final class PaymentArchive
{
    public static function report(Database $db,array $user,int $company,string $from,string $to,string $channel): array
    {
        if($user['role']!=='admin')return ['archivedItems'=>[],'archivedCount'=>0];
        $items=[];$count=0;
        foreach($db->all("SELECT id,payload,version,updated FROM records WHERE company_id=? AND kind='payments' ORDER BY id DESC",[$company]) as $row){
            $p=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            if(empty($p['integration'])||!MarketplaceVisibility::visible($p['channel'])||($channel!=='all'&&$p['channel']!==$channel))continue;
            $day=$p['expectedDate']??'';
            if($day!==''&&($day<$from||$day>$to))continue;
            $count++;if(count($items)<100)$items[]=PaymentPolicy::decorate([...$p,'id'=>(int)$row['id'],'version'=>(int)$row['version'],'updated'=>$row['updated']]);
        }
        return ['archivedItems'=>$items,'archivedCount'=>$count];
    }
}
