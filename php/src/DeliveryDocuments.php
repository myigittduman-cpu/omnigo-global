<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliveryDocuments
{
    public function __construct(private Database $db,private DeliveryCipher $cipher,private DeliveryRepository $orders,private bool $visibleOnly=false) {}
    public function create(int $company,int $user,array $input): array
    {
        $city=Validation::choice($input['city']??null,DeliveryMapper::CITIES);$date=Validation::date($input['deliveryDate']??null);
        $request=$input['requestId']??'';$selection=$input['orders']??null;
        if(!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{32,64}$/D',$request)||!is_array($selection)||!array_is_list($selection)||count($selection)<1||count($selection)>50)Http::fail(400,'Aynı şehir için 1–50 sipariş seçin.');
        $ids=[];foreach($selection as $v){$v=(array)$v;if(!is_int($v['id']??null)||$v['id']<1||!is_int($v['version']??null)||isset($ids[$v['id']]))Http::fail(400,'Sipariş seçimini kontrol edin.');$ids[$v['id']]=$v['version'];}ksort($ids);
        $hash=hash('sha256',json_encode([$city,$date,$ids],JSON_THROW_ON_ERROR));
        return $this->db->transaction(function()use($company,$user,$city,$date,$request,$hash,$ids){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $prior=$this->db->one('SELECT * FROM delivery_documents WHERE company_id=? AND request_id=?',[$company,$request]);
            if($prior){if(!hash_equals($prior['request_hash'],$hash))Http::fail(409,'Evrak isteği değişti. Listeyi yenileyin.');return $this->decode($prior);}
            $orders=[];foreach($ids as $id=>$version){
                $row=$this->db->one('SELECT * FROM delivery_orders WHERE id=? AND company_id=?'.$this->db->lock(),[$id,$company]);
                if(!$row)Http::fail(404,'Sipariş bulunamadı.');
                if($this->visibleOnly)MarketplaceVisibility::requireVisible($row['provider']);
                $value=$this->orders->operational($row,true);DeliveryDocumentGuard::assertVerified($value);
                if((int)$row['version']!==$version||$row['city']!==$city||$row['document_id'])Http::fail(409,'Bir sipariş değişmiş veya evrakı daha önce hazırlanmış. Listeyi yenileyin.');
                $document=DeliveryPlan::documentOrder($value);
                if($value['issues'])Http::fail(422,'Eksik teslimat bilgilerini pazaryerinde tamamlayıp aktarımı bekleyin.');
                $orders[]=$document;
            }
            $created=gmdate('c');$value=['city'=>$city,'deliveryDate'=>$date,'created'=>$created,'orders'=>$orders];
            $payload=$this->cipher->seal($company,'document:'.$request,$value);
            $this->db->run('INSERT INTO delivery_documents(company_id,request_id,request_hash,city,delivery_date,order_count,payload,created_by,created) VALUES(?,?,?,?,?,?,?,?,?)',[$company,$request,$hash,$city,$date,count($orders),$payload,$user,$created]);$id=$this->db->insertId();
            foreach($ids as $orderId=>$version)$this->db->run('UPDATE delivery_orders SET document_id=? WHERE id=? AND company_id=?',[$id,$orderId,$company]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$user,$company,'Teslimat evrağı oluşturuldu',$id,$created]);
            return [...$value,'id'=>$id];
        });
    }
    public function archive(int $company,int $page): array
    {
        if($page<0||$page>2000)Http::fail(400,'Sayfa numarası geçersiz.');
        if($this->visibleOnly)return $this->visibleArchive($company,$page);
        $rows=$this->db->all('SELECT id,city,delivery_date AS deliveryDate,order_count AS orderCount,created FROM delivery_documents WHERE company_id=? ORDER BY id DESC LIMIT 21 OFFSET '.($page*20),[$company]);
        return ['items'=>array_slice($rows,0,20),'page'=>$page,'hasMore'=>count($rows)>20];
    }
    public function get(int $company,int $id): array
    {
        $row=$this->db->one('SELECT * FROM delivery_documents WHERE company_id=? AND id=?',[$company,$id]);if(!$row)Http::fail(404,'Evrak bulunamadı.');return $this->decode($row);
    }
    private function decode(array $row): array
    {
        $value=[...$this->cipher->open((int)$row['company_id'],'document:'.$row['request_id'],$row['payload']),'id'=>(int)$row['id']];
        if($this->visibleOnly){
            $value['orders']=MarketplaceVisibility::rows($value['orders'],'provider');
            if(!$value['orders'])Http::fail(404,'Bu pazaryerinin evrakları geçici olarak gizlenmiştir.');
        }
        foreach($value['orders'] as $index=>$order){
            $current=$this->db->one('SELECT * FROM delivery_orders WHERE company_id=? AND id=?',[(int)$row['company_id'],(int)($order['id']??0)]);
            if(!$current)Http::fail(409,DeliveryDocumentGuard::MESSAGE);
            $operational=$this->orders->operational($current,true);DeliveryDocumentGuard::assertVerified($operational);
            // Historical documents cannot assign a new or superseded label to an old selection.
            $liveLabels=(int)($current['document_id']??0)===(int)$row['id']?$operational['shippingLabels']:[];
            foreach($order['shippingLabels']??[] as $part=>$label){
                if(!in_array($label,$liveLabels,true))$value['orders'][$index]['shippingLabels'][$part]['value']=null;
            }
            if(($operational['approvedReturnLines']??[])!==($order['approvedReturnLines']??[]))Http::fail(409,'Evraktaki ürünlerin iade durumu değişti. Güncel teslimat listesinden evrağı yeniden hazırlayın.');
        }
        return $value;
    }
    private function visibleArchive(int $company,int $page): array
    {
        $items=[];$cursor=PHP_INT_MAX;$matched=0;
        do{
            $rows=$this->db->all('SELECT * FROM delivery_documents WHERE company_id=? AND id<? ORDER BY id DESC LIMIT 100',[$company,$cursor]);
            foreach($rows as $row){
                $cursor=(int)$row['id'];
                $value=$this->cipher->open($company,'document:'.$row['request_id'],$row['payload']);
                $orders=MarketplaceVisibility::rows($value['orders'],'provider');
                if(!$orders||$matched++<$page*20)continue;
                $items[]=['id'=>$cursor,'city'=>$row['city'],'deliveryDate'=>$row['delivery_date'],'orderCount'=>count($orders),'created'=>$row['created']];
                if(count($items)>20)break 2;
            }
        }while(count($rows)===100);
        return ['items'=>array_slice($items,0,20),'page'=>$page,'hasMore'=>count($items)>20];
    }
}
