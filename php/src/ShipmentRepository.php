<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentRepository
{
    public const CITIES=TurkeyCities::ALL;
    public const PROVIDERS=['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol'];
    public const FIELDS=['orderedAt','customerName','recipientName','phone','deliveryAddress','province','district','productName','invoiceCents','quantity','invoiceName','invoiceAddress','recipientEmail','customerType','packageStatus'];
    private const PAGE_SIZE=10;
    public const OPTIONAL_FIELDS=['taxNumber'];
    public function __construct(private Database $db,private DeliveryCipher $cipher,private bool $customerView=false,
        private bool $hideReturns=false,private bool $cancellationMarkOnly=false){}
    public static function selection(array $query,bool $allowAllCities=false): array
    {
        $city=$query['city']??null;$provider=$query['provider']??null;$month=$query['month']??null;
        $lastMonth=max('2026-12',(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')))->format('Y-m'));
        if((!in_array($city,self::CITIES,true)&&!($allowAllCities&&$city==='all'))||!in_array($provider,[...self::PROVIDERS,'all'],true)||!is_string($month)||!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)||$month<'2026-06'||$month>$lastMonth)Http::fail(400,'Şehir, pazaryeri ve ay seçimini kontrol edin.');
        return [$city,$provider,$month];
    }
    public static function scope(int $company,array $selection): array
    {
        [$city,$provider,$month]=self::selection(['city'=>$selection[0]??null,'provider'=>$selection[1]??null,'month'=>$selection[2]??null],true);
        $sql='company_id=? AND month=? AND removed_at IS NULL';$params=[$company,$month];
        if($provider!=='all'){$sql.=' AND provider=?';$params[]=$provider;}else $sql.=' AND '.MarketplaceVisibility::sql();
        if($city!=='all'){$sql.=' AND city=?';$params[]=$city;}
        return [$sql,$params];
    }
    public function listing(int $company,array $selection,int $page=0,mixed $query='',mixed $filterValues=[],bool $invoice=false,bool $includePaymentDetails=false,bool $includeShippingLabels=false): array
    {
        if($page<0||$page>100000)Http::fail(400,'Sayfa numarası geçersiz.');
        $query=ShipmentSearch::query($query);
        $filters=$this->filters($filterValues);[$filterSql,$filterParams]=ShipmentFilters::predicate($filters,false,false);
        [$scope,$params]=CustomerCities::scope($this->db,$company,self::scope($company,$selection));
        if($this->customerView)$scope.=' AND '.ShipmentCustomerVisibility::SQL;
        $totalRecords=$this->customerView||$this->hideReturns?0:(int)$this->db->one('SELECT COUNT(*) AS total FROM shipment_entries WHERE '.$scope,$params)['total'];
        array_push($params,...$filterParams);
        if($this->customerView||$this->hideReturns||$invoice||$query!==''||$filters['customerType']!==''||$filters['rowStatus']!==''||$filters['shipped']!==''){
            $search=fn()=>$this->search($company,$selection,$page,$query,$totalRecords,$filters,$invoice,$includePaymentDetails,$includeShippingLabels);
            return $invoice&&!$this->db->pdo->inTransaction()?$this->db->transaction($search):$search();
        }
        $total=$filterSql===''?$totalRecords:(int)$this->db->one('SELECT COUNT(*) AS total FROM shipment_entries WHERE '.$scope.$filterSql,$params)['total'];
        $page=min($page,max(0,(int)ceil($total/self::PAGE_SIZE)-1));$offset=$page*self::PAGE_SIZE;
        $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$scope.$filterSql.' ORDER BY ordered_at DESC,id DESC LIMIT '.self::PAGE_SIZE.' OFFSET '.$offset,$params);
        $items=array_map(fn($record)=>self::publicItem(...$record),(new ShipmentDispatchStatus($this->cipher))->read($company,$rows));
        $items=(new ShipmentDataQuality($this->db,$this->cipher))->enrich($company,$items,includePaymentDetails:$includePaymentDetails);
        return ['items'=>$items,'total'=>$total,'totalRecords'=>$totalRecords,'query'=>'','filters'=>$filters,'page'=>$page,'pageSize'=>self::PAGE_SIZE];
    }
    private function filters(mixed $value): array
    {
        $filters=ShipmentFilters::parse($value);
        return $this->customerView||$this->cancellationMarkOnly?ShipmentCustomerVisibility::filters($filters):$filters;
    }
    private static function publicItem(array $row,array $payload,array $status): array
    {
        $payload=ShipmentAddress::payload($payload);
        if(trim((string)($payload['province']??''))===''&&in_array($row['city'],self::CITIES,true)){
            $payload['province']=$row['city'];
        }
        return [...$status,'provider'=>$row['provider'],'invoiceWorkDone'=>($payload['invoiceWorkDone']??false)===true,...array_intersect_key($payload,array_flip([...self::FIELDS,...self::OPTIONAL_FIELDS])),'marketplaceCancellation'=>$payload['marketplaceCancellation']??null,'documents'=>['invoice'=>isset($payload['documents']['invoice']),'delivery'=>isset($payload['documents']['delivery'])],'documentTypes'=>['invoice'=>$payload['documents']['invoice']['mime']??'image/jpeg','delivery'=>$payload['documents']['delivery']['mime']??'image/jpeg'],'id'=>(int)$row['id'],'cancelled'=>(bool)$row['cancelled'],'invoiced'=>(bool)$row['invoiced'],'shipped'=>!empty($status['dispatchTracked'])?$status['fulfillmentStatus']==='shipped':(bool)$row['shipped'],'rowStatus'=>ShipmentStatus::resolve($row),'version'=>(int)$row['version'],
            ...(!ShipmentCustomerVisibility::visible($row,$payload)?['customerReadOnly'=>true]:[])];
    }
    private function search(int $company,array $selection,int $page,string $query,int $totalRecords,array $filters,bool $invoice,bool $includePaymentDetails,bool $includeShippingLabels): array
    {
        // Payloads remain encrypted at rest; scoped keyset batches keep memory bounded.
        $matcher=new ShipmentSearch($query);$cursor=null;$total=0;$items=[];$lastPage=[];$offset=$page*self::PAGE_SIZE;
        $invoicePage=$invoice?new ShipmentInvoicePage($page,self::PAGE_SIZE):null;
        $quality=new ShipmentDataQuality($this->db,$this->cipher);
        [$filterSql,$filterParams]=ShipmentFilters::predicate($filters,false,false);
        [$scope,$scopeParams]=CustomerCities::scope($this->db,$company,self::scope($company,$selection));
        if($this->customerView)$scope.=' AND '.ShipmentCustomerVisibility::SQL;
        $restricted=$this->customerView||$this->hideReturns;
        $select='*';
        if($restricted){
            $totalRecords=0;
            if($filterSql!=='')$select.=', CASE WHEN 1=1'.$filterSql.' THEN 1 ELSE 0 END AS matches_filter';
        }
        do{
            $invoiceItems=[];$invoiceRecords=[];$invoiceMatches=[];
            $params=$restricted?[...$filterParams,...$scopeParams]:[...$scopeParams,...$filterParams];$after='';
            if($cursor){$after=' AND (ordered_at<? OR (ordered_at=? AND id<?))';array_push($params,$cursor['ordered_at'],$cursor['ordered_at'],$cursor['id']);}
            $rows=$this->db->all('SELECT '.$select.' FROM shipment_entries WHERE '.$scope.($restricted?'':$filterSql).$after.' ORDER BY ordered_at DESC,id DESC LIMIT 250',$params);
            foreach((new ShipmentDispatchStatus($this->cipher))->read($company,$rows) as [$row,$payload,$status]){
                if($restricted){
                    if(!ShipmentCustomerVisibility::readable($row,$payload,$this->customerView,$this->hideReturns))continue;
                    $totalRecords++;
                    if((int)($row['matches_filter']??1)!==1)continue;
                }
                if($filters['customerType']!==''&&($payload['customerType']??'')!==$filters['customerType'])continue;
                $searchable=$payload;
                if($query!==''&&$this->cancellationMarkOnly&&!ShipmentCustomerVisibility::visible($row,$payload))
                    unset($searchable['quantity'],$searchable['invoiceCents'],$searchable['packageStatus']);
                $matched=$query===''||$matcher->matches($searchable);
                if(!$invoicePage&&!$matched)continue;
                $item=self::publicItem($row,$payload,$status);
                if(!ShipmentFilters::matchesStatus($item,$filters))continue;
                if($invoicePage){$invoiceItems[]=$item;$invoiceRecords[]=[$row,$payload];$invoiceMatches[$item['id']]=$matched;continue;}
                if($total%self::PAGE_SIZE===0)$lastPage=[];$lastPage[]=$item;
                if($total>=$offset&&$total<$offset+self::PAGE_SIZE)$items[]=$item;$total++;
            }
            if($invoicePage)$invoicePage->add($quality->enrich($company,$invoiceItems,$invoiceRecords),$invoiceMatches);
            $cursor=$rows?end($rows):null;
        }while(count($rows)===250);
        if($invoicePage){
            $result=$invoicePage->result();
            return [...$result,'items'=>$this->invoiceItems($company,$result['invoiceGroups'],$quality,$includePaymentDetails,$includeShippingLabels),
                'totalRecords'=>$totalRecords,'query'=>$query,'filters'=>$filters];
        }
        $last=max(0,(int)ceil($total/self::PAGE_SIZE)-1);
        if($page>$last){$page=$last;$items=$lastPage;}
        $items=$quality->enrich($company,$items,includePaymentDetails:$includePaymentDetails);
        return ['items'=>$items,'total'=>$total,'totalRecords'=>$totalRecords,'query'=>$query,'filters'=>$filters,'page'=>$page,'pageSize'=>self::PAGE_SIZE];
    }
    private function invoiceItems(int $company,array $groups,ShipmentDataQuality $quality,bool $includePaymentDetails,bool $includeShippingLabels):array
    {
        $ids=array_merge(...$groups);$items=[];
        $status=new ShipmentDispatchStatus($this->cipher);
        foreach(array_chunk($ids,200) as $batch){
            $rows=$this->db->all('SELECT * FROM shipment_entries WHERE company_id=? AND removed_at IS NULL AND id IN ('
                .implode(',',array_fill(0,count($batch),'?')).')',[$company,...$batch]);
            $records=$status->read($company,$rows);
            $view=$quality->enrich($company,array_map(fn($record)=>self::publicItem(...$record),$records),$records,true,$includePaymentDetails,includeShippingLabels:$includeShippingLabels);
            foreach($view as $item){unset($item['invoiceOrderIdentity']);$items[$item['id']]=$item;}
        }
        $ordered=[];
        foreach($ids as $id)if(isset($items[$id]))$ordered[]=$items[$id];
        return $ordered;
    }
    public function export(int $company,array $selection,mixed $query='',mixed $filterValues=[]): array
    {
        $query=ShipmentSearch::query($query);$filters=$this->filters($filterValues);
        [$sql,$params]=ShipmentFilters::predicate($filters,false,false);$matcher=new ShipmentSearch($query);
        [$scope,$scopeParams]=CustomerCities::scope($this->db,$company,self::scope($company,$selection));
        if($this->customerView)$scope.=' AND '.ShipmentCustomerVisibility::SQL;
        return $this->db->transaction(function()use($company,$selection,$query,$filters,$sql,$params,$matcher,$scope,$scopeParams){
            $items=[];$cursor=null;$quality=new ShipmentDataQuality($this->db,$this->cipher);
            do{
                $values=[...$scopeParams,...$params];$after='';
                if($cursor){$after=' AND (ordered_at<? OR (ordered_at=? AND id<?))';array_push($values,$cursor['ordered_at'],$cursor['ordered_at'],$cursor['id']);}
                $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$scope.$sql.$after.' ORDER BY ordered_at DESC,id DESC LIMIT 250',$values);
                $batch=[];$records=[];
                foreach((new ShipmentDispatchStatus($this->cipher))->read($company,$rows) as [$row,$payload,$status]){
                    if(!ShipmentCustomerVisibility::readable($row,$payload,$this->customerView,$this->hideReturns))continue;
                    if($filters['customerType']!==''&&($payload['customerType']??'')!==$filters['customerType'])continue;
                    if(!$matcher->matches($payload))continue;
                    $item=self::publicItem($row,$payload,$status);if(!ShipmentFilters::matchesStatus($item,$filters))continue;
                    $batch[]=$item;$records[]=[$row,$payload];
                }
                array_push($items,...$quality->enrich($company,$batch,$records,forExport:true));
                if(count($items)>5000)Http::fail(400,'Tek dosyada en fazla 5000 kayıt aktarılabilir. Tarih aralığını daraltın.');
                $cursor=$rows?end($rows):null;
            }while(count($rows)===250);
        return ['items'=>$items,'total'=>count($items),'query'=>$query,'filters'=>$filters,'summary'=>(new ShipmentExportSummary($this->db,$this->cipher))->read($company,$items),'city'=>$selection[0],'provider'=>$selection[1],'month'=>$selection[2]];
        });
    }
    public function confirmInvoices(array $user,int $company,int $id,array $selection,mixed $items,mixed $value): array
    {
        if(!is_bool($value)||!is_array($items)||!array_is_list($items)||!$items||count($items)>500)Http::fail(400,'Fatura satırları geçersiz.');
        $versions=[];
        foreach($items as $item){
            $rowId=$item->id??null;$version=$item->version??null;
            if(!is_int($rowId)||$rowId<1||!is_int($version)||$version<1||isset($versions[$rowId]))Http::fail(400,'Fatura satırları geçersiz.');
            $versions[$rowId]=$version;
        }
        if(!isset($versions[$id]))Http::fail(400,'Fatura satırı bulunamadı.');
        ksort($versions);
        return TransactionRetry::run($this->db,function()use($user,$company,$selection,$versions,$value){
            $items=[];
            foreach($versions as $rowId=>$version)$items[]=$this->toggle($user,$company,$rowId,$selection,
                ['field'=>'invoiced','value'=>$value,'version'=>$version],true);
            return ['items'=>$items];
        });
    }

    public function toggle(array $user,int $company,int $id,array $selection,array $data,bool $inTransaction=false): array
    {
        ShipmentAccess::company($user,$company);
        $field=$data['field']??null;$value=$data['value']??null;$version=$data['version']??null;
        ShipmentCustomerVisibility::requireStatus($user,$field==='cancelled'?'cancelled':$value);
        // Accept older clients without recreating the retired purple status.
        if($field==='rowStatus'&&$value==='delivered')$value='completed';
        if(!empty($data['confirmFulfillment']))Http::fail(409,'Elle sevkiyat teyidi kaldırıldı. Sayfayı yenileyin; sipariş durumu pazaryerinden güncellenir.');
        if($field==='rowStatus'&&$value==='review')Http::fail(400,'Sevkiyat durumu geçersiz.');
        $validValue=$field==='rowStatus'?in_array($value,ShipmentStatus::VALUES,true):is_bool($value);
        if(!in_array($field,['cancelled','invoiced','shipped','rowStatus'],true)||!$validValue||!is_int($version)||$version<1)Http::fail(400,'İşaretleme bilgisi geçersiz.');
        $save=function() use($user,$company,$id,$selection,$field,$value,$version,$data,$inTransaction){
            [$scope,$params]=CustomerCities::scope($this->db,$company,self::scope($company,$selection));
            $row=$this->db->one('SELECT * FROM shipment_entries WHERE id=? AND '.$scope.$this->db->lock(),[$id,...$params]);
            if(!$row)Http::fail(404,'Sevkiyat kaydı bulunamadı.');
            if($user['role']==='client')ShipmentCustomerVisibility::requireVisible($user,$row,$this->cipher->open($company,'shipment:'.$id,$row['payload']));
            if((int)$row['version']!==$version)Http::fail(409,'Kayıt başka bir kullanıcı tarafından değiştirildi. Liste yenilendi; tekrar deneyin.');
            if($field==='invoiced'&&$inTransaction){
                $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
                $previous=($payload['invoiceWorkDone']??false)===true;
                if($previous===$value)return ['id'=>$id,'invoiceWorkDone'=>$value,'version'=>$version];
                $payload['invoiceWorkDone']=$value;
                $stamp=gmdate('c');
                $saved=$this->db->run('UPDATE shipment_entries SET payload=?,version=version+1,updated=? WHERE company_id=? AND id=? AND version=?',[
                    $this->cipher->seal($company,'shipment:'.$id,$payload),$stamp,$company,$id,$version,
                ]);
                if($saved->rowCount()!==1)Http::fail(409,'Kayıt değişti. Listeyi yenileyip tekrar deneyin.');
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[
                    $user['id'],$company,'shipment.invoice_work',$id,json_encode(['invoiceWorkDone'=>$previous]),json_encode(['invoiceWorkDone'=>$value]),$stamp,
                ]);
                return ['id'=>$id,'invoiceWorkDone'=>$value,'version'=>$version+1];
            }
            $before=['cancelled'=>(bool)$row['cancelled'],'invoiced'=>(bool)$row['invoiced'],'shipped'=>(bool)$row['shipped'],'rowStatus'=>ShipmentStatus::resolve($row)];
            $target=$field==='rowStatus'?$value:($value?$field:($before['rowStatus']===$field?'':$before['rowStatus']));
            if(isset($data['confirmation'])){
                (new ShipmentStatusConfirmation($this->db,$this->cipher))->verify($company,$row,$target,$data['confirmation']);
            }
            $after=$before;
            if($field==='invoiced'){
                $after['invoiced']=$value;
                $changed=$this->db->run('UPDATE shipment_entries SET invoiced=?,version=version+1,updated=? WHERE id=? AND company_id=? AND version=?',[(int)$value,gmdate('c'),$id,$company,$version]);
            }else{
                $status=$field==='rowStatus'?$value:($value?$field:($before['rowStatus']===$field?'':$before['rowStatus']));
                $after['rowStatus']=$status;$after['cancelled']=$status==='cancelled';$after['shipped']=$status==='shipped';
                if($status==='invoiced')$after['invoiced']=true;
                elseif($status===''&&$before['rowStatus']==='invoiced')$after['invoiced']=false;
                $changed=$this->db->run('UPDATE shipment_entries SET row_status=?,cancelled=?,shipped=?,invoiced=?,version=version+1,updated=? WHERE id=? AND company_id=? AND version=?',[$status,(int)$after['cancelled'],(int)$after['shipped'],(int)$after['invoiced'],gmdate('c'),$id,$company,$version]);
            }
            if($changed->rowCount()!==1)Http::fail(409,'Kayıt değişti. Listeyi yenileyip tekrar deneyin.');
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,'shipment.'.$field,$id,json_encode($before),json_encode($after),gmdate('c')]);
            (new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$id,'status',$before,$after);
            if($after['rowStatus']==='shipped'&&$before['rowStatus']!=='shipped')(new ShipmentDispatchNotifications($this->db,$this->cipher))->record($user,$company,[$row]);
            ShipmentFulfillment::confirm($this->db,$this->cipher,$user,$company,$id,$field!=='invoiced');
            $saved=$this->db->one('SELECT * FROM shipment_entries WHERE id=? AND company_id=?',[$id,$company]);
            $status=(new ShipmentDispatchStatus($this->cipher))->read($company,[$saved])[0][2];
            return ['id'=>$id,...$after,...$status,'version'=>$version+1];
        };
        return $inTransaction?$save():TransactionRetry::run($this->db,$save);
    }
}
