<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentRoutes
{
    public const DOCUMENT_QUEUE_SEARCH='/api/shipments/search/documents';
    private const DOCUMENT_QUEUE_ACTIONS='/api/shipments/document-queue/actions';

    public function __construct(private Database $db,private Security $security,private ?DeliveryCipher $cipher=null,private ?string $documentFolder=null){}
    public function handle(string $path,string $method,array $user): void
    {
        if($path==='/api/city-products'){
            if($method!=='GET')Http::fail(405,'Ürün listesi yalnızca görüntülenebilir.');
            $company=$this->security->company($user,$_GET['company']??null);
            ShipmentAccess::company($user,$company);
            $access=(new CustomerPermissions($this->db,$company))->access($user);
            if(empty($access['grants']['cityProducts']['view']))Http::fail(403,'Şehir dışı ürünleri görüntüleme yetkiniz yok.');
            if(($_GET['export']??'')==='1'&&empty($access['grants']['cityProducts']['export']))Http::fail(403,'Şehir dışı ürün listesini dışa aktarma yetkiniz yok.');
            $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
            $showCancellationMark=$user['role']==='admin'||CustomerTableVisibility::canSeeCancelledStatus($access,'cityProducts');
            Http::json((new CityProducts($this->db,$cipher,$showCancellationMark))->listing($company,$_GET));
        }
        if(str_starts_with($path,'/api/admin/shipments')){Security::admin($user);$path=str_replace('/api/admin/shipments','/api/shipments',$path);}
        if(!preg_match('#^/api/shipments(?:/|$)#D',$path))return;
        if($path===self::DOCUMENT_QUEUE_ACTIONS)Security::admin($user);
        $documentSearch=$path===self::DOCUMENT_QUEUE_SEARCH&&$method==='POST';
        $read=$method==='GET'||($method==='POST'&&in_array($path,['/api/shipments/search','/api/shipments/export',self::DOCUMENT_QUEUE_SEARCH],true));
        // CustomerPermissionRoutes validates every requested operation before this handler.
        if(!$read)ClientSessionOperator::requireName($user);
        $company=$this->security->company($user,$_GET['company']??null);
        $permissions=new CustomerPermissions($this->db,$company);
        CustomerPermissionRoutes::shipment($permissions,$user,$path,$method);
        ShipmentAccess::company($user,$company);
        CustomerModules::requireEnabled($this->db,$company,'shipments');
        $presence=new ShipmentEditingPresence();
        if($path==='/api/shipments/editing'){
            if($method==='GET')Http::json($presence->status($user,$company));
            if($method==='POST')Http::json($presence->update($user,$company,Http::body()));
            Http::fail(405,'Düzenleme işlemi desteklenmiyor.');
        }
        if(!$read)$presence->holdClientWrite($user,$company);
        if($path==='/api/shipments/revision'&&$method==='GET')Http::json(['revision'=>ShipmentRevision::read($this->db,$company)]);
        $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
        if($path===self::DOCUMENT_QUEUE_ACTIONS){
            if($method!=='POST')Http::fail(405,'Belge işlemi desteklenmiyor.');
            Http::json((new ShipmentDocumentActions($this->db,$cipher))->apply($user,$company,Http::body(8192)));
        }
        $customerView=$user['role']==='client';
        $showMark=fn(string $page):bool=>!$customerView||CustomerTableVisibility::canSeeCancelledStatus($permissions->access($user),$page);
        $respond=function(array $value,string $page='shipments')use($customerView,$user,$company):never{
            Http::json($customerView?CustomerSensitiveVisibility::financial($this->db,$user,$company,$value,page:$page):$value);
        };
        if($documentSearch||($path==='/api/shipments/document-queue'&&$method==='GET')){
            if($method==='GET'&&($_GET['summary']??null)==='1'){
                $hideReturns=array_map(fn(string $page):bool=>
                    !CustomerSensitiveVisibility::flags($this->db,$user,$company,$page)['returns'],CustomerPermissionCatalog::DOCUMENT_PAGES);
                $counts=array_intersect_key((new ShipmentDocumentQueue($this->db,$cipher,$customerView))->pendingCounts($company,$hideReturns),CustomerPermissionCatalog::DOCUMENT_PAGES);
                foreach(CustomerPermissionCatalog::DOCUMENT_PAGES as $kind=>$page){
                    if(!$permissions->allows($user,$page,'upload'))unset($counts[$kind]);
                }
                Http::json($counts);
            }
            $kind=$_GET['kind']??null;$bucket=$_GET['bucket']??null;
            if(!is_string($kind)||!isset(CustomerPermissionCatalog::DOCUMENT_PAGES[$kind])||!is_string($bucket))Http::fail(400,'Belge listesi seçimini kontrol edin.');
            $documentPage=CustomerPermissionCatalog::DOCUMENT_PAGES[$kind];
            $flags=CustomerSensitiveVisibility::flags($this->db,$user,$company,$documentPage);
            $show=$showMark($documentPage);
            $queue=new ShipmentDocumentQueue($this->db,$cipher,!$flags['cancellations']&&!$show,!$flags['returns'],$show);
            if($documentSearch||isset($_GET['page'])||isset($_GET['pageSize'])){
                $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT);
                $size=filter_var($_GET['pageSize']??10,FILTER_VALIDATE_INT);
                if($page===false||$size===false)Http::fail(400,'Sayfa ve kayıt adedini kontrol edin.');
                $query=$documentSearch?(Http::body(2048)['query']??''):'';
                $result=$queue->page($company,$kind,$bucket,$page,$size,$query);
                $respond($show?OperationalCancellationMark::shipments($result,$flags['cancellations']):$result,$documentPage);
            }
            $before=filter_var($_GET['before']??0,FILTER_VALIDATE_INT);
            if($before===false)Http::fail(400,'Belge listesi seçimini kontrol edin.');
            $result=$queue->listing($company,$kind,$bucket,$before);
            $respond($show?OperationalCancellationMark::shipments($result,$flags['cancellations']):$result,$documentPage);
        }
        $selection=ShipmentRepository::selection($_GET,true);
        MarketplaceVisibility::requireVisible($selection[1]);
        $flags=CustomerSensitiveVisibility::flags($this->db,$user,$company,'shipments');
        $listRequest=($path==='/api/shipments'&&$method==='GET')||($path==='/api/shipments/search'&&$method==='POST');
        $show=$listRequest&&$showMark('shipments');
        $repository=new ShipmentRepository($this->db,$cipher,!$flags['cancellations']&&!$show,!$flags['returns'],$show&&!$flags['cancellations']);$writer=new ShipmentWriter($this->db,$cipher);
        if($path==='/api/shipments/commit'&&$method==='POST')Http::json((new ShipmentEditSession($this->db,$cipher))->save($user,$company,$selection,Http::body()));
        if(($path==='/api/shipments/undo'||preg_match('#^/api/shipments/\d+/restore$#D',$path))&&$method==='POST')Http::fail(410,'Kaydedilmiş ekleme ve silme işlemleri geri alınamaz.');
        if(preg_match('#^/api/shipments/(\d+)/documents/(invoice|delivery)$#D',$path,$match)){
            $documents=new ShipmentDocuments($this->db,$cipher,$this->documentFolder??dirname(__DIR__).'/storage/shipment-documents');
            if($method==='GET')Http::json($documents->load($user,$company,(int)$match[1],$match[2],$selection));
            if($method==='DELETE'&&$match[2]==='delivery'){
                $body=Http::body();
                Http::json($documents->removeDelivery($user,$company,(int)$match[1],$selection,$body['version']??null));
            }
            if($method==='POST'){
                $file=$_FILES['file']??null;$version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);
                if(!$file||($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||$file['size']>ShipmentPdf::MAX_BYTES||$version===false)Http::fail(400,'Dosya yüklenemedi. PDF en fazla 4 MB, işlenen görsel en fazla 2 MB olabilir.');
                Http::json($documents->save($user,$company,(int)$match[1],$match[2],$selection,(int)$version,(string)($_POST['requestId']??''),file_get_contents($file['tmp_name'])));
            }
        }
        if(preg_match('#^/api/shipments/(\d+)/(delete|restore)$#D',$path,$match)&&$method==='POST')Http::json((new ShipmentDeletion($this->db,$cipher))->save($user,$company,(int)$match[1],$selection,Http::body(),$match[2]==='restore'));
        if($path==='/api/shipments'&&$method==='POST')Http::json($writer->create($user,$company,$selection,Http::body()));
        if($path==='/api/shipments/undo'&&$method==='POST')Http::json($writer->undo($user,$company,$selection,Http::body()));
        if($path==='/api/shipments/status-preview'&&$method==='POST')Http::json((new ShipmentStatusConfirmation($this->db,$cipher))->preview($user,$company,$selection,Http::body()));
        if($path==='/api/shipments/status'&&$method==='POST'){
            $body=Http::body();ShipmentStatusConfirmation::required($body,true);
            $respond((new ShipmentBulkStatus($this->db,$cipher))->save($user,$company,$selection,$body));
        }
        if(preg_match('#^/api/shipments/(\d+)/details$#D',$path,$match)&&$method==='PUT')Http::json($writer->update($user,$company,(int)$match[1],$selection,Http::body()));
        if($path==='/api/shipments/export'&&$method==='POST'){
            $body=Http::body();
            $brand=$this->db->one('SELECT id,name FROM companies WHERE id=?',[$company]);
            $respond([...$repository->export($company,$selection,$body['query']??'',$body['filters']??[]),'company'=>[...$brand,...(new CustomerManagement($this->db))->profile($company,false)]]);
        }
        if($listRequest){
            $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT);if($page===false)Http::fail(400,'Sayfa numarası geçersiz.');
            $body=$method==='POST'?Http::body():[];
            $revision=ShipmentRevision::read($this->db,$company);
            $invoice=($_GET['layout']??null)==='invoice';
            $result=$repository->listing($company,$selection,$page,$body['query']??'',$body['filters']??[],$invoice,$user['role']==='admin');
            $respond(['revision'=>$revision,...($show?OperationalCancellationMark::shipments($result,$flags['cancellations']):$result),'undo'=>null,'deleted'=>null]);
        }
        if(preg_match('#^/api/shipments/(\d+)$#D',$path,$match)&&$method==='PUT'){
            $body=Http::body();if(($body['field']??'')!=='invoiced')ShipmentStatusConfirmation::required($body);
            $respond($repository->toggle($user,$company,(int)$match[1],$selection,$body));
        }
        Http::fail(405,'Sevkiyat işlemi desteklenmiyor.');
    }
}
