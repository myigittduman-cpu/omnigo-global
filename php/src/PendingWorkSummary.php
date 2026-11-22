<?php
declare(strict_types=1);
namespace Omnigo;

/** Only totals for tasks the current account can open in this company. */
final class PendingWorkSummary
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}

    public function read(array $user,int $company): array
    {
        $read=function()use($user,$company):array{
            $customer=$user['role']==='client';
            $access=(new CustomerPermissions($this->db,$company))->access($user);
            $grants=$access['grants'];
            if($customer&&empty($grants['overviewWork']['view']))Http::fail(403,'İşlem Bekleyenler bölümünü görüntüleme yetkiniz yok.');
            $modules=CustomerModules::read($this->db,$company);
            $counts=[];
            if($modules['shipments']){
                $available=array_filter(['invoice'=>'shipmentInvoices'],static fn(string $page):bool=>
                    !empty($grants[$page]['view'])&&!empty($grants[$page]['documents'])&&!empty($grants[$page]['upload']));
                if($available){
                    $hideReturns=array_map(static fn(string $page):bool=>
                        $customer&&!CustomerTableVisibility::fromAccess($access,$page)['returns'],$available);
                    $counts=array_intersect_key((new ShipmentDocumentQueue($this->db,$this->cipher,$customer))->pendingCounts($company,$hideReturns),$available);
                }
            }
            if($modules['deliveries']&&!empty($grants['deliveries']['view'])){
                $orders=new DeliveryRepository($this->db,$this->cipher);
                $counts['dispatch']=(new DeliveryOperationalList($this->db,$orders))->pendingCount($company);
            }
            return $counts;
        };
        return $this->db->pdo->inTransaction()?$read():$this->db->transaction($read);
    }
}
