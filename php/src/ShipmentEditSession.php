<?php
declare(strict_types=1);
namespace Omnigo;

/** One explicit save commits an ordered edit session or none of it. */
final class ShipmentEditSession
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function save(array $user,int $company,array $selection,array $input): array
    {
        ShipmentAccess::company($user,$company);
        $request=$input['requestId']??'';
        $changes=$input['changes']??null;
        if(!is_string($request)||!preg_match('/^[a-f0-9]{48}$/D',$request)||!is_array($changes)||!array_is_list($changes)||!count($changes)||count($changes)>100)
            Http::fail(400,'Kaydedilecek işlemler geçersiz. Bir defada en fazla 100 işlem kaydedilebilir.');
        $hash=hash('sha256',json_encode([$selection,$changes],JSON_THROW_ON_ERROR));
        return TransactionRetry::run($this->db,function()use($user,$company,$selection,$changes,$request,$hash){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))Http::fail(404,'Firma bulunamadı.');
            // The company lock serializes retry detection and all writers.
            $previous=$this->db->all("SELECT after_value FROM audit WHERE company_id=? AND user_id=? AND action='shipment.session_saved' AND after_value LIKE ? ORDER BY id DESC LIMIT 1",[$company,$user['id'],'%"requestId":"'.$request.'"%']);
            foreach($previous as $audit){
                $saved=json_decode($audit['after_value'],true,512,JSON_THROW_ON_ERROR);
                if(($saved['requestId']??'')!==$request)continue;
                if(($saved['hash']??'')!==$hash)Http::fail(409,'Bu kayıt isteği farklı işlemlerle kullanılmış.');
                return ['saved'=>count($saved['records']),'replayed'=>true];
            }
            $writer=new ShipmentWriter($this->db,$this->cipher,true);
            $deletion=new ShipmentDeletion($this->db,$this->cipher,true);
            $records=[];$seen=[];
            foreach($changes as $change){
                if($change instanceof \stdClass)$change=(array)$change;
                if(!is_array($change))Http::fail(400,'İşlem bilgisi geçersiz.');
                $type=$change['type']??'';
                if($type==='create'){
                    $result=$writer->create($user,$company,$selection,['record'=>$change['record']??null,'requestId'=>$change['requestId']??null]);
                }else{
                    $id=$change['id']??null;
                    if(!is_int($id)||$id<1||isset($seen[$id]))Http::fail(400,'Bir kayıt için yalnız bir son işlem gönderin.');
                    $seen[$id]=true;
                    $result=match($type){
                        'update'=>$writer->update($user,$company,$id,$selection,['record'=>$change['record']??null,'version'=>$change['version']??null]),
                        'delete'=>$deletion->save($user,$company,$id,$selection,['version'=>$change['version']??null]),
                        default=>Http::fail(400,'İşlem türü geçersiz.')
                    };
                }
                $records[]=['id'=>$result['id'],'type'=>$type];
            }
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',
                [$user['id'],$company,'shipment.session_saved',$company,json_encode(['requestId'=>$request,'hash'=>$hash,'records'=>$records],JSON_THROW_ON_ERROR),gmdate('c')]);
            return ['saved'=>count($records),'replayed'=>false];
        });
    }
}
