<?php
declare(strict_types=1);
namespace Omnigo;

final class NotificationRoutes
{
    public function __construct(private Database $db,private Security $security,private ?DeliveryCipher $cipher=null) {}

    public function handle(string $path,string $method,array $user): void
    {
        if($path!=='/api/notifications'&&!str_starts_with($path,'/api/notifications/'))return;
        if(!in_array($user['role'],['admin','client'],true))Http::fail(403,'Bildirim erişiminiz yok.');
        if(($_GET['scope']??'')==='all'){
            if($user['role']!=='admin')Http::fail(403,'Tüm mağazaların bildirimlerine erişemezsiniz.');
            $global=new AdminNotificationInbox($this->db,$this->cipher);
            $category=NotificationCategory::validate($_GET['category']??'all');
            if($path==='/api/notifications'&&$method==='GET')Http::json($global->listing($user,Validation::choice($_GET['filter']??'all',['all','unread','read']),$category));
            if($method==='POST'&&in_array($path,['/api/notifications/read-all','/api/notifications/dismiss-all','/api/notifications/undo'],true)){
                $data=Http::body();
                if($path==='/api/notifications/undo'){
                    if(!is_string($data['undoId']??null))Http::fail(400,'Geri alma bilgisi geçersiz.');
                    $global->undo($user,$data['undoId'],$category);Http::json(['ok'=>true]);
                }
                if(!is_object($data['through']??null))Http::fail(400,'Bildirim listesini yenileyin.');
                Http::json(['ok'=>true,'undoId'=>$global->bulk($user,(array)$data['through'],$path==='/api/notifications/dismiss-all',$category)]);
            }
            Http::fail(405,'Bildirim işlemi desteklenmiyor.');
        }
        if($path==='/api/notifications/orders'&&$method==='GET'){
            $after=isset($_GET['after'])?filter_var($_GET['after'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2147483647]]):null;
            if($after===false)Http::fail(400,'Bildirim konumu geçersiz.');
            if($user['role']==='client')Http::json((new DesktopClientNotifications($this->db))->arrivals($user,$after));
            Http::json((new OrderNotifications($this->db))->arrivals($after,($_GET['events']??'')==='all',($_GET['recovery']??'')==='1',($_GET['operations']??'')==='1',$user));
        }
        $company=$this->security->company($user,$_GET['company']??null);
        $policy=new CustomerNotificationPolicy($this->db,$user,$company);
        if(preg_match('#^/api/notifications/(order|operation|integration|user|shipment|support|resolved)/(\d+)(/read)?$#D',$path,$notice)){
            $type=$notice[1];
            if(in_array($type,['order','operation','integration','user','resolved'],true)){
                $event=$this->db->one('SELECT * FROM order_notifications WHERE id=? AND company_id=? AND event_type=?',[(int)$notice[2],$company,$type]);
                if(!$event||!$policy->permits($event))Http::fail(403,'Bu bildirime erişemezsiniz.');
            }elseif(!$policy->enabled($type))Http::fail(403,'Bu bildirime erişemezsiniz.');
        }
        if(preg_match('#^/api/notifications/resolved/(\d+)$#D',$path,$notice)&&$method==='GET')Http::json((new SupportResolutionNotifications($this->db))->detail((int)$user['id'],$company,(int)$notice[1]));
        $category=NotificationCategory::validate($_GET['category']??'all');
        if(preg_match('#^/api/notifications/order/(\d+)$#D',$path,$match)&&$method==='GET')Http::json((new OrderNotificationOrder($this->db,$this->cipher))->detail($user,$company,(int)$match[1]));
        if(preg_match('#^/api/notifications/user/(\d+)$#D',$path,$match)&&$method==='GET')Http::json((new UserChangeNotifications($this->db))->detail($company,(int)$match[1]));
        if(preg_match('#^/api/notifications/operation/(\d+)$#D',$path,$match)&&$method==='GET')Http::json((new OperationalNotifications($this->db,$this->cipher))->detail($company,(int)$match[1]));
        if(preg_match('#^/api/notifications/shipment/(\d+)$#D',$path,$match)&&$method==='GET')Http::json((new ShipmentChangeNotifications($this->db,$this->cipher))->detail($company,(int)$match[1]));
        if(preg_match('#^/api/notifications/integration/(\d+)$#D',$path,$match)&&$method==='GET')Http::json((new IntegrationAlertMonitor($this->db,new MailQueue($this->db,[])))->detail($company,(int)$match[1]));
        if($path==='/api/notifications'&&$method==='GET'){
            $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
            if($page===false)Http::fail(400,'Sayfa geçersiz.');
            $filter=Validation::choice($_GET['filter']??'all',['all','unread','read']);
            Http::json((new NotificationFeed($this->db,$this->cipher))->listing((int)$user['id'],$company,$page,$filter,$category));
        }
        if($method==='POST'){
            $state=new NotificationState($this->db);$userId=(int)$user['id'];
            if(preg_match('#^/api/notifications/(marketplace|support|order|shipment|integration|operation|user|resolved)/(\d+)/read$#D',$path,$match)){
                $state->read($userId,$company,$match[1],(int)$match[2]);Http::json(['ok'=>true]);
            }
            $data=Http::body();
            if(in_array($path,['/api/notifications/read-all','/api/notifications/dismiss-all'],true)){
                if(!is_object($data['through']??null))Http::fail(400,'Bildirim listesini yenileyin.');
                $batch=$state->bulk($userId,$company,(array)$data['through'],$path==='/api/notifications/dismiss-all',$category);Http::json(['ok'=>true,'undoId'=>$batch]);
            }
            if($path==='/api/notifications/undo'){
                if(!is_string($data['undoId']??null))Http::fail(400,'Geri alma bilgisi geçersiz.');
                $state->undo($userId,$company,$data['undoId'],$category);Http::json(['ok'=>true]);
            }
        }
        Http::fail(405,'Bildirim işlemi desteklenmiyor.');
    }
}
