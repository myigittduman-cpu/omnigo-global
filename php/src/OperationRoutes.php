<?php
declare(strict_types=1);
namespace Omnigo;

final class OperationRoutes
{
    public function __construct(private Database $db,private Security $security,private ?DeliveryCipher $cipher=null,private ?string $folder=null){}
    public function handle(string $path,string $method,array $user):void
    {
        if(!in_array($path,['/api/operations/attention','/api/operations/dossier','/api/operations/file'],true))return;
        Security::admin($user);$company=$this->security->company($user,$_GET['company']??null);$cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
        if($path==='/api/operations/attention'){
            $center=new AttentionCenter($this->db,new AttentionSources($this->db,$cipher));
            if($method==='GET')Http::json(($_GET['summary']??null)==='1'?$center->pendingCount($user,$company):$center->listing($user,$company,$_GET));
            if($method==='PUT')Http::json($center->save($user,$company,Http::body()));
        }else{
            $target=(new OperationTargets($this->db,$cipher))->resolve($user,$company,$_GET);$files=new OperationFiles($cipher,$this->folder??dirname(__DIR__).'/storage/operation-documents');$dossier=new OperationDossier($this->db,$cipher,$files);
            if($path==='/api/operations/file'&&$method==='GET')Http::json($dossier->file($user,$company,$target,(string)($_GET['file']??'')));
            if($path==='/api/operations/dossier'&&$method==='GET')Http::json($dossier->load($user,$company,$target));
            if($path==='/api/operations/dossier'&&$method==='POST'){
                if(strlen((string)($_POST['data']??''))>16000)Http::fail(413,'Dosya bilgileri çok uzun.');
                try{$data=json_decode((string)($_POST['data']??''),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){Http::fail(400,'Dosya bilgileri okunamadı.');}
                if(!is_array($data))Http::fail(400,'Dosya bilgilerini kontrol edin.');$upload=null;
                if(isset($_FILES['file'])&&($_FILES['file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$file=$_FILES['file'];if(($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||$file['size']>5242880)Http::fail(400,'Dosya yüklenemedi; en fazla 5 MB görsel veya 4 MB PDF seçin.');$upload=['bytes'=>file_get_contents($file['tmp_name']),'name'=>(string)$file['name']];}
                Http::json($dossier->save($user,$company,$target,$data,$upload));
            }
        }
        Http::fail(405,'Operasyon işlemi desteklenmiyor.');
    }
}
