<?php
declare(strict_types=1);
namespace Omnigo;

/** Encrypted, immutable evidence files outside the public web root. */
final class OperationFiles
{
    public function __construct(private DeliveryCipher $cipher,private string $folder){}
    public static function prepare(string $bytes):array
    {
        if(str_starts_with($bytes,'%PDF-')){ShipmentPdf::validate($bytes);return ['bytes'=>$bytes,'mime'=>'application/pdf'];}
        if(strlen($bytes)>5242880)Http::fail(413,'Görsel en fazla 5 MB olabilir.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||$info[0]>4000||$info[1]>4000||$info[0]*$info[1]>16000000)Http::fail(415,'Geçerli bir JPEG, PNG, WebP veya PDF seçin.');
        if(!function_exists('imagecreatefromstring'))Http::fail(503,'Görsel işleme hazır değil.');
        $image=@imagecreatefromstring($bytes);if(!$image)Http::fail(415,'Görsel okunamadı.');
        ob_start();imagejpeg($image,null,90);$safe=ob_get_clean();imagedestroy($image);
        if(!is_string($safe)||strlen($safe)>5242880)Http::fail(413,'Görsel daha küçük olmalı.');
        return ['bytes'=>$safe,'mime'=>'image/jpeg','width'=>$info[0],'height'=>$info[1]];
    }
    private function path(string $key):string
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$key))Http::fail(404,'Dosya bulunamadı.');
        return $this->folder.'/'.$key.'.bin';
    }
    public function write(int $company,string $dossier,string $key,array $file):void
    {
        if(!is_dir($this->folder)&&!mkdir($this->folder,0700,true)&&!is_dir($this->folder))Http::fail(503,'Belge klasörü hazırlanamadı.');
        $bytes=$this->cipher->seal($company,'operation-file:'.$dossier.':'.$key,['bytes'=>base64_encode($file['bytes'])]);
        $handle=fopen($this->path($key),'xb');if(!$handle)Http::fail(503,'Dosya kaydedilemedi.');
        try{if(fwrite($handle,$bytes)!==strlen($bytes))Http::fail(503,'Dosya kaydedilemedi.');}finally{fclose($handle);}
        chmod($this->path($key),0600);
    }
    public function read(int $company,string $dossier,array $meta):array
    {
        $path=$this->path($meta['key']);if(!is_file($path)||filesize($path)>8000000)Http::fail(404,'Dosya bulunamadı.');
        $value=$this->cipher->open($company,'operation-file:'.$dossier.':'.$meta['key'],file_get_contents($path));
        $size=$meta['mime']==='image/jpeg'?getimagesizefromstring(base64_decode($value['bytes'],true)):[0,0];
        return [...$value,'mime'=>$meta['mime'],'name'=>$meta['name'],'width'=>$size[0],'height'=>$size[1]];
    }
    public function discard(string $key):void{if(is_file($this->path($key)))unlink($this->path($key));}
}
