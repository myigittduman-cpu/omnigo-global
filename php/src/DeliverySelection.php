<?php
declare(strict_types=1);
namespace Omnigo;

/** Read a current, tenant-scoped PDF/Excel snapshot without changing order state. */
final class DeliverySelection
{
    public function __construct(private Database $db, private DeliveryRepository $orders) {}

    public function snapshot(int $company, array $input, bool $visibleOnly=false): array
    {
        $city=Validation::choice($input['city']??null,['all',...DeliveryMapper::CITIES]);
        $cities=DeliveryCities::parse($input['cities']??null);
        $date=Validation::date($input['deliveryDate']??null);
        $entries=DeliveryRecordSelection::entries($input['orders']??null);
        return $this->db->transaction(function()use($company,$city,$cities,$date,$entries,$visibleOnly){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $rows=DeliveryRecordSelection::rows($this->db,$company,$entries,$visibleOnly);
            $allowed=CustomerCities::read($this->db,$company);$items=[];
            foreach(array_chunk(array_values($rows),200) as $batch){
                foreach($this->orders->operationalBatch($batch,true) as $order){
                    $id=$order['id'];$row=$rows[$id];
                    DeliveryDocumentGuard::assertVerified($order);
                    if(!in_array($order['city'],$allowed,true))Http::fail(409,'Bu şehir artık seçili değil. Listeyi yenileyin.');
                    if(($city!=='all'&&$row['city']!==$city)||($cities!==null&&!in_array($row['city'],$cities,true))){
                        Http::fail(409,'Bir sipariş başka bir şehre ait. Listeyi yenileyip yeniden seçin.');
                    }
                    $document=DeliveryPlan::documentOrder($order);
                    if($order['issues'])Http::fail(422,'Eksik teslimat bilgilerini tamamlayıp aktarımı bekleyin.');
                    $items[]=$document;
                }
            }
            $items=DeliveryDispatchScope::exportOrders($items);
            usort($items,fn($a,$b)=>(strtotime($b['createdAt']??'')?:0)<=>(strtotime($a['createdAt']??'')?:0));
            return ['companyId'=>$company,'sender'=>DeliverySender::forCompany($this->db,$company),
                'city'=>$cities!==null?implode(', ',$cities):($city==='all'?'Tüm Şehirler':$city),
                ...($cities!==null?['cities'=>$cities]:[]),'deliveryDate'=>$date,'orders'=>$items];
        });
    }
}
