<?php
declare(strict_types=1);
namespace Omnigo;

final class ClientNotificationRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user): void
    {
        $company=$this->security->company($user,$_GET['company']??null);$id=(int)$user['id'];
        Validation::choice($_GET['category']??'resolved',['all','resolved']);
        $service=new SupportResolutionNotifications($this->db);
        if($path==='/api/notifications'&&$method==='GET'){
            $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);if($page===false)Http::fail(400,'Sayfa geçersiz.');
            Http::json($service->listing($id,$company,$page,Validation::choice($_GET['filter']??'all',['all','unread','read'])));
        }
        if(preg_match('#^/api/notifications/resolved/(\d+)(/read)?$#D',$path,$m)){
            if($method==='GET'&&!isset($m[2]))Http::json($service->detail($id,$company,(int)$m[1]));
            if($method==='POST'&&isset($m[2])){$service->mark($id,$company,(int)$m[1],false,true);Http::json(['ok'=>true]);}
        }
        if($method==='POST'&&in_array($path,['/api/notifications/read-all','/api/notifications/dismiss-all','/api/notifications/undo'],true)){
            $data=Http::body();
            if($path==='/api/notifications/undo'){if(!is_string($data['undoId']??null))Http::fail(400,'Geri alma bilgisi geçersiz.');$service->undo($id,$company,$data['undoId']);Http::json(['ok'=>true]);}
            $boundary=is_object($data['through']??null)?($data['through']->resolved??null):null;
            if(!is_int($boundary))Http::fail(400,'Bildirim listesini yenileyin.');
            Http::json(['ok'=>true,'undoId'=>$service->mark($id,$company,$boundary,$path==='/api/notifications/dismiss-all')]);
        }
        Http::fail(403,'Bu bildirim bölümüne erişemezsiniz.');
    }
}
