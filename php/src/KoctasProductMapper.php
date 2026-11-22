<?php
declare(strict_types=1);
namespace Omnigo;

/** Product catalogue fields only; never persists buyer information. */
final class KoctasProductMapper
{
    public static function map(array $line,int $gross,int $cancel,int $refund): array
    {
        $quantity=is_int($line['quantity']??null)&&$line['quantity']>=0?$line['quantity']:null;
        $netQuantity=$quantity;
        foreach ($line['cancelations']??[] as $item) $netQuantity=self::subtract($netQuantity,$item);
        $cancelledQuantity=in_array($line['order_line_state']??'',['CANCELED','REFUSED'],true)?$quantity
            :($netQuantity!==null&&$quantity!==null&&$netQuantity>=0?$quantity-$netQuantity:null);
        foreach ($line['refunds']??[] as $item) if (($item['refund_state']??'')==='REFUNDED') $netQuantity=self::subtract($netQuantity,$item);
        if (in_array($line['order_line_state']??'',['CANCELED','REFUSED','STAGING'],true)) $netQuantity=0;
        if ($netQuantity!==null && $netQuantity<0) $netQuantity=null;
        $sku=self::text($line['offer_sku']??$line['product_sku']??'',200);
        $title=self::text($line['product_title']??'',500);
        $pricing=ProfitabilityMarketplacePricing::koctas($line);
        return ['sku'=>$sku,'title'=>$title?:($sku?:'Ürün adı bekleniyor'),'imageUrl'=>self::mediaUrl($line['product_medias']??[]),'quantity'=>$quantity,'netQuantity'=>$netQuantity,'cancelledQuantity'=>$cancelledQuantity,
            'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$refund,'netCents'=>$gross-$cancel-$refund,
            ...($pricing===ProfitabilityOrderPricing::UNKNOWN?[]:['orderPricing'=>$pricing])];
    }
    public static function mediaUrl(array $medias): ?string
    {
        $priority=['LARGE'=>3,'MEDIUM'=>2,'SMALL'=>1];
        usort($medias,fn($a,$b)=>($priority[strtoupper($b['type']??'')]??0)<=>($priority[strtoupper($a['type']??'')]??0));
        foreach ($medias as $media) {
            $mime=strtolower((string)($media['mime_type']??''));
            if (!in_array($mime,['image/jpeg','image/png','image/webp','image/gif','jpg','jpeg','png','webp','.jpg','.png'],true)) continue;
            $candidate=self::imageUrl($media['media_url']??null);
            if ($candidate!==null) return $candidate;
        }
        return null;
    }
    private static function subtract(?int $quantity,array $item): ?int
    {
        if ($quantity===null) return null;
        $removed=$item['quantity']??null;
        return is_int($removed)&&$removed>=0?$quantity-$removed:null;
    }
    private static function text(mixed $value,int $limit): string { return is_string($value)?mb_substr(trim($value),0,$limit):''; }
    public static function imageUrl(mixed $value): ?string
    {
        if (is_string($value)&&preg_match('#^/media/product/image/[a-f0-9-]{36}$#iD',$value)) $value='https://koctas.mirakl.net'.$value;
        if (!is_string($value)||strlen($value)>2048||!filter_var($value,FILTER_VALIDATE_URL)) return null;
        $url=parse_url($value);$host=strtolower($url['host']??'');
        if (($url['scheme']??'')!=='https'||isset($url['user'])||isset($url['pass'])||isset($url['port'])||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)||str_ends_with($host,'.local')) return null;
        return $value;
    }
}
