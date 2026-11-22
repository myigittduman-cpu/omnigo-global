<?php
declare(strict_types=1);
namespace Omnigo;

/** Called only after session, CSRF, tenant and inventory permission checks. */
final class InventoryImageRoutes
{
    public function __construct(private Database $db) {}
    public function handle(int $company,string $method,array $user):void
    {
        $images=new InventoryImages($this->db,new InventoryImageFile(dirname(__DIR__).'/storage/inventory-images'));
        if($method==='GET'){
            $bytes=$images->read($company,(string)($_GET['productKey']??''));
            header('Content-Type: image/png');header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');echo $bytes;exit;
        }
        Security::admin($user);
        if($method==='POST'){
            $input=$_POST;$file=$_FILES['image']??null;
            if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new \InvalidArgumentException('Görsel yüklenemedi. En fazla 5 MB boyutunda PNG, JPEG veya WebP seçin.');
            if(($file['size']??0)>5*1024*1024)throw new \InvalidArgumentException('Görsel en fazla 5 MB olabilir.');
            $bytes=file_get_contents($file['tmp_name']);
            if($bytes===false)throw new \RuntimeException('Görsel okunamadı.');
        }elseif($method==='DELETE'){
            $input=Http::body();$bytes=null;
        }else{Http::fail(405,'Bu görsel işlemi desteklenmiyor.');}
        $version=filter_var($input['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($version===false||!is_string($input['productKey']??null))throw new \InvalidArgumentException('Ürün bilgilerini yenileyip tekrar deneyin.');
        Http::json($images->change($company,$input['productKey'],$version,$bytes,(int)$user['id']));
    }
}
