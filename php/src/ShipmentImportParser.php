<?php
declare(strict_types=1);
namespace Omnigo;

/** Marketplace pasted exports, including quoted multiline addresses. */
final class ShipmentImportParser
{
    public static function parse(string $source,string $month,string $provider='Hepsiburada'): array
    {
        if(strlen($source)>2097152||!mb_check_encoding($source,'UTF-8'))throw new \InvalidArgumentException('Dosya boyutu veya metin biçimi geçersiz.');
        $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$source));rewind($stream);
        $items=[];$line=0;
        try{
            while(($columns=fgetcsv($stream,null,"\t",'"',''))!==false){
                if($columns===[null])continue;$line++;
                $columns=array_map(fn($value)=>preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u','',(string)$value),$columns);
                [$columns,$taxNumber]=ShipmentImportFormat::columns($columns,$provider,$line);
                $date=ShipmentImportFormat::date($columns[0],$month,$line);
                $province=self::city($columns[5]);
                ShipmentRepository::selection(['city'=>$province,'provider'=>$provider,'month'=>$month]);
                $cents=null;
                if($columns[8]!==''){
                    if(!preg_match('/^(?:\d{1,3}(?:\.\d{3})*|\d+)(?:,\d{1,2})?$/D',$columns[8]))throw new \InvalidArgumentException("Kayıt $line: Fatura tutarı geçersiz.");
                    $amount=explode(',',str_replace('.','',$columns[8]));$cents=(int)($amount[0].str_pad($amount[1]??'',2,'0'));
                }
                if($cents>99999999999)throw new \InvalidArgumentException("Kayıt $line: Fatura tutarı sınırı aşıldı.");
                if(!ctype_digit($columns[9])||(int)$columns[9]<1||(int)$columns[9]>100000)throw new \InvalidArgumentException("Kayıt $line: Adet geçersiz.");
                if($columns[11]!==''&&!filter_var($columns[11],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException("Kayıt $line: E-posta geçersiz.");
                if(!in_array($columns[13],['Bireysel','Kurumsal'],true))throw new \InvalidArgumentException("Kayıt $line: Müşteri tipi geçersiz.");
                foreach($columns as $value)if(mb_strlen($value)>8000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new \InvalidArgumentException("Kayıt $line: Alan içeriği geçersiz.");
                // Recipient/contact fields can be absent in historical exports; never infer them.
                if($columns[1]===''&&$columns[7]==='')throw new \InvalidArgumentException("Kayıt $line: Temel sipariş alanı eksik.");
                // Source order ends with invoice ADDRESS, email, invoice NAME, type, status.
                $payload=['orderedAt'=>$date->format(\DateTimeInterface::ATOM),'customerName'=>$columns[1],'recipientName'=>$columns[2],
                    'phone'=>$columns[3],'deliveryAddress'=>$columns[4],'province'=>$province,'district'=>$columns[6],
                    'productName'=>$columns[7],'invoiceCents'=>$cents,'quantity'=>(int)$columns[9],
                    'invoiceName'=>$columns[12],'invoiceAddress'=>$columns[10],'recipientEmail'=>$columns[11],
                    'customerType'=>$columns[13],'packageStatus'=>$columns[14]];
                if($taxNumber!=='')$payload['taxNumber']=$taxNumber;
                $items[]=['city'=>$province,'provider'=>$provider,'month'=>$month,'sourceRow'=>$line,'payload'=>$payload];
                if(count($items)>2000)throw new \InvalidArgumentException('Tek aktarımda en fazla 2000 kayıt alınabilir.');
            }
        }finally{fclose($stream);}
        if(!$items)throw new \InvalidArgumentException('Dosyada kayıt bulunamadı.');
        return $items;
    }
    private static function city(string $value): string
    {
        $normalize=fn($text)=>mb_strtolower(str_replace(['İ','I'],['i','ı'],$text),'UTF-8');
        foreach(ShipmentRepository::CITIES as $city)if($normalize($city)===$normalize($value))return $city;
        throw new \InvalidArgumentException('Dosyada desteklenmeyen teslimat ili var.');
    }
}
