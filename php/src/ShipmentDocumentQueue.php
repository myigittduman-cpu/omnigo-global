<?php
declare(strict_types=1);
namespace Omnigo;

/** Lists the same encrypted document references used by the shipment table. */
final class ShipmentDocumentQueue
{
    private const TRACKING_SINCE = '2026-11-10T05:12:58+00:00';
    private const PAGE_SIZE = 10;
    private const BATCH_SIZE = 120;

    public function __construct(private Database $db,private DeliveryCipher $cipher,private bool $customerView=false,
        private bool $hideReturns=false,private bool $markCancellations=false){}

    public function listing(int $company,string $kind,string $bucket,int $before): array
    {
        self::validate($kind,$bucket,$before);
        $matches=[];
        foreach($this->matching($company,$kind,$bucket,$before) as $match){
            $matches[]=$match;
            if(count($matches)>self::PAGE_SIZE)break;
        }
        $next=count($matches)>self::PAGE_SIZE?(int)$matches[self::PAGE_SIZE-1][0]['id']:null;
        return ['items'=>$this->items($company,$kind,array_slice($matches,0,self::PAGE_SIZE)),'next'=>$next];
    }

    public function page(int $company,string $kind,string $bucket,int $page,int $pageSize,mixed $query=''): array
    {
        self::validate($kind,$bucket,$page);
        if($page>100000||!in_array($pageSize,[10,50],true))Http::fail(400,'Sayfa ve kayıt adedini kontrol edin.');
        $query=ShipmentSearch::query($query);
        $search=$query===''?null:new ShipmentSearch($query,['orderNumber','productName','customerName']);
        $read=function()use($company,$kind,$bucket,$page,$pageSize,$search){
            $offset=$page*$pageSize;
            $total=0;$pendingTotal=0;
            $items=$lastPage=[];
            foreach($this->rows($company) as [$row,$payload,$eligibleForPending]){
                if(self::removed($payload,$kind))continue;
                $document=$payload['documents'][$kind]??null;
                $pending=$document===null&&$eligibleForPending;
                if($pending)$pendingTotal++;
                if($bucket==='pending'?!$pending:$document===null)continue;
                if($search&&!$search->matches($payload))continue;
                $match=[$row,$payload,$document];
                if($total%$pageSize===0)$lastPage=[];
                $lastPage[]=$match;
                if($total>=$offset&&$total<$offset+$pageSize)$items[]=$match;
                $total++;
            }
            $last=max(0,(int)ceil($total/$pageSize)-1);
            if($page>$last){$page=$last;$items=$lastPage;}
            return ['items'=>$this->items($company,$kind,$items),'total'=>$total,'pendingTotal'=>$pendingTotal,'page'=>$page,'pageSize'=>$pageSize];
        };
        return $this->db->pdo->inTransaction()?$read():$this->db->transaction($read);
    }

    /** Recheck list membership and versions under the action's transaction lock. */
    public function selected(int $company,string $kind,string $bucket,array $versions): array
    {
        self::validate($kind,$bucket,0);
        [$scope,$params]=$this->scope($company);
        $placeholders=implode(',',array_fill(0,count($versions),'?'));
        $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$scope.' AND id IN ('.$placeholders.') ORDER BY id'.$this->db->lock(),
            [...$params,...array_keys($versions)]);
        if(count($rows)!==count($versions))Http::fail(409,'Seçilen bir kayıt artık bu listede yok.');
        $selected=[];
        foreach($rows as $row){
            $id=(int)$row['id'];
            $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
            $document=$payload['documents'][$kind]??null;
            $matches=$bucket==='completed'?$document!==null:$document===null&&ShipmentCustomerVisibility::visible($row,$payload);
            if((int)$row['version']!==$versions[$id]||!$matches||self::removed($payload,$kind))Http::fail(409,'Seçilen bir kayıt değişti. Listeyi yenileyip tekrar deneyin.');
            $selected[]=[$row,$payload];
        }
        return $selected;
    }

    public function pendingCounts(int $company,array $hideReturnsByKind=[]): array
    {
        $counts=['invoice'=>0,'delivery'=>0];
        foreach($this->rows($company) as [$row,$payload,$eligibleForPending]){
            if(!$eligibleForPending)continue;
            foreach(array_keys($counts) as $kind){
                if(!ShipmentCustomerVisibility::readable($row,$payload,false,$hideReturnsByKind[$kind]??false))continue;
                if(!isset($payload['documents'][$kind])&&!self::removed($payload,$kind))$counts[$kind]++;
            }
        }
        return $counts;
    }

    private static function validate(string $kind,string $bucket,int $position): void
    {
        if(!in_array($kind,['invoice','delivery'],true)||!in_array($bucket,['pending','completed'],true)||$position<0){
            Http::fail(400,'Belge listesi seçimini kontrol edin.');
        }
    }

    private function matching(int $company,string $kind,string $bucket,int $before=0): \Generator
    {
        $pending=$bucket==='pending';
        foreach($this->rows($company,$before) as [$row,$payload,$eligibleForPending]){
            if(self::removed($payload,$kind))continue;
            $document=$payload['documents'][$kind]??null;
            if($pending?($document===null&&$eligibleForPending):$document!==null){
                yield [$row,$payload,$document];
            }
        }
    }

    private static function removed(array $payload,string $kind): bool
    {
        return !empty($payload['documentTracking']['removed'][$kind]);
    }

    private function scope(int $company): array
    {
        $onboarded=$this->db->one('SELECT created FROM audit WHERE company_id=? AND action=? ORDER BY id LIMIT 1',[$company,CustomerOnboarding::CREATED_AUDIT])['created']??'';
        $since=max(self::TRACKING_SINCE,$onboarded);
        $orderSince=gmdate('Y-m-d\TH:i:s\Z',strtotime($since));
        [$scope,$params]=CustomerCities::scope($this->db,$company,[
            'company_id=? AND removed_at IS NULL AND created>=? AND ordered_at>=? AND '.MarketplaceVisibility::sql(),[$company,$since,$orderSince]
        ]);
        if($this->customerView)$scope.=' AND '.ShipmentCustomerVisibility::SQL;
        return [$scope,$params];
    }

    private function rows(int $company,int $cursor=0): \Generator
    {
        [$scope,$params]=$this->scope($company);
        do{
            $after=$cursor?' AND id<?':'';
            $values=$cursor?[...$params,$cursor]:$params;
            $rows=$this->db->all('SELECT id,city,provider,month,version,payload,source_key,cancelled,row_status,shipped,invoiced FROM shipment_entries WHERE '.$scope.$after.' ORDER BY id DESC LIMIT '.self::BATCH_SIZE,$values);
            foreach($rows as $row){
                $cursor=(int)$row['id'];
                $payload=$this->cipher->open($company,'shipment:'.$cursor,$row['payload']);
                $eligibleForPending=ShipmentCustomerVisibility::visible($row,$payload);
                if(ShipmentCustomerVisibility::readable($row,$payload,$this->customerView,$this->hideReturns)){
                    yield [$row,$payload,$eligibleForPending];
                }
            }
        }while(count($rows)===self::BATCH_SIZE);
    }

    private function items(int $company,string $kind,array $matches): array
    {
        $items=array_map(static fn($match)=>self::item($kind,...$match),$matches);
        $enriched=(new ShipmentDataQuality($this->db,$this->cipher))->enrich($company,$items,$matches);
        foreach($items as $index=>&$item){
            if($this->markCancellations){
                $mark=OperationalCancellationMark::shipmentMark($item,$matches[$index][1]['marketplaceCancellation']??[]);
                if($mark!==null)$item['cancellationMark']=$mark;
            }
            $item['productCode']=$enriched[$index]['productCode'];
            if($item['provider']==='Pazarama' && array_key_exists('invoiceCents',$enriched[$index])){
                $item['invoiceCents']=$enriched[$index]['invoiceCents'];
            }
            $item['recipientName']=$enriched[$index]['recipientName']??'';
            $item['customerNameSource']=$enriched[$index]['customerNameSource']??null;
        }
        unset($item);
        return $items;
    }

    private static function item(string $kind,array $row,array $payload,?array $document): array
    {
        return [
            'id'=>(int)$row['id'],'version'=>(int)$row['version'],'month'=>$row['month'],'provider'=>$row['provider'],'city'=>$row['city'],
            ...array_intersect_key($payload,array_flip(['orderedAt','orderNumber','customerName','recipientName','productName','quantity','invoiceCents'])),
            ...ShipmentDocumentActions::deliveryState($row,$payload),
            'documents'=>[$kind=>$document!==null],'documentTypes'=>[$kind=>$document['mime']??'image/jpeg'],
            'documentUpdated'=>$document['updated']??null,
            ...(!ShipmentCustomerVisibility::visible($row,$payload)?['customerReadOnly'=>true]:[]),
            'displayRowStatus'=>ShipmentColorPolicy::status($row,$payload),
            'marketplaceReturn'=>$payload['marketplaceReturn']??null,
        ];
    }
}
