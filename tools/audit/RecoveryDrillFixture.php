<?php
declare(strict_types=1);
namespace Omnigo\Audit;

use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,ShipmentOrderIngestor,SyncQueue,SyncFailure,FixedCadenceReadAdapter};

/** Synthetic orders only; this fixture never loads production configuration or makes HTTP requests. */
final class RecoveryDrillFixture
{
    public const PROVIDERS=['Hepsiburada','n11','idefix','Pazarama','Koçtaş'];
    public static function cipher(): DeliveryCipher { return DeliveryCipher::withKey(str_repeat('z',32)); }
    public static function check(bool $condition,string $message): void
    {
        if(!$condition)throw new \RuntimeException($message);
    }
    public static function order(string $provider,int $number,int $now,string $state='pending'): array
    {
        $key='DRILL-'.$number;$quantity=$number;$cancelled=$state==='cancelled'?$quantity:0;
        $cancellation=['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>false,'lines'=>[$key=>['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>false]]];
        $date=gmdate('c',$now-20*86400+$number);
        $status=['pending'=>'Hazırlanıyor','shipped'=>'Kargolandı','delivered'=>'Teslim edildi','cancelled'=>'İptal edildi'][$state];
        $payload=['orderedAt'=>$date,'customerName'=>'DENEME Sipariş Veren '.$number,'recipientName'=>'DENEME Teslim Alan '.$number,'phone'=>'05000000000','deliveryAddress'=>'Sentetik teslimat adresi '.$number,'province'=>'Ankara','district'=>'Çankaya','productName'=>'DENEME Ürün '.$number,'invoiceCents'=>12345*$number,'quantity'=>$quantity,'invoiceName'=>'DENEME Fatura '.$number,'invoiceAddress'=>'Sentetik fatura adresi '.$number,'recipientEmail'=>'test@example.invalid','customerType'=>'Bireysel','taxNumber'=>'','packageStatus'=>$status,'marketplaceShipping'=>$state,'marketplaceCancellation'=>$cancellation];
        return ['provider'=>$provider,'externalId'=>$key,'orderNumber'=>$key,'createdAt'=>$date,'buyerName'=>$payload['customerName'],'recipientName'=>$payload['recipientName'],'phone'=>$payload['phone'],'address'=>$payload['deliveryAddress'],'city'=>'Ankara','district'=>'Çankaya','status'=>$status,'active'=>in_array($state,['pending','shipped'],true),'products'=>in_array($state,['pending','shipped'],true)?[['title'=>$payload['productName'],'sku'=>$key,'quantity'=>$quantity]]:[],'details'=>['products'=>[['lineId'=>$key,'title'=>$payload['productName'],'quantity'=>$quantity,'shippingState'=>$state,'cancelledQuantity'=>$cancelled]]],'cancellation'=>$cancellation,'issues'=>[],'shipmentItems'=>[['key'=>$key,'payload'=>$payload]]];
    }
    public static function build(string $path,int $now): array
    {
        self::check(!is_file($path),'Test dosyası zaten var.');
        $db=new Database(['dsn'=>'sqlite:'.$path]);
        $db->pdo->exec(file_get_contents(dirname(__DIR__,2).'/php/tests/schema.sqlite.sql'));
        DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
        $db->run("INSERT INTO companies VALUES(1,'Yalıtılmış test'),(2,'Diğer test firması')");
        $db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Test yönetici','unused','admin',NULL),(2,'client@example.invalid','Test müşteri','unused','client',1)");
        ShipmentOrderIngestor::activate($db,1,$now-30*86400);
        $repo=new DeliveryRepository($db,self::cipher());
        foreach(self::PROVIDERS as $provider)$db->transaction(fn()=>$repo->ingest(1,self::order($provider,1,$now),false));
        $stats=[];$snapshot=function(string $phase)use($db,$path,&$stats):void{
            $stats[$phase]=['deliveries'=>(int)$db->one('SELECT COUNT(*) n FROM delivery_orders')['n'],'shipments'=>(int)$db->one('SELECT COUNT(*) n FROM shipment_entries')['n']];
            self::check(!$db->one('SELECT id FROM shipment_entries WHERE company_id=2'),'Diğer firmaya yazım');
            self::check(!$db->one('SELECT source_key FROM shipment_entries GROUP BY company_id,source_key HAVING COUNT(*)>1'),'Mükerrer sevkiyat');
            self::check(!$db->one('SELECT external_id FROM delivery_orders GROUP BY company_id,provider,external_id HAVING COUNT(*)>1'),'Mükerrer evrak');
            $db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            self::check(copy($path,dirname($path).'/'.$phase.'.sqlite'),'Test görünümü kaydedilemedi.');
        };
        $snapshot('before');
        foreach(self::PROVIDERS as $provider){
            $adapter=new class($db,$repo,$provider,$now) implements FixedCadenceReadAdapter {
                public bool $online=false;
                public function __construct(private Database $db,private DeliveryRepository $repo,private string $provider,private int $now){}
                public function nextDelay():int{return 60;}
                public function syncReadOnly(int $company,int $deadline):void{
                    if(!$this->online)throw new SyncFailure(503);
                    // The recovered page repeats the previously imported order and repeats one new order.
                    $this->db->transaction(function()use($company){foreach([1,2,3,2] as $n)$this->repo->ingest($company,RecoveryDrillFixture::order($this->provider,$n,$this->now),false);});
                }
            };
            $queue=new SyncQueue($db,[$provider=>$adapter]);$queue->enqueue(1,$provider);
            $before=hash('sha256',json_encode([$db->all('SELECT * FROM delivery_orders'),$db->all('SELECT * FROM shipment_entries')]));
            $queue->runOne($now+1,[$provider],time()+30);
            self::check($before===hash('sha256',json_encode([$db->all('SELECT * FROM delivery_orders'),$db->all('SELECT * FROM shipment_entries')])),'Kesinti sipariş değiştirdi: '.$provider);
            $job=$db->one('SELECT * FROM jobs WHERE provider=?',[$provider]);
            self::check($job['state']==='pending'&&(int)$job['attempts']===1,'Yeniden deneme planlanmadı: '.$provider);
            $adapters[$provider]=[$adapter,$queue];
        }
        $snapshot('outage');
        foreach($adapters as $provider=>[$adapter,$queue]){
            $adapter->online=true;$job=$db->one('SELECT * FROM jobs WHERE provider=?',[$provider]);
            $queue->runOne((int)$job['next_run'],[$provider],time()+30);
            self::check((int)$db->one('SELECT attempts FROM jobs WHERE provider=?',[$provider])['attempts']===0,'Toparlanma tamamlanmadı: '.$provider);
        }
        $snapshot('recovered');
        self::check($stats['recovered']===['deliveries'=>15,'shipments'=>15],'On eksik sipariş tamamlanmadı.');
        foreach(self::PROVIDERS as $provider){
            foreach([1=>'shipped',2=>'cancelled',3=>'delivered'] as $number=>$state)$db->transaction(fn()=>$repo->ingest(1,self::order($provider,$number,$now,$state),false));
        }
        $snapshot('statuses');
        $before=hash('sha256',json_encode([$db->all('SELECT * FROM delivery_orders'),$db->all('SELECT * FROM shipment_entries')]));
        for($pass=0;$pass<3;$pass++)foreach(self::PROVIDERS as $provider)foreach([1=>'shipped',2=>'cancelled',3=>'delivered'] as $number=>$state)$db->transaction(fn()=>$repo->ingest(1,self::order($provider,$number,$now,$state),false));
        self::check($before===hash('sha256',json_encode([$db->all('SELECT * FROM delivery_orders'),$db->all('SELECT * FROM shipment_entries')])),'Tekrar işleme kayıt/sürüm değiştirdi.');
        $snapshot('replay');
        return ['states'=>$stats,'providers'=>self::PROVIDERS,'missingCompleted'=>10,'duplicateRows'=>0,'replayPasses'=>3,'noBrowserRequired'=>true,'otherCompanyUnchanged'=>true];
    }
}
