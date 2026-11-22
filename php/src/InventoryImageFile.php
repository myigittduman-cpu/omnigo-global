<?php
declare(strict_types=1);
namespace Omnigo;

/** Private, re-encoded product photos. Never accepts a filesystem path from the browser. */
final class InventoryImageFile
{
    public function __construct(private string $folder) {}

    public static function normalize(string $bytes): string
    {
        if(strlen($bytes)>5242880||strlen($bytes)<8)throw new \InvalidArgumentException('Görsel en fazla 5 MB olmalı.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>16000000) {
            throw new \InvalidArgumentException('En fazla 4096 × 4096 piksel (16 megapiksel) PNG, JPEG veya WebP seçin.');
        }
        if(!function_exists('imagecreatefromstring'))throw new \RuntimeException('Sunucuda görsel işleme hazır değil.');
        $source=@imagecreatefromstring($bytes);
        if(!$source)throw new \InvalidArgumentException('Görsel okunamadı.');
        $ratio=min(1,1024/$info[0],1024/$info[1]);
        $image=imagecreatetruecolor(max(1,(int)round($info[0]*$ratio)),max(1,(int)round($info[1]*$ratio)));
        imagealphablending($image,false);imagesavealpha($image,true);
        imagecopyresampled($image,$source,0,0,0,0,imagesx($image),imagesy($image),$info[0],$info[1]);
        ob_start();imagepng($image,null,6);$safe=ob_get_clean();
        imagedestroy($source);imagedestroy($image);
        if(!is_string($safe)||$safe==='')throw new \RuntimeException('Görsel hazırlanamadı.');
        return $safe;
    }

    private function path(int $company,string $key): string
    {
        if($company<1||!preg_match('/^[a-f0-9]{48}$/D',$key))throw new \OutOfBoundsException('Görsel bulunamadı.');
        return $this->folder.'/'.$company.'/'.$key.'.png';
    }

    public function write(int $company,string $safe): string
    {
        $key=bin2hex(random_bytes(24));$path=$this->path($company,$key);$folder=dirname($path);
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new \RuntimeException('Görsel klasörü hazırlanamadı.');
        $file=@fopen($path,'xb');if(!$file)throw new \RuntimeException('Görsel kaydedilemedi.');
        try{if(fwrite($file,$safe)!==strlen($safe))throw new \RuntimeException('Görsel kaydedilemedi.');}
        catch(\Throwable $e){fclose($file);@unlink($path);throw $e;}
        fclose($file);chmod($path,0600);return $key;
    }

    public function read(int $company,string $key): string
    {
        $path=$this->path($company,$key);
        if(!is_file($path))throw new \OutOfBoundsException('Görsel bulunamadı.');
        $bytes=file_get_contents($path);if($bytes===false)throw new \RuntimeException('Görsel okunamadı.');return $bytes;
    }

    public function remove(int $company,string $key): void
    {
        $path=$this->path($company,$key);if(is_file($path))@unlink($path);
    }
}
