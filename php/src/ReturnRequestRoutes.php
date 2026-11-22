<?php
declare(strict_types=1);
namespace Omnigo;

final class ReturnRequestRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user): void
    {
        if(!in_array($path,['/api/admin/returns','/api/returns'],true))return;
        if($path==='/api/admin/returns')Security::admin($user);
        if($method!=='GET')Http::fail(405,'İptal ve iade talepleri bu ekranda görüntülenebilir.');
        $company=$this->security->company($user,$_GET['company']??null);
        (new CustomerPermissions($this->db,$company))->require($user,'returns');
        $details=new OrderDetails($this->db,new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
        Http::json((new ReturnRequestList($this->db,$details))->listing($user,$company,$_GET,includeCancellations:true));
    }
}
