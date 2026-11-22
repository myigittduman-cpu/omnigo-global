<?php
declare(strict_types=1);
namespace Omnigo;
/** Immutable encrypted files; no public URL or caller-supplied filesystem path. */
final class PrivateDocumentFile
{
    public function __construct(private DeliveryCipher $cipher,private string $folder){}
    private function path(string $key):string
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$key))Http::fail(404,'Belge bulunamadı.');
        return $this->folder.'/'.$key.'.bin';
    }
    public function write(int $company,string $reference,string $kind,string $bytes):array
    {
        $mime='application/pdf';$info=[0,0];$safe=$bytes;
        if(str_starts_with($bytes,'%PDF-'))ShipmentPdf::validate($bytes);
        else{
            if(strlen($bytes)>2097152||strlen($bytes)<8)Http::fail(413,'İşlenen görsel en fazla 2 MB, PDF en fazla 4 MB olabilir.');
            $info=@getimagesizefromstring($bytes);
            if(!$info||$info['mime']!=='image/jpeg'||$info[0]>4000||$info[1]>4000||$info[0]*$info[1]>16000000)Http::fail(415,'Geçerli bir JPEG görseli seçin.');
            if(!function_exists('imagecreatefromstring'))Http::fail(503,'Sunucuda görsel işleme hazır değil.');
            $image=@imagecreatefromstring($bytes);if(!$image)Http::fail(415,'Görsel okunamadı.');
            ob_start();imagejpeg($image,null,92);$safe=ob_get_clean();imagedestroy($image);$mime='image/jpeg';
        }
        if(!is_dir($this->folder)&&!mkdir($this->folder,0700,true)&&!is_dir($this->folder))Http::fail(503,'Belge klasörü hazırlanamadı.');
        $key=bin2hex(random_bytes(24));$encrypted=$this->cipher->seal($company,'order-document:'.$reference.':'.$kind.':'.$key,['bytes'=>base64_encode($safe)]);
        $file=$this->path($key);$handle=@fopen($file,'xb');if(!$handle)Http::fail(503,'Belge saklanamadı.');
        try{if(fwrite($handle,$encrypted)!==strlen($encrypted))Http::fail(503,'Belge saklanamadı.');}catch(\Throwable $e){fclose($handle);@unlink($file);throw $e;}
        fclose($handle);chmod($file,0600);
        return ['key'=>$key,'mime'=>$mime,'width'=>$info[0],'height'=>$info[1],'hash'=>hash('sha256',$bytes)];
    }
    public function read(int $company,string $reference,string $kind,array $meta):array
    {
        $path=$this->path($meta['key']);if(!is_file($path)||filesize($path)>8000000)Http::fail(404,'Belge dosyası bulunamadı.');
        $value=$this->cipher->open($company,'order-document:'.$reference.':'.$kind.':'.$meta['key'],file_get_contents($path));
        return ['bytes'=>$value['bytes'],'mime'=>$meta['mime'],'width'=>$meta['width'],'height'=>$meta['height']];
    }
    public function remove(string $key):void{if(is_file($file=$this->path($key)))@unlink($file);}
}
