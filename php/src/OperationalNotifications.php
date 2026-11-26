<?php
declare(strict_types=1);
namespace Omnigo;

/** A small public envelope references the encrypted audit; no customer data enters push storage. */
final class OperationalNotifications
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null){}
    public function publish(int $company,string $type,int $auditId,string $provider,array $summary): void
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Bildirim işlemle birlikte kaydedilmeli.');
        if(!in_array($type,['operation','shipment'],true))throw new \LogicException('Bildirim türü geçersiz.');
        $provider=['Koçtaş'=>'koctas','Pazarama'=>'pazarama','Hepsiburada'=>'hepsiburada','İdefix'=>'idefix','PttAvm'=>'pttavm','PTT AVM'=>'pttavm'][$provider]??strtolower($provider);
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $result=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
            [$company,$provider,hash('sha256',$type.':'.$auditId),$type,json_encode(['auditId'=>$auditId,...$summary],JSON_THROW_ON_ERROR),gmdate('c')]);
        if($result->rowCount()===1)PushRoutes::devices($this->db)->enqueue($this->db->insertId());
    }
    public function summary(int $company,int $id): array
    {
        $row=$this->db->one("SELECT payload,created,provider FROM order_notifications WHERE id=? AND company_id=? AND event_type='operation'",[$id,$company]);
        if(!$row)Http::fail(404,'İşlem bildirimi bulunamadı.');
        $summary=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
        if(StoreSetupRun::isNotice($summary))return [...$summary,'message'=>StoreSetupRun::presentation()['title'],'createdAt'=>$row['created']];
        if(UpcomingPayoutNotice::isEvent($summary))return [...$summary,'message'=>UpcomingPayoutNotice::presentation()['title'],'createdAt'=>$row['created']];
        if($notice=CustomerOrderNotifications::presentation($summary))return [...$summary,'message'=>$notice['title'],'view'=>$notice['view'],'provider'=>$row['provider'],'createdAt'=>$row['created']];
        if(in_array('payment_updated',$summary['events']??[],true))return [...$summary,'message'=>CustomerServiceNotifications::paymentMessage($summary),'createdAt'=>$row['created']];
        if($message=ActivityNotification::message($summary))$summary['message']=$message;
        if(array_intersect(['delivery_added','shipment_added','cancelled'],$summary['events']??[])){
            $audit=$this->db->one("SELECT record_id,after_value FROM audit WHERE id=? AND company_id=? AND action IN ('operations.mail','inventory.alert')",[$summary['auditId'],$company]);
            if($audit){
                $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
                $detail=DeliveryNotificationName::enrich($this->db,$cipher,$company,(int)$audit['record_id'],$cipher->open($company,'operation-mail:'.$summary['auditId'],$audit['after_value']));
                $summary['buyerName']=$detail['buyerName']??'';
            }
        }
        return $summary;
    }
    public function detail(int $company,int $id): array
    {
        $summary=$this->summary($company,$id);
        if(StoreSetupRun::isNotice($summary))return $summary;
        if(UpcomingPayoutNotice::isEvent($summary))return (new UpcomingPayoutNotice($this->db))->detail($company,$id,$summary);
        if(IntegrationImportPresentation::isEvent($summary)){
            if(is_array($summary['importReport']??null))$summary['importReport']=(new IntegrationImportCatalogSummary($this->db))->attach($company,[$summary['importReport']])[0];
            return $summary;
        }
        if(CustomerOrderNotifications::event($summary)!==null||in_array('payment_updated',$summary['events']??[],true))return $summary;
        $row=$this->db->one("SELECT record_id,after_value,created FROM audit WHERE id=? AND company_id=? AND action IN ('operations.mail','inventory.alert')",[$summary['auditId'],$company]);
        if(!$row)Http::fail(404,'İşlem bildirimi bulunamadı.');
        $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
        $detail=[...$cipher->open($company,'operation-mail:'.$summary['auditId'],$row['after_value']),...array_intersect_key($summary,['buyerName'=>true]),'createdAt'=>$row['created']];
        return (new OperationOrderDetails($this->db,$cipher))->enrich($company,(int)$row['record_id'],$detail);
    }
    public static function presentation(string $type,array $detail): array
    {
        if($type==='operation'&&StoreSetupRun::isNotice($detail))return StoreSetupRun::presentation();
        if($type==='operation'&&IntegrationImportPresentation::isEvent($detail))return IntegrationImportPresentation::presentation($detail);
        if($type==='operation'&&UpcomingPayoutNotice::isEvent($detail))return UpcomingPayoutNotice::presentation();
        if($type==='operation'&&ReconciliationPresentation::isEvent($detail))return ReconciliationPresentation::presentation($detail);
        if($type==='operation'&&($notice=CustomerOrderNotifications::presentation($detail)))return $notice;
        if(in_array('payment_updated',$detail['events']??[],true))return ['title'=>'Omnigo','body'=>CustomerServiceNotifications::paymentMessage($detail),'view'=>'payments'];
        if($message=ActivityNotification::message($detail))return ['title'=>$message,'body'=>'','view'=>($detail['section']??'orders')];
        if($type==='shipment')return ['title'=>ActivityNotification::shipment($detail),'body'=>'','view'=>'shipments'];
        if(in_array('cancelled',$detail['events']??[],true))return ['title'=>DeliveryNotificationName::title($detail,'cancelled'),'body'=>'','view'=>'orders'];
        if(in_array('cancellation_requested',$detail['events']??[],true))return ['title'=>'Sipariş için iptal talebi geldi','body'=>'Pazaryerinde iptal talebi var. Bu bildirim siparişin iptal edildiği anlamına gelmez.','view'=>'orders'];
        if(in_array('shipment_added',$detail['events']??[],true))return ['title'=>DeliveryNotificationName::title($detail,'shipment_added'),'body'=>'','view'=>'shipments'];
        return ['title'=>'Sipariş aktarımı tamamlandı','body'=>'Sipariş bilgileri ilgili teslimat ve sevkiyat listelerine işlendi.','view'=>'orders'];
    }
}
