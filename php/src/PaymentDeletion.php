<?php
declare(strict_types=1);
namespace Omnigo;

/** Versioned tenant-scoped deletion; the complete previous value remains in audit. */
final class PaymentDeletion
{
    public function __construct(private Database $db){}
    public function delete(array $actor,int $company,int $id,array $input):array
    {
        Security::admin($actor);
        if(!is_int($input['version']??null)||$input['version']<1)Http::fail(400,'Hakediş sürümünü yenileyin.');
        $this->deleteRows($actor,$company,[$id=>$input['version']],false);
        return ['deleted'=>true,'id'=>$id];
    }

    public function deleteSelection(array $actor,int $company,array $input):array
    {
        Security::admin($actor);
        $selection=$input['payments']??null;
        if(!is_array($selection)||!array_is_list($selection)||!$selection||count($selection)>5000)
            Http::fail(400,'Silmek için 1 ile 5.000 arasında hakediş seçin.');
        $versions=[];
        foreach($selection as $entry){
            $entry=(array)$entry;
            $id=$entry['id']??null;
            $version=$entry['version']??null;
            if(!is_int($id)||$id<1||!is_int($version)||$version<1||isset($versions[$id]))
                Http::fail(400,'Seçili hakedişleri yenileyip yeniden seçin.');
            $versions[$id]=$version;
        }
        $this->deleteRows($actor,$company,$versions,true);
        return ['deleted'=>count($versions)];
    }

    private function deleteRows(array $actor,int $company,array $versions,bool $allowApproved):void
    {
        TransactionRetry::run($this->db,function()use($actor,$company,$versions,$allowApproved){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            foreach($versions as $id=>$version){
                $row=$this->db->one("SELECT version,payload FROM records WHERE company_id=? AND kind='payments' AND id=?".$this->db->lock(),[$company,$id]);
                if(!$row)Http::fail(404,'Hakediş kaydı bulunamadı.');
                if((int)$row['version']!==$version)Http::fail(409,'Hakediş değişti. Güncel kaydı kontrol edip tekrar deneyin.');
                $payment=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                if(!$allowApproved&&PaymentApproval::isApproved($payment))Http::fail(409,'Bu ödeme onaylandı ve tamamlandı. Silinemez.');
                if(!empty($payment['integration'])||!empty($payment['archivedAt']))Http::fail(409,'Arşivdeki otomatik hakediş bu alandan silinemez.');
                $this->db->run("DELETE FROM records WHERE company_id=? AND kind='payments' AND id=? AND version=?",[$company,$id,$version]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,NULL,?)',[$actor['id'],$company,'Hakediş silindi',$id,$row['payload'],gmdate('c')]);
                CustomerServiceNotifications::payment($this->db,$actor,$company,$id,$this->db->insertId(),true,true);
            }
            if(UpcomingPayoutReviewStore::available($this->db)) {
                (new UpcomingPayoutReviewService($this->db))->observe($company);
            }
        });
    }
}
