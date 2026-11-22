<?php
declare(strict_types=1);
namespace Omnigo;

/** Preserve the statement's amounts and rates without assuming VAT inclusion rules. */
final class PaymentDetails
{
    public static function validate(array $data): array
    {
        $details=$data['details']??[];
        if(!is_array($details)||!array_is_list($details)||count($details)>40)Http::fail(400,'En fazla 40 hakediş detayı ekleyebilirsiniz.');
        $rows=[];
        foreach($details as $row){
            // Http::body preserves nested JSON objects; internal callers also pass arrays.
            if($row instanceof \stdClass)$row=(array)$row;
            if(!is_array($row))Http::fail(400,'Hakediş detayını kontrol edin.');
            $amount=$row['amountCents']??null;
            if($amount!==null&&(!is_int($amount)||abs($amount)>100000000000))Http::fail(400,'Kalem tutarını kontrol edin.');
            $rate=($row['rateBasisPoints']??null)===null?null:Validation::integer($row['rateBasisPoints']);
            if($rate!==null&&$rate>10000)Http::fail(400,'Yüzde oranı 0–100 arasında olmalı.');
            if($amount===null&&$rate===null)continue;
            $rows[]=['section'=>Validation::choice($row['section']??null,['sales','commission','service','tax','adjustment']),
                'label'=>Validation::text($row['label']??null,'Kalem adı',120),'amountCents'=>$amount,'rateBasisPoints'=>$rate];
        }
        $periodStart=empty($data['periodStart'])?'':Validation::date($data['periodStart']);
        $periodEnd=empty($data['periodEnd'])?'':Validation::date($data['periodEnd']);
        if(($periodStart==='')!==($periodEnd==='')||($periodStart!==''&&$periodStart>$periodEnd))Http::fail(400,'Dönem başlangıç ve bitiş tarihlerini kontrol edin.');
        return ['details'=>$rows,'periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'taxBasis'=>Validation::choice($data['taxBasis']??'unspecified',['included','excluded','mixed','unspecified']),
            'reference'=>Validation::text($data['reference']??null,'Ekstre / ödeme referansı',160,true),
            'notes'=>Validation::text($data['notes']??null,'Açıklama',2000,true)];
    }
}
