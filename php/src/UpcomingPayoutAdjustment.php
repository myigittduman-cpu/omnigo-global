<?php
declare(strict_types=1);
namespace Omnigo;

/** Company-local presentation overrides for imported forecast rows. */
final class UpcomingPayoutAdjustment
{
    private const KEY='payout_adjustments';

    public function __construct(private Database $db) {}

    private function stored(int $company): ?array
    {
        return (new FinanceStore($this->db))->state($company,self::KEY);
    }

    /** Called in the approval transaction. Explicit removals remain in effect. */
    public function supersedeEdits(int $company,array $published,array $accepted): void
    {
        if(!PazaramaCommissionStore::available($this->db)||!($stored=$this->stored($company)))return;
        $changes=json_decode($stored['payload'],true,32,JSON_THROW_ON_ERROR);
        $removedEdit=false;
        foreach([...$published,...$accepted] as $item){
            $id=$item['id'];
            if(!is_string($id)||!array_key_exists($id,$changes)||!empty($changes[$id]['removed']))continue;
            unset($changes[$id]);
            $removedEdit=true;
        }
        if($removedEdit)(new FinanceStore($this->db))->writeState($company,self::KEY,$changes,$stored);
    }

    public function apply(int $company,array $items,bool $manage,array $payments=[],?string $today=null): array
    {
        if(!PazaramaCommissionStore::available($this->db))return $items;
        $changes=json_decode($this->stored($company)['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        $visible=[];
        $removedDays=[];
        foreach($items as $item){
            if(is_string($item['id'])&&in_array($item['source'],['marketplace','marketplace_panel'],true)){
                $change=$changes[$item['id']]??null;
                if(!empty($change['removed'])){
                    $removedDays[$item['channel']][$item['date']]=true;
                    continue;
                }
                if($manage)$item['adjustmentRevision']=hash('sha256',json_encode([
                    $item['id'],$item['date'],$item['amountCents'],$item['verification'],$change,
                ],JSON_THROW_ON_ERROR));
                if($change!==null){
                    $item['date']=$change['date'];
                    $item['amountCents']=$change['amountCents'];
                    $item['adjusted']=true;
                    $item['verification']='estimated';
                }
            }
            $visible[]=$item;
        }
        if($removedDays&&$payments&&$today!==null){
            // Hiding a marketplace line must not hide an independently saved manual estimate.
            $manual=UpcomingPayments::snapshot($payments,array_keys($removedDays),$today);
            foreach([...$visible,...$manual['settled']] as $item){
                unset($removedDays[$item['channel']][$item['date']]);
            }
            foreach($manual['items'] as $item){
                if(!empty($item['manualEntry'])&&isset($removedDays[$item['channel']][$item['date']])){
                    $visible[]=$item;
                }
            }
        }
        usort($visible,static fn($a,$b)=>[$a['date'],$a['channel'],$a['id']]<=>[$b['date'],$b['channel'],$b['id']]);
        return $visible;
    }

    public function save(array $actor,int $company,array $input): array
    {
        Security::admin($actor);
        $id=$input['id']??null;$revision=$input['revision']??null;$action=$input['action']??null;
        if(!is_string($id)||strlen($id)>120||!is_string($revision)||!in_array($action,['edit','remove'],true))
            Http::fail(400,'Hakediş işlemi geçersiz.');
        $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $change=['removed'=>$action==='remove'];
        if($action==='edit'){
            $change['date']=Validation::date($input['date']??null);
            $change['amountCents']=Validation::integer($input['amountCents']??null);
            if($change['date']<$today||$change['amountCents']===0)Http::fail(400,'Bugün veya sonrası için sıfırdan büyük bir tutar girin.');
        }
        return TransactionRetry::run($this->db,function()use($actor,$company,$id,$revision,$today,$change){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $forecast=(new PaymentCalendar($this->db))->report($actor,$company,$today,$today,'all',true)['upcoming'];
            $current=null;
            foreach($forecast['periodItems'] as $item)if($item['id']===$id){$current=$item;break;}
            if($current===null||!hash_equals($current['adjustmentRevision']??'',$revision)||$revision==='')
                Http::fail(409,'Hakediş değişti. Listeyi yenileyip tekrar deneyin.');
            $stored=$this->stored($company);
            $changes=json_decode($stored['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
            $changes[$id]=$change;
            $now=gmdate('c');
            (new FinanceStore($this->db))->writeState($company,self::KEY,$changes,$stored);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[
                $actor['id'],$company,$change['removed']?'Otomatik hakediş tablodan kaldırıldı':'Otomatik hakediş düzeltildi',
                null,json_encode($current,JSON_THROW_ON_ERROR),json_encode($change,JSON_THROW_ON_ERROR),$now,
            ]);
            return ['saved'=>true];
        });
    }
}
