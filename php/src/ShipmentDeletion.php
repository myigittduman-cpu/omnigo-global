<?php
declare(strict_types=1);
namespace Omnigo;

final class ShipmentDeletion
{
    public function __construct(private Database $db,private DeliveryCipher $cipher,private bool $withinSession=false){}
    private function scope(int $company,array $selection): array
    {
        [$city,$provider,$month]=ShipmentRepository::selection(['city'=>$selection[0]??null,'provider'=>$selection[1]??null,'month'=>$selection[2]??null],true);
        $sql='s.company_id=? AND s.month=?';$params=[$company,$month];
        if($provider!=='all'){$sql.=' AND s.provider=?';$params[]=$provider;}else $sql.=' AND '.MarketplaceVisibility::sql('s.provider');
        if($city!=='all'){$sql.=' AND s.city=?';$params[]=$city;}return [$sql,$params];
    }
    public function latest(array $user,int $company,array $selection): ?array
    {
        ShipmentAccess::company($user,$company);[$scope,$args]=$this->scope($company,$selection);
        // A saved edit dismisses earlier delete shortcuts for this user's company workspace.
        $row=$this->db->one("SELECT s.id,s.version FROM audit a JOIN shipment_entries s ON s.id=a.record_id AND s.company_id=a.company_id WHERE $scope AND s.removed_at IS NOT NULL AND a.user_id=? AND a.action='shipment.delete' AND a.id=(SELECT MAX(b.id) FROM audit b WHERE b.company_id=a.company_id AND b.record_id=a.record_id AND b.action='shipment.delete') AND NOT EXISTS (SELECT 1 FROM audit e WHERE e.company_id=a.company_id AND e.user_id=a.user_id AND e.action='shipment.edit' AND e.id>a.id) ORDER BY a.id DESC LIMIT 1",[...$args,$user['id']]);
        return $row?['id'=>(int)$row['id'],'version'=>(int)$row['version']]:null;
    }
    public function save(array $user,int $company,int $id,array $selection,array $input,bool $restore=false): array
    {
        ShipmentAccess::company($user,$company);[$scope,$args]=$this->scope($company,$selection);$version=$input['version']??null;
        if(!is_int($version)||$version<1)Http::fail(400,'Kayıt sürümü geçersiz.');
        $action=function()use($user,$company,$id,$scope,$args,$version,$restore){
            $row=$this->db->one('SELECT s.* FROM shipment_entries s WHERE s.id=? AND '.$scope.$this->db->lock(),[$id,...$args]);
            if(!$row)Http::fail(404,'Sevkiyat kaydı bulunamadı.');
            if($user['role']==='client')ShipmentCustomerVisibility::requireVisible($user,$row,$this->cipher->open($company,'shipment:'.$id,$row['payload']));
            if(!$restore&&$row['removed_at']!==null&&(int)$row['version']===$version+1)return ['id'=>$id,'version'=>$version+1,'replayed'=>true];
            if((int)$row['version']!==$version||($restore? $row['removed_at']===null:$row['removed_at']!==null))Http::fail(409,'Satır değişmiş. Listeyi yenileyip tekrar deneyin.');
            if($restore){
                $last=$this->db->one("SELECT user_id FROM audit WHERE company_id=? AND record_id=? AND action='shipment.delete' ORDER BY id DESC LIMIT 1",[$company,$id]);
                if(!$last||(int)$last['user_id']!==(int)$user['id'])Http::fail(403,'Yalnızca kendi sildiğiniz satırı geri alabilirsiniz.');
            }
            $now=gmdate('c');$operation=$restore?'restore':'delete';
            $this->db->run('UPDATE shipment_entries SET removed_at=?,version=version+1,updated=? WHERE id=? AND company_id=?',[$restore?null:$now,$now,$id,$company]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[$user['id'],$company,'shipment.'.$operation,$id,json_encode(['version'=>$version+1]),$now]);
            (new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$id,$operation,['recordState'=>$restore?'Silindi':'Listede'],['recordState'=>$restore?'Listede':'Silindi']);
            return ['id'=>$id,'version'=>$version+1,'replayed'=>false];
        };
        if($this->withinSession&&$this->db->pdo->inTransaction())return $action();
        return TransactionRetry::run($this->db,$action);
    }
}
