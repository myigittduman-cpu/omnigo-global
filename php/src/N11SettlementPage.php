<?php
declare(strict_types=1);
namespace Omnigo;

/** A settlement day can contain several installments in the official SOAP response. */
final class N11SettlementPage
{
    public static function normalize(array $rows,int $expected): array
    {
        if(count($rows)>10000)throw new SyncFailure(422);
        $days=[];$groups=[];$seen=[];$dayGroups=[];
        $amount=static function(string $value):string{
            $parts=explode('.',$value);$whole=ltrim($parts[0],'0');
            return ($whole===''?'0':$whole).'.'.str_pad($parts[1]??'',2,'0');
        };
        foreach($rows as $raw){
            $entry=FinanceMapper::n11($raw);$day=$entry['transactionDate'];$days[$day]=true;
            $identity=[$day,$entry['expectedDate'],$raw['status'],$amount((string)$raw['paymentAmount']),$amount((string)$raw['deductionAmount']),$entry['netCents']];
            $fingerprint=hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
            if(isset($seen[$fingerprint]))throw new SyncFailure(409);$seen[$fingerprint]=true;
            $group=hash('sha256',json_encode([$day,$entry['expectedDate'],$entry['state']],JSON_THROW_ON_ERROR));
            if(isset($groups[$group])){
                $sum=$groups[$group]['netCents']+$entry['netCents'];
                if(!is_int($sum))throw new SyncFailure(422);
                $groups[$group]['netCents']=$sum;
            }else{$groups[$group]=$entry;$dayGroups[$day][$group]=true;}
        }
        // n11 may report settlement-day count rather than installment-row count.
        // Both forms still require a complete page; missing days/rows remain errors.
        if(count($rows)!==$expected&&count($days)!==$expected)throw new SyncFailure(422);
        foreach($groups as $group=>&$entry){
            if(count($dayGroups[$entry['transactionDate']])>1)$entry['key']=hash('sha256','n11:group:'.$group);
        }unset($entry);
        return array_values($groups);
    }
}
