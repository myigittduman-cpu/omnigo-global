<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliveryRoutes
{
    public function __construct(private Database $db,private Security $security,private ?DeliveryCipher $cipher=null) {}
    public function handle(string $path,string $method,array $user): void
    {
        CustomerPermissionRoutes::enforce($this->db,$this->security,$user,$path,$method);
        if(str_starts_with($path,'/api/admin/delivery/'))Security::admin($user);
        elseif(str_starts_with($path,'/api/delivery/')){
            $read=$method==='GET'&&in_array($path,['/api/delivery/orders','/api/delivery/revision'],true);
            $selection=$path==='/api/delivery/pdf-data'&&$method==='POST';
            $additions=$path==='/api/delivery/list-additions'&&in_array($method,['GET','POST'],true);
            if(!$read&&!$selection&&!$additions)Http::fail(403,'Bu teslimat işlemine erişemezsiniz.');
            $path=str_replace('/api/delivery/','/api/admin/delivery/',$path);
        }else return;
        $company=$this->security->company($user,$_GET['company']??null);
        $policy=new CustomerPermissions($this->db,$company);$policy->require($user,'deliveries');
        if($method==='POST'&&$path==='/api/admin/delivery/pdf-data'){$policy->require($user,'deliveries','selectDocuments');$policy->require($user,'deliveries','export');}
        CustomerModules::requireEnabled($this->db,$company,'deliveries');
        if($path==='/api/admin/delivery/revision'&&$method==='GET')Http::json(['revision'=>ShipmentRevision::read($this->db,$company)]);
        $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');$orders=new DeliveryRepository($this->db,$cipher);$documents=new DeliveryDocuments($this->db,$cipher,$orders,true);
        $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT);if($page===false)Http::fail(400,'Sayfa numarası geçersiz.');
        $limit=($_GET['limit']??null)==='all'?0:filter_var($_GET['limit']??50,FILTER_VALIDATE_INT);
        if(!in_array($limit,[0,10,20,50,250],true))Http::fail(400,'Liste boyutu geçersiz.');
        if(preg_match('#^/api/admin/delivery/orders/(\d+)/correction$#D',$path,$match)){
            $correction=new DeliveryCorrectionService($this->db,$cipher);
            if($method==='GET')Http::json($correction->read($user,$company,(int)$match[1]));
            if($method==='PATCH')Http::json($correction->save($user,$company,(int)$match[1],Http::body()));
        }
        if($path==='/api/admin/delivery/list-additions'){
            $policy->require($user,'deliveries','selectDocuments');
            if($method==='GET'){
                $result=$orders->listing($company,(string)($_GET['city']??'all'),(string)($_GET['provider']??'all'),DeliveryPlan::ADDITIONS,$page,$limit,true,array_intersect_key($_GET,array_flip(['query','from','to','cities'])));
                Http::json(CustomerSensitiveVisibility::financial($this->db,$user,$company,$result,page:'deliveries'));
            }
            if($method==='POST')Http::json((new DeliveryListAdditions($this->db,$orders))->add($company,Http::body(DeliveryRecordSelection::BODY_LIMIT)));
        }
        if($path==='/api/admin/delivery/orders'&&$method==='GET'){
            $flags=CustomerSensitiveVisibility::flags($this->db,$user,$company,'deliveries');
            if(($_GET['mode']??'')==='cancelled'&&!$flags['cancellations'])Http::fail(403,'İptal kayıtlarını görüntüleme yetkiniz yok.');
            $mode=Validation::choice($_GET['mode']??'all',['all','pending','prepared','cancelled']);
            $filters=array_intersect_key($_GET,array_flip(['query','from','to','cities']));
            $filters['showCancellationMark']=$user['role']==='admin'||CustomerTableVisibility::canSeeCancelledStatus($policy->access($user),'deliveries');
            $result=$orders->listing($company,(string)($_GET['city']??'all'),(string)($_GET['provider']??'all'),$mode,$page,$limit,true,$filters);
            if(!$flags['cancellations'])unset($result['queues']['cancelled']);
            Http::json(CustomerSensitiveVisibility::financial($this->db,$user,$company,$result,page:'deliveries'));
        }
        if($path==='/api/admin/delivery/pdf-data'&&$method==='POST'){
            $result=(new DeliverySelection($this->db,$orders))->snapshot($company,Http::body(DeliveryRecordSelection::BODY_LIMIT),true);
            Http::json(CustomerSensitiveVisibility::financial($this->db,$user,$company,$result,page:'deliveries'));
        }
        if($path==='/api/admin/delivery/documents'){
            if($method==='GET')Http::json($documents->archive($company,$page));
            if($method==='POST')Http::json($documents->create($company,(int)$user['id'],Http::body()),201);
        }
        if(preg_match('#^/api/admin/delivery/documents/(\d+)$#D',$path,$m)&&$method==='GET')Http::json($documents->get($company,(int)$m[1]));
        Http::fail(405,'Teslimat işlemi desteklenmiyor.');
    }
}
