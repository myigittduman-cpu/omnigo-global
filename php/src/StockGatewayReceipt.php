<?php
declare(strict_types=1);
namespace Omnigo;

/** A single-item receipt also identifies the confirmed seller, item and target quantity. */
final class StockGatewayReceipt
{
    public static function tag(string $seller,string $item):string
    {
        return substr(hash('sha256',$seller."\0".$item),0,24);
    }
    public static function make(string $id,string $seller,string $item,int $quantity):string
    {
        if(!preg_match('/^[a-zA-Z0-9-]{1,64}$/D',$id))throw new MarketplaceStockFailure(uncertain:true);
        return $id.'.'.$quantity.'.'.self::tag($seller,$item);
    }
    public static function split(string $receipt):array
    {
        if(!preg_match('/^([a-zA-Z0-9-]{1,64})\.(0|[1-9][0-9]{0,8})\.([0-9a-f]{24})$/D',$receipt,$parts))throw new \DomainException('Pazaryeri takip kimliği geçersiz.');
        return [$parts[1],self::quantity((int)$parts[2]),$parts[3]];
    }
    public static function quantity(mixed $value):int
    {
        if(!is_int($value)||$value<0||$value>100000000)throw new \DomainException('Pazaryeri stok adedi doğrulanamadı.');
        return $value;
    }
    public static function code(mixed $value):string
    {
        if(!is_string($value)||$value===''||trim($value)!==$value||mb_strlen($value)>180||preg_match('/[[:cntrl:]]/u',$value))throw new \DomainException('Pazaryeri ürün kimliği doğrulanamadı.');
        return $value;
    }
    public static function seller(mixed $value):string
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value))throw new \DomainException('Pazaryeri mağaza kimliği doğrulanamadı.');
        return (string)$value;
    }
}
