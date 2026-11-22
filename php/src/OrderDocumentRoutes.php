<?php
declare(strict_types=1);
namespace Omnigo;
final class OrderDocumentRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user):void
    {
        if(!preg_match('#^/api/orders/(-?[1-9][0-9]*)/documents(?:/(invoice|delivery))?$#D',$path,$m))return;
        $company=$this->security->company($user,$_GET['company']??null);$policy=new CustomerPermissions($this->db,$company);
        $policy->require($user,'orders',$method==='GET'?'documents':($method==='DELETE'?'deleteDocuments':'upload'));
        if($method!=='GET')ClientSessionOperator::requireName($user);
        try{OrderDocumentSchema::ensure($this->db);}catch(\PDOException $error){self::logDatabaseError('schema',$error);throw $error;}
        $cipher=new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');$store=new OrderDocuments($this->db,$cipher,new PrivateDocumentFile($cipher,dirname(__DIR__).'/storage/order-documents'));
        $id=(int)$m[1];$kind=$m[2]??null;
        if($method==='GET'){
            try{$result=$kind?$store->load($user,$company,$id,$kind):$store->listing($user,$company,$id);}
            catch(\PDOException $error){self::logDatabaseError('read',$error);throw $error;}
            Http::json($result);
        }
        if(!$kind)Http::fail(405,'Belge türü seçin.');
        if($method==='POST'){
            $file=$_FILES['file']??null;if(!$file||($file['error']??1)!==UPLOAD_ERR_OK||$file['size']>ShipmentPdf::MAX_BYTES||!is_uploaded_file($file['tmp_name']))Http::fail(400,'Geçerli bir belge seçin.');
            $version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);if($version===false)Http::fail(400,'Belge sürümü geçersiz.');
            Http::json($store->change($user,$company,$id,$kind,$version,(string)($_POST['requestId']??''),file_get_contents($file['tmp_name'])));
        }
        if($method==='DELETE'){$body=Http::body();if(!is_int($body['version']??null))Http::fail(400,'Belge sürümü geçersiz.');Http::json($store->change($user,$company,$id,$kind,$body['version'],(string)($body['requestId']??''),null));}
        Http::fail(405,'Belge işlemi desteklenmiyor.');
    }
    private static function logDatabaseError(string $operation,\PDOException $error):void
    {
        // Never log SQL parameters, document contents, credentials or customer identifiers.
        $frames=array_map(static fn($frame)=>basename($frame['file']??'').':'.($frame['line']??0),array_slice($error->getTrace(),0,4));
        error_log('Omnigo order documents '.$operation.' sqlstate='.$error->getCode().' driver='.(int)($error->errorInfo[1]??0).' frames='.implode(',',$frames));
    }
}
