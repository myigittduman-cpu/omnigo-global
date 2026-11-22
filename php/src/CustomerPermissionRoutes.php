<?php
declare(strict_types=1);
namespace Omnigo;

/** HTTP boundary checks supplement the existing tenant, CSRF and version checks. */
final class CustomerPermissionRoutes
{
    public static function enforce(Database $db,Security $security,array $user,string $path,string $method): void
    {
        if(($user['role']??'')==='admin')return;
        if($path==='/api/admin/returns')Security::admin($user);
        $page=match(true){
            str_starts_with($path,'/api/shipments')=>'shipments',
            str_starts_with($path,'/api/delivery/')=>'deliveries',
            preg_match('#^/api/(?:admin/)?orders(?:/|$)#',$path)===1=>'orders',
            $path==='/api/returns'=>'returns',
            $path==='/api/analytics/report'=>'analytics',
            $path==='/api/payments/calendar'=>'payments',
            str_starts_with($path,'/api/support/')=>'support',
            preg_match('#^/api/records/(tasks|reports)(?:/|$)#',$path,$m)===1=>$m[1],
            default=>null
        };
        $salesRead=in_array($path,['/api/sales/detail','/api/sales/top-products','/api/sales/product-image'],true);
        if(!$page&&!$salesRead)return;
        $company=$security->company($user,$_GET['company']??null);$policy=new CustomerPermissions($db,$company);
        if($salesRead){
            $page=$path==='/api/sales/detail'?'overviewSales':'overviewTop';
            $allowed=$policy->allows($user,$page)||$policy->allows($user,'analytics')
                ||($path==='/api/sales/product-image'&&($policy->allows($user,'overviewSales')||$policy->allows($user,'inventory')));
            if(!$allowed)Http::fail(403,'Satış bilgilerini görüntüleme yetkiniz yok.');
            return;
        }
        if($page==='shipments'){self::shipment($policy,$user,$path,$method);return;}
        $policy->require($user,$page);
        if($page==='deliveries'&&($_GET['mode']??'')==='cancelled')$policy->require($user,'deliveries',CustomerTableVisibility::CANCELLATIONS);
        if($page==='deliveries'){
            if($path==='/api/delivery/pdf-data'&&$method==='POST'){$policy->require($user,$page,'selectDocuments');$policy->require($user,$page,'export');}
            elseif($path==='/api/delivery/list-additions'&&in_array($method,['GET','POST'],true))$policy->require($user,$page,'selectDocuments');
            elseif($method!=='GET'||!in_array($path,['/api/delivery/orders','/api/delivery/revision'],true))Security::admin($user);
        }
        if($page==='support'&&$method!=='GET')$policy->require($user,$page,str_ends_with($path,'/reply')?'reply':(str_ends_with($path,'/status')?'status':'create'));
        if(in_array($page,['tasks','reports'],true)&&$method!=='GET')$policy->require($user,$page,$method==='POST'?'create':'edit');
    }
    public static function shipment(CustomerPermissions $policy,array $user,string $path,string $method,?array $body=null): void
    {
        if(preg_match('#^/api/shipments/\d+/documents/delivery$#D',$path)){
            if($method!=='GET')Http::fail(410,'Bu belge işlemi kullanımdan kaldırıldı.');
            $policy->archivedDeliveryFlags($user);
            return;
        }
        if(($user['role']??'')==='admin')return;
        if(($path==='/api/shipments/document-queue'&&$method==='GET')||($path===ShipmentRoutes::DOCUMENT_QUEUE_SEARCH&&$method==='POST')){
            if($method==='GET'&&($_GET['summary']??null)==='1'){
                $policy->require($user,'overviewWork');
                foreach(CustomerPermissionCatalog::DOCUMENT_PAGES as $page)if($policy->allows($user,$page,'upload'))return;
                Http::fail(403,'Bekleyen belge işlemlerini görüntüleme yetkiniz yok.');
            }
            $kind=Validation::choice($_GET['kind']??null,array_keys(CustomerPermissionCatalog::DOCUMENT_PAGES));
            $policy->require($user,CustomerPermissionCatalog::DOCUMENT_PAGES[$kind]);
            return;
        }
        if(preg_match('#/documents/(invoice|delivery)$#',$path,$match)){
            $page=CustomerPermissionCatalog::DOCUMENT_PAGES[$match[1]];
            $policy->require($user,$page,'documents');
            if($method==='DELETE')$policy->require($user,$page,'deleteDocuments');
            if($method==='POST')$policy->require($user,$page,'upload');
            return;
        }
        if($path==='/api/shipments/revision'&&$method==='GET'){
            foreach(CustomerPermissionCatalog::DOCUMENT_PAGES as $page)if($policy->allows($user,$page))return;
        }
        $policy->require($user,'shipments');
        if($method==='GET'||($path==='/api/shipments/search'&&$method==='POST'))return;
        if($path==='/api/shipments/export'){$policy->require($user,'shipments','export');return;}
        if($path==='/api/shipments/editing'){
            if(!$policy->allows($user,'shipments','create')&&!$policy->allows($user,'shipments','edit')&&!$policy->allows($user,'shipments','delete'))Http::fail(403,'Tablo düzenleme yetkiniz yok.');
            return;
        }
        if($path==='/api/shipments/commit'||$method==='PUT'){
            $actions=$path==='/api/shipments/commit'?['create','edit','delete']:(str_ends_with($path,'/details')?['edit']:['invoice','status']);
            if(!array_filter($actions,fn($action)=>$policy->allows($user,'shipments',$action)))Http::fail(403,'Bu tablo işlemi için yetkiniz yok.');
        }
        if($path==='/api/shipments/commit'){
            if(!$policy->allows($user,'shipments','create')&&!$policy->allows($user,'shipments','edit')&&!$policy->allows($user,'shipments','delete'))Http::fail(403,'Tablo düzenleme yetkiniz yok.');
            $body??=Http::body();
            foreach($body['changes']??[] as $change){$change=(array)$change;$action=match($change['type']??''){'create'=>'create','update'=>'edit','delete'=>'delete',default=>Http::fail(400,'İşlem türü geçersiz.')};$policy->require($user,'shipments',$action);}
            return;
        }
        if($method==='PUT'&&!$policy->allows($user,'shipments','invoice')&&!$policy->allows($user,'shipments','status')&&!$policy->allows($user,'shipments','edit'))Http::fail(403,'Tablo düzenleme yetkiniz yok.');
        if($path==='/api/shipments/status'){$policy->require($user,'shipments','status');$policy->require($user,'shipments','selectRows');$policy->require($user,'shipments','bulkStatus');return;}
        if($path==='/api/shipments/status-preview'){
            $body??=Http::body();if(!is_array($body['ids']??[]))Http::fail(400,'Sipariş seçimi geçersiz.');$bulk=count($body['ids']??[])>1||($body['bulk']??false)===true;
            $policy->require($user,'shipments','status');
            if($bulk){$policy->require($user,'shipments','selectRows');$policy->require($user,'shipments','bulkStatus');}
            return;
        }
        $action=match(true){
            $path==='/api/shipments'=>'create',
            str_ends_with($path,'/delete')=>'delete',
            str_ends_with($path,'/details')=>'edit',
            in_array($path,['/api/shipments/status','/api/shipments/status-preview'],true)=>'status',
            $method==='PUT'=>((($body??Http::body())['field']??'')==='invoiced'?'invoice':'status'),
            default=>null
        };
        if(!$action)Http::fail(403,'Bu işleme müşteri erişimi kapalı.');
        $policy->require($user,'shipments',$action);
    }
}
