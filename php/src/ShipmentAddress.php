<?php
declare(strict_types=1);
namespace Omnigo;

/** Remove only duplicates of structured columns, retaining meaningful street names. */
final class ShipmentAddress
{
    private static function key(string $value):string
    {
        return mb_strtolower(strtr($value,['İ'=>'i','I'=>'i','ı'=>'i','Ş'=>'s','ş'=>'s','Ğ'=>'g','ğ'=>'g','Ü'=>'u','ü'=>'u','Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c']));
    }
    private static function tokens(string $value):array
    {
        preg_match_all('/[\p{L}\p{N}]+/u',$value,$matches,PREG_OFFSET_CAPTURE);
        return array_map(fn($match)=>['text'=>self::key($match[0]),'start'=>$match[1],'end'=>$match[1]+strlen($match[0])],$matches[0]);
    }
    public static function clean(string $address,array $columns):string
    {
        $address=trim($address);if($address==='')return '';
        $email=trim((string)($columns['recipientEmail']??''));
        if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL))$address=preg_replace('/(?<![\w@.])'.preg_quote($email,'/').'(?![\w@.])/iu',' ',$address);
        $phone=preg_replace('/\D/','',(string)($columns['phone']??''));
        if(strlen($phone)>=10){$phone=substr($phone,-10);$pattern=implode('[\s().+\/-]*',str_split($phone));$address=preg_replace('/(?<!\d)(?:(?:\+?90|0)[\s().+\/-]*)?'.$pattern.'(?!\d)/u',' ',$address);}
        $tokens=self::tokens($address);$remove=[];
        $street=['mah','mahalle','mahallesi','cad','cadde','caddesi','cd','sok','sokak','sokagi','sk','bulvar','bulvari','blv','apartman','apartmani','apt','sitesi','site','is','pasaji','plaza'];
        foreach(['recipientName','customerName','invoiceName','province','district'] as $field){
            $value=self::tokens(trim((string)($columns[$field]??'')));$length=count($value);
            if(!$length||(!in_array($field,['province','district'],true)&&$length<2))continue;
            $needle=array_column($value,'text');
            for($i=0;$i<=count($tokens)-$length;$i++){
                if(array_column(array_slice($tokens,$i,$length),'text')!==$needle)continue;
                // "Ankara Caddesi" / "Sanayi Mahallesi" remain part of the open address.
                if(in_array($tokens[$i+$length]['text']??'',$street,true))continue;
                $remove[]=[$tokens[$i]['start'],$tokens[$i+$length-1]['end']];
            }
        }
        // Byte offsets refer to the unchanged input; merge overlaps before replacing backwards.
        usort($remove,fn($a,$b)=>$a[0]<=>$b[0]);$ranges=[];
        foreach($remove as [$start,$end]){if($ranges&&$start<=$ranges[count($ranges)-1][1])$ranges[count($ranges)-1][1]=max($ranges[count($ranges)-1][1],$end);else $ranges[]=[$start,$end];}
        foreach(array_reverse($ranges) as [$start,$end])$address=substr_replace($address,' ',$start,$end-$start);
        $address=preg_replace('/\b(?:tel(?:efon)?|gsm|e-?posta|email|alıcı|alici|ad\s*soyad)\s*[:;]\s*(?=[,;\/\n]|$)/iu',' ',$address);
        $address=preg_replace('/[\t ]+/u',' ',$address);$address=preg_replace('/(?:\s*[,;\/]\s*){2,}/u',', ',$address);
        $address=preg_replace('/^[\s,;\/()\-]+|[\s,;\/()\-]+$/u','',$address);
        $address=preg_replace('/\n\s*\n+/u',"\n",$address);
        return trim($address);
    }
    public static function payload(array $payload):array
    {
        if(($payload['source']['kind']??'')==='manual')return $payload;
        foreach(['deliveryAddress','invoiceAddress'] as $field)if(isset($payload[$field]))$payload[$field]=self::clean((string)$payload[$field],$payload);
        return $payload;
    }
}
