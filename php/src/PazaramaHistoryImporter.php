<?php
declare(strict_types=1);
namespace Omnigo;

/** Import only independently identified archive orders into the operational index and private details. */
final class PazaramaHistoryImporter
{
    private HistoricalSalesArchive $archive;

    public function __construct(private Database $db,private DeliveryCipher $cipher,?HistoricalSalesArchive $archive=null)
    {
        $this->archive=$archive??new HistoricalSalesArchive($db);
    }

    private function snapshot(int $company): ?array
    {
        return $this->archive->snapshot($company,'pazarama',new \DateTimeImmutable('1970-01-01'),new \DateTimeImmutable('now'));
    }

    public function bounds(int $company): ?array
    {
        $snapshot=$this->snapshot($company);
        return $snapshot===null?null:['from'=>$snapshot['from']->format('Y-m-d'),'untilExclusive'=>$snapshot['untilExclusive']->format('Y-m-d')];
    }

    private function archiveOrders(int $company): array
    {
        $snapshot=$this->snapshot($company);
        $orders=[];
        foreach ($snapshot['orders']??[] as $order) {
            $key=PazaramaSalesMapper::id($order['externalId']??null);
            if (isset($orders[$key])) throw new SyncFailure(422);
            $orders[$key]=$order;
        }
        return $orders;
    }

    public function expected(int $company,string $from,string $untilExclusive): array
    {
        $zone=new \DateTimeZone('Europe/Istanbul');
        $start=new \DateTimeImmutable(Validation::date($from).' 00:00:00',$zone);
        $until=new \DateTimeImmutable(Validation::date($untilExclusive).' 00:00:00',$zone);
        return array_filter($this->archiveOrders($company),static function(array $order) use ($start,$until):bool {
            $date=new \DateTimeImmutable($order['createdAt']);
            return $date>=$start&&$date<$until;
        });
    }

    private function scope(int $company,array $expected): array
    {
        $orders=array_intersect_key($this->archiveOrders($company),$expected);
        if (count($orders)!==count($expected)) throw new SyncFailure(409);
        return $orders;
    }

    private function indexed(int $company,string $id): bool
    {
        $external=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))":"JSON_EXTRACT(payload,'$.externalId')";
        return (bool)($this->db->one("SELECT id FROM recent_orders WHERE company_id=? AND provider='pazarama' AND external_id=?",[$company,$id])
            ||$this->db->one("SELECT id FROM records WHERE company_id=? AND kind='order_pazarama' AND $external=? LIMIT 1",[$company,$id]));
    }

    private function hasDetails(int $company,string $id): bool
    {
        return (bool)$this->db->one("SELECT id FROM delivery_orders WHERE company_id=? AND provider='Pazarama' AND external_id=?",[$company,$id]);
    }

    public function coverage(int $company,array $expected): array
    {
        $orders=$this->scope($company,$expected);
        $present=0;
        $external=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))":"JSON_EXTRACT(payload,'$.externalId')";
        foreach (array_chunk(array_keys($orders),100) as $ids) {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $args=[$company,...$ids];
            $detailIds=array_column($this->db->all("SELECT external_id FROM delivery_orders WHERE company_id=? AND provider='Pazarama' AND external_id IN ($marks)",$args),'external_id');
            $indexedIds=array_column($this->db->all("SELECT external_id FROM recent_orders WHERE company_id=? AND provider='pazarama' AND external_id IN ($marks)
                UNION SELECT $external AS external_id FROM records WHERE company_id=? AND kind='order_pazarama' AND $external IN ($marks)",[...$args,...$args]),'external_id');
            $present+=count(array_intersect($detailIds,$indexedIds));
        }
        return ['total'=>count($orders),'present'=>$present,'missing'=>count($orders)-$present];
    }

    private static function lineProof(array $lines): ?array
    {
        if (!$lines) return null;
        $proof=[];
        foreach ($lines as $line) {
            $sku=trim((string)($line['sku']??''));
            $gross=$line['grossCents']??null;
            $buyer=PazaramaInvoiceAmount::fromPricing($line['pricing']??null,$gross);
            if ($sku==='' || !is_int($line['quantity']??null) || $line['quantity']<1 || $buyer===null) return null;
            $proof[]=json_encode([$sku,$line['quantity'],$gross,$buyer],JSON_THROW_ON_ERROR);
        }
        sort($proof,SORT_STRING);
        return $proof;
    }

    public function page(int $company,array $rawItems,array $expected): int
    {
        $scope=$this->scope($company,$expected);
        $write=function() use ($company,$scope,$rawItems):int {
            if (!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company])) throw new SyncFailure(404);
            $written=0;
            $intake=new NewOrderRecordStore($this->db,new DeliveryRepository($this->db,$this->cipher));
            foreach ($rawItems as $raw) {
                $id=$raw['orderId']??null;
                if (!is_string($id) || !isset($scope[strtolower($id)])) continue;
                $id=strtolower($id);
                $archived=$scope[$id];
                try { $order=PazaramaSalesMapper::order($raw); }
                catch (SyncFailure) {continue;}
                $proof=self::lineProof($archived['products']??[]);
                if (strtotime($archived['createdAt'])!==strtotime($order['createdAt']) || $archived['grossCents']!==$order['grossCents']
                    || $proof===null || $proof!==self::lineProof($order['products'])) continue;
                $indexed=$this->indexed($company,$id);
                $detailed=$this->hasDetails($company,$id);
                if ($indexed && $detailed) continue;
                if (!$detailed) {
                    $delivery=DeliveryMapper::pazarama($raw);
                    if ($delivery['orderNumber']==='') continue;
                    if (!$indexed) {
                        $source=[...$delivery,'shipmentItems'=>ShipmentOrderMapper::pazarama($raw,$delivery)];
                        if ($intake->seen($company,'pazarama',$id,[$source])) continue;
                    }
                    $delivery['details']=OrderDetailMapper::pazarama($raw,$delivery);
                    $delivery['externalId']=$id;
                    // This historical document is supplementary order evidence, never a new dispatch.
                    $delivery['active']=false;
                    $body=json_encode($delivery,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    $encrypted=$this->cipher->seal($company,'order:Pazarama:'.$id,$delivery);
                    $this->db->run("INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,'Pazarama',?,?,0,?,?,?)",
                        [$company,$id,$delivery['city'],$encrypted,hash('sha256',$body),gmdate('c')]);
                }
                if (!$indexed) {
                    $this->db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,'pazarama',?,?,?)",
                        [$company,$id,json_encode($order,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),gmdate('c')]);
                }
                $written++;
            }
            return $written;
        };
        return $this->db->pdo->inTransaction()?$write():TransactionRetry::run($this->db,$write);
    }
}
