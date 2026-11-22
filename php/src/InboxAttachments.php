<?php
declare(strict_types=1);
namespace Omnigo;

/** Private, encrypted, short-lived files; never exposed under the public directory. */
final class InboxAttachments
{
    public function __construct(private string $folder,private DeliveryCipher $cipher) {}
    public static function local(): self {return new self(dirname(__DIR__).'/storage/inbox-attachments',new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));}
    public static function validate(string $name,string $bytes): array
    {
        if(strlen($bytes)<8||strlen($bytes)>2097152)Http::fail(413,'Her dosya en fazla 2 MB olabilir.');
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));$type=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed=['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
        if(!isset($allowed[$extension])||$allowed[$extension]!==$type)Http::fail(415,'Yalnızca PDF, PNG ve JPEG dosyaları eklenebilir.');
        if($extension==='pdf'&&!str_starts_with($bytes,'%PDF-'))Http::fail(415,'PDF dosyası okunamadı.');
        if($extension!=='pdf'){$image=@getimagesizefromstring($bytes);if(!$image||$image[0]*$image[1]>40000000)Http::fail(415,'Görsel dosyası geçersiz veya çok büyük.');}
        $safe=mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u','_',basename($name)),0,150);
        return ['name'=>$safe,'type'=>$type,'size'=>strlen($bytes),'extension'=>$extension];
    }
    public function save(int $company,int $thread,int $user,string $name,string $bytes): array
    {
        $meta=self::validate($name,$bytes);$id=bin2hex(random_bytes(24));
        if(!is_dir($this->folder)&&!mkdir($this->folder,0700,true)&&!is_dir($this->folder))Http::fail(503,'Dosya klasörü hazırlanamadı.');
        $used=0;foreach(glob($this->folder.'/*.bin')?:[] as $stored)$used+=filesize($stored);
        if($used>134217728)Http::fail(429,'Dosya alanı dolu. Bekleyen gönderimler tamamlandıktan sonra yeniden deneyin.');
        $payload=$this->cipher->seal($company,'inbox-attachment:'.$thread.':'.$id,['user'=>$user,'created'=>time(),'meta'=>$meta,'bytes'=>base64_encode($bytes)]);
        $file=fopen($this->path($id),'xb');if(!$file)Http::fail(503,'Dosya saklanamadı.');
        try{if(fwrite($file,$payload)!==strlen($payload))Http::fail(503,'Dosya saklanamadı.');}finally{fclose($file);}
        chmod($this->path($id),0600);return ['id'=>$id,...$meta];
    }
    public function load(int $company,int $thread,int $user,array $ids,bool $queued=false): array
    {
        if(count($ids)>3||count(array_unique($ids))!==count($ids))Http::fail(400,'En fazla 3 farklı dosya ekleyebilirsiniz.');
        $files=[];
        foreach($ids as $id){
            $path=$this->path($id);if(!is_file($path)||filesize($path)>4000000)Http::fail(404,'Ek dosya bulunamadı; dosyayı yeniden seçin.');
            try{$value=$this->cipher->open($company,'inbox-attachment:'.$thread.':'.$id,file_get_contents($path));}catch(\Throwable){Http::fail(404,'Ek dosya bulunamadı.');}
            if(($value['user']??null)!==$user||(!$queued&&($value['created']??0)<time()-86400))Http::fail(404,'Ek dosyanın süresi dolmuş; yeniden seçin.');
            $bytes=base64_decode($value['bytes'],true);if($bytes===false)Http::fail(422,'Dosya okunamadı.');
            $meta=self::validate($value['meta']['name'],$bytes);$files[]=[...$meta,'bytes'=>$bytes];
        }
        return $files;
    }
    public function remove(array $ids): void {foreach($ids as $id){$path=$this->path($id);if(is_file($path))unlink($path);}}
    public function cleanup(Database $db): void
    {
        $protected=[];
        foreach($db->all("SELECT payload FROM inbox_jobs WHERE kind='reply' AND state IN ('pending','running','unknown')") as $job){$payload=json_decode($job['payload'],true);foreach($payload['attachments']??[] as $id)$protected[$id]=true;}
        foreach(glob($this->folder.'/*.bin')?:[] as $file){$id=basename($file,'.bin');if(preg_match('/^[a-f0-9]{48}$/D',$id)&&!isset($protected[$id])&&filemtime($file)<time()-86400)unlink($file);}
    }
    private function path(mixed $id): string {if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id))Http::fail(400,'Ek dosya bilgisi geçersiz.');return $this->folder.'/'.$id.'.bin';}
}
