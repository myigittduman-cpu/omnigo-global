<?php
declare(strict_types=1);
namespace Omnigo;

final class ShipmentWriter
{
    public function __construct(private Database $db,private DeliveryCipher $cipher,private bool $withinSession=false){}
    private function scope(int $company,array $selection): array
    {
        [$city,$provider,$month]=ShipmentRepository::selection(['city'=>$selection[0]??null,'provider'=>$selection[1]??null,'month'=>$selection[2]??null],true);
        return ShipmentRepository::scope($company,[$city,$provider,$month]);
    }
    private function request(array $data): string
    {
        $key=$data['requestId']??null;if(!is_string($key)||!preg_match('/^[a-f0-9]{48}$/D',$key))Http::fail(400,'İşlem kimliği geçersiz.');return $key;
    }
    private function transaction(int $company,callable $action): mixed
    {
        if($this->withinSession&&$this->db->pdo->inTransaction())return $action();
        for($attempt=0;;$attempt++)try{return $this->db->transaction(function()use($company,$action){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))Http::fail(404,'Firma bulunamadı.');
            return $action();
        });}catch(\PDOException $error){if($attempt>=2||!in_array((int)($error->errorInfo[1]??0),[1205,1213],true))throw $error;usleep(50000*($attempt+1));}
    }
    private function audit(array $user,int $company,string $action,int $id,array $details): void
    {
        $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,NULL,?,?)',[$user['id'],$company,$action,$id,json_encode($details,JSON_THROW_ON_ERROR),gmdate('c')]);
    }
    public function undoState(array $user,int $company,array $selection): ?array
    {
        ShipmentAccess::company($user,$company);[$scope,$params]=$this->scope($company,$selection);
        $row=$this->db->one('SELECT id,version FROM shipment_entries WHERE '.$scope.' AND manual_user_id=? ORDER BY id DESC LIMIT 1',[...$params,$user['id']]);
        return $row?['id'=>(int)$row['id'],'version'=>(int)$row['version']]:null;
    }
    public function create(array $user,int $company,array $selection,array $data): array
    {
        ShipmentAccess::company($user,$company);$this->scope($company,$selection);$request=$this->request($data);$draft=ShipmentDraft::parse($data['record']??null,$selection[2]);
        if($selection[0]!=='all'&&$draft['province']!==$selection[0])Http::fail(400,'Teslimat ili seçili şehirle aynı olmalı.');
        if($selection[1]==='all')Http::fail(400,'Yeni satır için önce pazaryeri seçin.');
        $hash=hash('sha256',json_encode([$selection,$draft],JSON_THROW_ON_ERROR));
        return $this->transaction($company,function()use($user,$company,$selection,$request,$draft,$hash){
            $old=$this->db->one('SELECT id,manual_user_id,create_hash FROM shipment_entries WHERE company_id=? AND create_request=?',[$company,$request]);
            if($old){if((int)$old['manual_user_id']!==(int)$user['id']||$old['create_hash']!==$hash)Http::fail(409,'Bu işlem daha önce farklı bilgilerle kaydedildi. Listeyi yenileyin.');return ['id'=>(int)$old['id'],'replayed'=>true];}
            $now=gmdate('c');
            $this->db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,ordered_at,created,updated,manual_user_id,create_request,create_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$company,$draft['province'],$selection[1],$selection[2],'',ShipmentOrdering::timestamp($draft),$now,$now,$user['id'],$request,$hash]);
            $id=$this->db->insertId();$draft['packageStatus']='';$draft['fulfillment']=['version'=>1];
            $this->db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$this->cipher->seal($company,'shipment:'.$id,$draft),$id]);
            $this->audit($user,$company,'shipment.create',$id,['version'=>1]);(new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$id,'create',[],$draft);return ['id'=>$id,'replayed'=>false];
        });
    }
    public function update(array $user,int $company,int $id,array $selection,array $data): array
    {
        ShipmentAccess::company($user,$company);[$scope,$params]=$this->scope($company,$selection);$draft=ShipmentDraft::parse($data['record']??null,$selection[2]);$version=$data['version']??null;
        if(!is_int($version)||$version<1)Http::fail(400,'Kayıt sürümü geçersiz.');
        return $this->transaction($company,function()use($user,$company,$id,$scope,$params,$draft,$version){
            $row=$this->db->one('SELECT * FROM shipment_entries WHERE id=? AND '.$scope.$this->db->lock(),[$id,...$params]);
            if(!$row)Http::fail(404,'Sevkiyat kaydı bulunamadı.');
            if((int)$row['version']!==$version)Http::fail(409,'Kayıt değişmiş. Girdiğiniz bilgiler korunuyor; vazgeçip listeyi yenileyerek tekrar düzenleyin.');
            $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
            ShipmentCustomerVisibility::requireVisible($user,$row,$payload);
            $before=$payload;$changed=[];
            foreach($draft as $key=>$value)if(($payload[$key]??null)!==$value)$changed[]=$key;
            if(isset($payload['source']))$payload['source']['locked']=array_values(array_unique([...($payload['source']['locked']??[]),...$changed]));
            $payload=array_replace($payload,$draft);
            $this->db->run('UPDATE shipment_entries SET city=?,payload=?,ordered_at=?,version=version+1,updated=? WHERE id=?',[$draft['province'],$this->cipher->seal($company,'shipment:'.$id,$payload),ShipmentOrdering::timestamp($payload),gmdate('c'),$id]);
            $this->audit($user,$company,'shipment.edit',$id,['fields'=>$changed,'version'=>$version+1]);(new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$id,'edit',$before,$draft);return ['id'=>$id,'version'=>$version+1];
        });
    }
    public function undo(array $user,int $company,array $selection,array $data): array
    {
        ShipmentAccess::company($user,$company);$this->scope($company,$selection);$request=$this->request($data);
        if(!is_int($data['id']??null)||!is_int($data['version']??null))Http::fail(400,'Geri alınacak kayıt bilgisi geçersiz.');
        return $this->transaction($company,function()use($user,$company,$selection,$request,$data){
            $old=$this->db->one('SELECT id,manual_user_id FROM shipment_entries WHERE company_id=? AND undo_request=?',[$company,$request]);
            if($old){if((int)$old['manual_user_id']!==(int)$user['id']||(int)$old['id']!==$data['id'])Http::fail(409,'Geri alma işlemi uyuşmuyor.');return ['id'=>(int)$old['id'],'replayed'=>true];}
            $latest=$this->undoState($user,$company,$selection);
            if(!$latest||$latest['id']!==$data['id']||$latest['version']!==$data['version'])Http::fail(409,'Son kayıt değişmiş. Listeyi yenileyip geri almayı tekrar deneyin.');
            $now=gmdate('c');$changed=$this->db->run('UPDATE shipment_entries SET removed_at=?,undo_request=?,updated=?,version=version+1 WHERE id=? AND version=? AND removed_at IS NULL',[$now,$request,$now,$latest['id'],$latest['version']]);
            if($changed->rowCount()!==1)Http::fail(409,'Kayıt değişmiş. Listeyi yenileyin.');
            $this->audit($user,$company,'shipment.undo',$latest['id'],['version'=>$latest['version']+1]);(new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$latest['id'],'undo',['recordState'=>'Listede'],['recordState'=>'Silindi']);return ['id'=>$latest['id'],'replayed'=>false];
        });
    }
}
