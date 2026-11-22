<?php
declare(strict_types=1);
namespace Omnigo;

final class CustomerStores
{
    public function __construct(private Database $db) {}
    public function list(int $company): array
    {
        return array_map(fn($row)=>array_intersect_key($row,array_flip(['id','name','storeName','version'])),MarketplaceVisibility::rows((new Repository($this->db))->records($company,'channels',['role'=>'admin']),'name'));
    }
    public function save(array $user,int $company,int $id,array $data): void
    {
        Security::admin($user);
        $name=Validation::text($data['storeName']??null,'Mağaza adı',150);
        $this->db->transaction(function()use($user,$company,$id,$data,$name){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one("SELECT * FROM records WHERE id=? AND company_id=? AND kind='channels'",[$id,$company]);
            if(!$row)Http::fail(404,'Mağaza bulunamadı.');
            if(($data['version']??null)!==(int)$row['version'])Http::fail(409,'Mağaza bilgileri değişti. Pencereyi yeniden açın.');
            $value=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            MarketplaceVisibility::requireVisible($value['name']);
            // A display-name edit must not reset activation, API metadata or synchronization state.
            $value['storeName']=$name;
            $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$at=gmdate('c');
            $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,$at,$id]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,'Mağaza adı güncellendi',$id,$row['payload'],$payload,$at]);
        });
    }
}
