<?php
declare(strict_types=1);
namespace Omnigo;

/** One-time, audited adoption of old spreadsheet rows. Ambiguity aborts the transaction. */
final class PttavmLegacyLink
{
    private static function normal(string $value): string
    {
        return preg_replace('/[^\pL\pN]/u','',mb_strtolower(strtr($value,['İ'=>'i','I'=>'i','ı'=>'i'])));
    }
    public static function matches(array $saved,array $source): bool
    {
        if(strtotime($saved['orderedAt']??'')!==strtotime($source['orderedAt']??''))return false;
        $phone=static fn($value)=>substr(preg_replace('/\D/','',(string)$value),-10);
        if(strlen($phone($saved['phone']??''))!==10||$phone($saved['phone'])!==$phone($source['phone']??''))return false;
        if(($saved['quantity']??0)!==($source['quantity']??0)||($saved['province']??'')!==($source['province']??''))return false;
        $a=self::normal(preg_replace('/\(\s*GİTTİ\s*\)/u','',$saved['productName']??''));$b=self::normal($source['productName']??'');
        if(min(mb_strlen($a),mb_strlen($b))<24||(!str_starts_with($a,$b)&&!str_starts_with($b,$a)))return false;
        $total=(int)$source['invoiceCents'];$unit=(int)round($total/$source['quantity']);$price=$saved['invoiceCents']??-9999;
        return min(abs($price-$total),abs($price-$unit))<=2;
    }
    public static function plan(array $legacy,array $mapped): array
    {
        $lines=[];foreach($mapped as $order)foreach($order['delivery']['shipmentItems'] as $line)$lines[$line['key']]=['deliveryId'=>$order['delivery']['externalId'],...$line];
        $plan=[];$used=[];
        foreach($legacy as $row){
            if($row['sourceKey']!==null||$row['removedAt']!==null)continue;
            $matches=array_filter($lines,fn($line)=>self::matches($row['value'],$line['payload']));
            if(count($matches)!==1)throw new \RuntimeException('Eski PttAVM satırı kesin eşleşmedi: '.(int)$row['id']);
            $line=array_values($matches)[0];if(isset($used[$line['key']]))throw new \RuntimeException('Bir kaynak satırı birden fazla eski kayda uyuyor.');
            $used[$line['key']]=true;$plan[]=['id'=>(int)$row['id'],'line'=>$line];
        }
        return $plan;
    }
    public static function apply(Database $db,DeliveryCipher $cipher,int $company,array $mapped): int
    {
        if(!$db->pdo->inTransaction())throw new \LogicException('Firma işlem kilidi gerekli.');
        $rows=$db->all("SELECT * FROM shipment_entries WHERE company_id=? AND provider='PTT AVM' AND source_key IS NULL AND removed_at IS NULL".$db->lock(),[$company]);
        $legacy=array_map(fn($row)=>['id'=>(int)$row['id'],'sourceKey'=>$row['source_key'],'removedAt'=>$row['removed_at'],'value'=>$cipher->open($company,'shipment:'.$row['id'],$row['payload'])],$rows);
        $plan=self::plan($legacy,$mapped);$values=array_column($legacy,'value','id');
        foreach($plan as $item){
            $id=$item['id'];$payload=$values[$id];$line=$item['line'];
            $payload['source']=['kind'=>'marketplace','provider'=>'PTT AVM','legacyLinked'=>true,
                'locked'=>array_values(array_diff(array_keys($payload),['packageStatus','marketplaceShipping','marketplaceRowStatus']))];
            $payload['sourceDeliveryId']=$line['deliveryId'];$payload['sourceDeliveryIds']=[$line['deliveryId']];
            $db->run('UPDATE shipment_entries SET source_key=?,payload=?,version=version+1,updated=? WHERE id=? AND company_id=?',
                [hash('sha256','PTT AVM:'.$line['key']),$cipher->seal($company,'shipment:'.$id,$payload),gmdate('c'),$id,$company]);
            $db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,NULL,?,?)',
                [$company,'shipment.pttavm_link',$id,json_encode(['sourceKey'=>hash('sha256','PTT AVM:'.$line['key']),'existingFieldsPreserved'=>true]),gmdate('c')]);
        }
        return count($plan);
    }
}
