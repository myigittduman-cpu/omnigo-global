<?php
declare(strict_types=1);
namespace Omnigo;

/** Search only the shipment fields the administrator can see. */
final class ShipmentSearch
{
    private array $terms;
    private array $fields;
    private bool $searchPhone;

    public function __construct(string $query,?array $fields=null)
    {
        $this->fields=$fields??[...ShipmentRepository::FIELDS,...ShipmentRepository::OPTIONAL_FIELDS];
        $this->searchPhone=in_array('phone',$this->fields,true);
        $phone=preg_replace('/\D/','',$query);
        $this->terms=$this->searchPhone&&preg_match('/^\+?[\d\s()\-]+$/D',$query)&&strlen($phone)>=5
            ?[$phone]:explode(' ',self::normalize($query));
    }
    public static function query(mixed $value): string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>120||preg_match('/[\x00-\x1F\x7F]/',$value))Http::fail(400,'Arama metni en fazla 120 karakter olmalı.');
        return trim($value);
    }
    public function matches(array $payload): bool
    {
        $values=[];
        foreach($this->fields as $field){
            $value=$payload[$field]??'';
            if($field==='invoiceCents'&&is_int($value)){$values[]=number_format($value/100,2,',','');$value=number_format($value/100,2,',','.');}
            if($field==='orderedAt'&&$value)$value=(new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i');
            if(is_scalar($value))$values[]=(string)$value;
        }
        if($this->searchPhone)$values[]=preg_replace('/\D/','',(string)($payload['phone']??''));
        $text=self::normalize(implode(' ',$values));
        foreach($this->terms as $term)if($term!==''&&!str_contains($text,$term))return false;
        return true;
    }
    private static function normalize(string $value): string
    {
        $value=mb_strtolower(strtr($value,['İ'=>'i','I'=>'i']),'UTF-8');
        $value=strtr($value,['ı'=>'i','ç'=>'c','ğ'=>'g','ö'=>'o','ş'=>'s','ü'=>'u',"\u{0307}"=>'']);
        return trim(preg_replace('/\s+/u',' ',$value));
    }
}
