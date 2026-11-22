<?php
declare(strict_types=1);
namespace Omnigo;
final class OrderRoutes
{
    public function __construct(private Database $db,private Security $security,private ?DeliveryCipher $cipher=null){}
    public function handle(string $path,string $method,array $user): void
    {
        if(!preg_match('#^/api/(?:admin/)?orders(?:/(-?[1-9][0-9]*))?$#D',$path,$match))return;
        if(str_starts_with($path,'/api/admin/'))Security::admin($user);
        $company=$this->security->company($user,$_GET['company']??null);
        if($method!=='GET')Http::fail(405,'Sipariş listesi yalnızca görüntülenebilir.');
        $details=new OrderDetails($this->db,$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
        if(isset($match[1]))Http::json($details->detail($user,$company,(int)$match[1]));
        $management=($_GET['view']??'')==='management';
        if($management&&!str_starts_with($path,'/api/admin/'))Http::fail(403,'Yönetici sipariş görünümü için yetkiniz yok.');
        Http::json((new OrderList($this->db,$details))->listing($user,$company,$_GET,$management));
    }
}
