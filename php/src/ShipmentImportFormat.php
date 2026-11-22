<?php
declare(strict_types=1);
namespace Omnigo;

/** Converts marketplace export columns without inferring missing personal details. */
final class ShipmentImportFormat
{
    public static function columns(array $columns,string $provider,int $line): array
    {
        if($provider==='Hepsiburada'){
            if(!in_array(count($columns),[14,15],true))throw new \InvalidArgumentException("Kayıt $line: Hepsiburada için 14 veya 15 sütun olmalı.");
            return [array_pad($columns,15,''),''];
        }
        if($provider==='PTT AVM'){
            if(count($columns)!==15)throw new \InvalidArgumentException("Kayıt $line: PttAVM için 15 sütun olmalı.");
            if($columns[13]==='Bireysel Fatura')$columns[13]='Bireysel';
            $tax=$columns[14];$columns[14]='';return self::withTax($columns,$tax,$line);
        }
        if($provider==='idefix'){
            if(count($columns)!==15)throw new \InvalidArgumentException("Kayıt $line: İdefix için 15 sütun olmalı.");
            return self::withTax([$columns[0],$columns[1],$columns[7],$columns[3],$columns[8],$columns[9],$columns[10],$columns[4],$columns[6],$columns[5],$columns[11],$columns[2],$columns[12],$columns[14],''],$columns[13],$line);
        }
        if($provider==='Koçtaş'){
            if(count($columns)!==14)throw new \InvalidArgumentException("Kayıt $line: Koçtaş için 14 sütun olmalı.");
            return self::withTax([$columns[0],$columns[1],$columns[6],$columns[2],$columns[7],$columns[8],$columns[9],$columns[3],$columns[5],$columns[4],$columns[10],'',$columns[11],$columns[13],''],$columns[12],$line);
        }
        if($provider==='Pazarama'){
            if(count($columns)!==16)throw new \InvalidArgumentException("Kayıt $line: Pazarama için 16 sütun olmalı.");
            if(preg_match('/^[0-9]+,00$/D',$columns[2]))$columns[2]=substr($columns[2],0,-3);
            return self::withTax([$columns[0],$columns[4],$columns[10],$columns[5],$columns[7],$columns[8],$columns[9],$columns[1],$columns[3],$columns[2],$columns[11],$columns[6],$columns[10],$columns[14],''],$columns[15],$line);
        }
        if($provider==='n11'&&count($columns)===17){
            // Historical export combines tax number/office, then ends with recipient name.
            return self::withTax([$columns[0],$columns[4],$columns[16],$columns[5],$columns[7],$columns[9],$columns[8],$columns[1],$columns[3],$columns[2],$columns[11],$columns[6],$columns[10],$columns[14],''],$columns[15],$line);
        }
        if($provider!=='n11'||count($columns)!==19)throw new \InvalidArgumentException("Kayıt $line: N11 için 17 veya 19 sütun olmalı.");
        $corporate=$columns[14]==='Kurumsal';
        $taxNumber=$corporate?$columns[16]:'';
        if($taxNumber!==''&&!preg_match('/^[0-9]{1,20}$/D',$taxNumber))throw new \InvalidArgumentException("Kayıt $line: Vergi numarası biçimi geçersiz.");
        $invoiceAddress=$columns[11];
        if($corporate&&$columns[18]!=='')$invoiceAddress.="\nV.D.: ".$columns[18];
        // Confirmed source: customer=5, invoice name=11, recipient=18 (one-based).
        return [[$columns[0],$columns[4],$columns[17],$columns[5],$columns[7],$columns[9],$columns[8],
            $columns[1],$columns[3],$columns[2],$invoiceAddress,$columns[6],$columns[10],$columns[14],''],$taxNumber];
    }
    public static function date(string $value,string $month,int $line): \DateTimeImmutable
    {
        $value=preg_replace('/^(\d{2}\.\d{2}\.\d{4}) - (\d{2}:\d{2}(?::\d{2})?)$/D','$1 $2',$value);
        $value=preg_replace('/^(\d{1,2})-([\p{L}]+)-(\d{4}) (\d{2}:\d{2}(?::\d{2})?)$/Du','$1 $2 $3 $4',$value);
        $value=preg_replace('/^(\d{1,2}) ([\p{L}]+) (?:Pzt|Sal|Çar|Per|Cum|Cmt|Paz) (\d{4}) (\d{2}:\d{2}:\d{2})$/Du','$1 $2 $3 $4',$value);
        $months=['Oca'=>1,'Ocak'=>1,'Şub'=>2,'Şubat'=>2,'Mar'=>3,'Mart'=>3,'Nis'=>4,'Nisan'=>4,'May'=>5,'Mayıs'=>5,'Haz'=>6,'Haziran'=>6,'Tem'=>7,'Temmuz'=>7,'Ağu'=>8,'Ağustos'=>8,'Eyl'=>9,'Eylül'=>9,'Eki'=>10,'Ekim'=>10,'Kas'=>11,'Kasım'=>11,'Ara'=>12,'Aralık'=>12];
        if(preg_match('/^(\d{1,2}) ([\p{L}]+) (\d{4})(?: \3)?,? (\d{2}:\d{2}(?::\d{2})?)$/Du',$value,$parts)&&isset($months[$parts[2]])){
            $value=sprintf('%02d.%02d.%04d %s',(int)$parts[1],$months[$parts[2]],(int)$parts[3],$parts[4]);
        }elseif(preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}(?::\d{2})?$/D',$value))$value=str_replace('/','.',$value);
        $format=strlen($value)===19?'d.m.Y H:i:s':'d.m.Y H:i';
        $date=\DateTimeImmutable::createFromFormat('!'.$format,$value,new \DateTimeZone('Europe/Istanbul'));
        if(!$date||$date->format($format)!==$value||$date->format('Y-m')!==$month)throw new \InvalidArgumentException("Kayıt $line: Sipariş tarihi seçili ayla uyumlu değil.");
        return $date;
    }
    private static function withTax(array $columns,string $taxInfo,int $line): array
    {
        $type=mb_strtolower(str_replace('İ','i',$columns[13]),'UTF-8');
        $columns[13]=['kurumsal'=>'Kurumsal','bireysel'=>'Bireysel'][$type]??$columns[13];
        $taxNumber='';
        if($columns[13]==='Kurumsal'&&$taxInfo!==''){
            $office=[];
            foreach(array_map('trim',explode('/',$taxInfo)) as $part){
                if(preg_match('/^[0-9]{1,20}$/D',$part)){
                    if($taxNumber!=='')throw new \InvalidArgumentException("Kayıt $line: Vergi bilgisi belirsiz.");
                    $taxNumber=$part;
                }elseif($part!=='')$office[]=$part;
            }
            if($office)$columns[10].="\nV.D.: ".implode(' / ',$office);
        }
        return [$columns,$taxNumber];
    }
}
