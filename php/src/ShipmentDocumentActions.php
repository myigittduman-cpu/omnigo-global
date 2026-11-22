<?php
declare(strict_types=1);
namespace Omnigo;

/** Local document tracking marks are independent of marketplace fulfillment and uploaded files. */
final class ShipmentDocumentActions
{
    private const ACTIONS=['deliver','remove'];

    public function __construct(private Database $db,private DeliveryCipher $cipher){}

    public function apply(array $actor,int $company,array $input): array
    {
        Security::admin($actor);
        ShipmentAccess::company($actor,$company);
        $kind=$input['kind']??null;
        $bucket=$input['bucket']??null;
        $action=$input['action']??null;
        $items=$input['items']??null;
        if(!is_string($kind)||!isset(CustomerPermissionCatalog::DOCUMENT_PAGES[$kind])||!is_string($bucket)||!in_array($action,self::ACTIONS,true)
            ||!is_array($items)||!array_is_list($items)||!count($items)||count($items)>50)Http::fail(400,'Belge işlemini ve seçilen kayıtları kontrol edin.');
        $versions=[];
        foreach($items as $item){
            if($item instanceof \stdClass)$item=(array)$item;
            if(!is_array($item)||!is_int($item['id']??null)||$item['id']<1
                ||!is_int($item['version']??null)||$item['version']<1||isset($versions[$item['id']]))Http::fail(400,'Belge kaydı seçimi geçersiz.');
            $versions[$item['id']]=$item['version'];
        }
        return TransactionRetry::run($this->db,function()use($actor,$company,$kind,$bucket,$action,$versions){
            $queue=new ShipmentDocumentQueue($this->db,$this->cipher);
            $rows=$queue->selected($company,$kind,$bucket,$versions);
            $now=gmdate('c');
            $updated=0;
            foreach($rows as [$row,$payload]){
                $id=(int)$row['id'];
                $state=self::deliveryState($row,$payload);
                if($action==='deliver'&&!$state['canMarkDelivered'])Http::fail(409,'Seçimde teslim edildi olarak işaretlenemeyen bir sipariş var. Listeyi yenileyip tekrar deneyin.');
                if($action==='deliver'&&$state['delivered'])continue;
                $mark=['at'=>$now,'userId'=>(int)$actor['id']];
                if($action==='deliver')$payload['documentTracking']['delivered']=$mark;
                else $payload['documentTracking']['removed'][$kind]=$mark;
                $this->db->run('UPDATE shipment_entries SET payload=?,version=version+1,updated=? WHERE company_id=? AND id=?',
                    [$this->cipher->seal($company,'shipment:'.$id,$payload),$now,$company,$id]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,NULL,?,?)',
                    [$actor['id'],$company,'shipment.document_tracking.'.$action,$id,json_encode(['kind'=>$kind,'bucket'=>$bucket],JSON_THROW_ON_ERROR),$now]);
                $updated++;
            }
            return ['updated'=>$updated];
        });
    }

    public static function deliveryState(array $row,array $payload): array
    {
        $returned=$payload['marketplaceReturn']??null;
        $canMark=ShipmentCustomerVisibility::visible($row,$payload)&&(!$returned||!empty($returned['partial']));
        $live=ShipmentSourceStatus::isDelivered($payload)
            ||(empty($row['source_key'])&&in_array(ShipmentStatus::resolve($row),['completed','delivered'],true));
        $manual=$canMark&&!$live&&!empty($payload['documentTracking']['delivered']);
        return ['delivered'=>$canMark&&($live||$manual),'deliveredManually'=>$manual,'canMarkDelivered'=>$canMark];
    }
}
