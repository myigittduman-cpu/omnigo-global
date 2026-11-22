<?php
declare(strict_types=1);
namespace Omnigo;

/** Only explicitly approved operational fields survive the API response. */
final class HepsiburadaPreview
{
    public static function map(array $items,string $merchantId): array
    {
        $rows=[];$seen=[];
        foreach($items as $item){
            if(!is_array($item)||strtolower((string)($item['merchantId']??''))!==$merchantId)throw new SyncFailure(422);
            $id=self::text($item['id']??null,80);$number=self::text($item['orderNumber']??null,80);
            if(isset($seen[$id]))throw new SyncFailure(422);$seen[$id]=true;
            $quantity=$item['quantity']??null;$price=$item['totalPrice']??null;
            if(!is_int($quantity)||$quantity<1||$quantity>100000||!is_array($price)||($price['currency']??null)!=='TRY')throw new SyncFailure(422);
            $rows[]=['lineId'=>$id,'orderNumber'=>$number,'quantity'=>$quantity,'amountCents'=>HepsiburadaMoney::cents($price['amount']??null),
                'status'=>self::text($item['status']??null,80),'sku'=>self::text($item['merchantSKU']??$item['merchantSku']??$item['sku']??null,200),
                'title'=>self::text($item['name']??$item['productName']??$item['sku']??null,500)];
        }
        return ['items'=>$rows,'merchantVerified'=>count($rows)>0];
    }
    private static function text(mixed $value,int $max): string
    {
        if(!is_string($value)||$value===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new SyncFailure(422);
        return $value;
    }
}
