<?php
declare(strict_types=1);
namespace Omnigo;

/** Central account directory. Credentials and operational history never enter list responses. */
final class AccountDirectory
{
    public function __construct(private Database $db){}
    private function query():string
    {
        return 'SELECT u.*,n.username,COALESCE(c.active,1) AS active,p.version AS profile_version,CASE WHEN p.avatar IS NULL THEN 0 ELSE 1 END AS avatar,p.deleted_at,cp.payload AS company_profile,cp.version AS company_logo_version,CASE WHEN cp.logo IS NULL OR LENGTH(cp.logo)=0 THEN 0 ELSE 1 END AS company_logo FROM users u LEFT JOIN user_login_names n ON n.user_id=u.id LEFT JOIN account_controls c ON c.user_id=u.id LEFT JOIN account_profiles p ON p.user_id=u.id LEFT JOIN company_profiles cp ON cp.company_id=u.company_id';
    }
    public function row(int $id):array
    {
        $row=$this->db->one($this->query().' WHERE u.id=?',[$id]);
        if(!$row||$row['deleted_at']!==null||($row['role']==='client'&&$row['company_id']===null))Http::fail(404,'Kullanıcı bulunamadı.');
        return $row;
    }
    public static function revision(array $row):string
    {
        return hash('sha256',json_encode(array_intersect_key($row,array_flip(['id','name','email','password','role','company_id','username','active','profile_version'])),JSON_THROW_ON_ERROR));
    }
    public static function publicRow(array $row):array
    {
        $profile=isset($row['company_profile'])?json_decode($row['company_profile'],true,32,JSON_THROW_ON_ERROR):[];
        $role=$row['role']==='client'?(CustomerAccountRoles::roles($profile)[$row['id']]??null):null;
        return ['id'=>(int)$row['id'],'name'=>$row['name'],'email'=>$row['email'],'username'=>$row['username'],'role'=>$row['role'],'storeRole'=>$role,'companyId'=>$row['company_id']===null?null:(int)$row['company_id'],'active'=>(bool)$row['active'],'avatarVersion'=>AccountPortraitSource::version($row,['logo'=>$row['company_logo']??null,'version'=>$row['company_logo_version']??0],['avatar'=>$row['avatar'],'version'=>$row['profile_version']]),'revision'=>self::revision($row)];
    }
    public function listing(array $actor,array $filter):array
    {
        Security::admin($actor);
        $page=max(1,min(100000,(int)($filter['page']??1)));$where=['p.deleted_at IS NULL',"(u.role='admin' OR u.company_id IS NOT NULL)"];$params=[];
        $search=trim((string)($filter['q']??''));if(mb_strlen($search)>150)Http::fail(400,'Arama metni çok uzun.');
        if($search!==''){$where[]='(LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(n.username) LIKE ?)';$term='%'.mb_strtolower($search).'%';array_push($params,$term,$term,$term);}
        if(($filter['role']??'')!==''){$where[]='u.role=?';$params[]=Validation::choice($filter['role'],['admin','client']);}
        if(($filter['company']??'')!==''){$company=filter_var($filter['company'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$company)Http::fail(400,'Mağaza seçimini kontrol edin.');$where[]='u.company_id=?';$params[]=$company;}
        $size=(int)($filter['pageSize']??50)===3?3:50;
        $sql=$this->query().' WHERE '.implode(' AND ',$where);
        $total=(int)$this->db->one('SELECT COUNT(*) AS total FROM ('.$sql.') directory_count',$params)['total'];
        $pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
        $rows=$this->db->all($sql.' ORDER BY u.id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size),$params);
        return ['items'=>array_map(self::publicRow(...),$rows),'page'=>$page,'pageSize'=>$size,'pageCount'=>$pages,'total'=>$total,'hasMore'=>$page<$pages,'companies'=>$this->db->all('SELECT id,name FROM companies ORDER BY name')];
    }
    public function assertRevision(array $row,mixed $value):void
    {
        if(!is_string($value)||!hash_equals(self::revision($row),$value))Http::fail(409,'Kullanıcı bilgileri değişti. Listeyi yeniden açıp güncel bilgileri kontrol edin.');
    }
    public function audit(array $actor,int $target,string $action):void
    {
        $this->db->run('INSERT INTO account_audit(actor_id,target_id,action,created) VALUES(?,?,?,?)',[$actor['id'],$target,$action,time()]);
    }
    public function revoke(int $id):void
    {
        $this->db->run('DELETE FROM client_session_operators WHERE session_token IN (SELECT token FROM sessions WHERE user_id=?)',[$id]);
        (new CustomerManagement($this->db))->revoke($id);
        foreach(['password_change_challenges','admin_mfa_challenges','admin_mfa_sessions'] as $table)$this->db->run("DELETE FROM $table WHERE user_id=?",[$id]);
        $this->db->run('UPDATE notification_devices SET enabled=0,generation=generation+1 WHERE user_id=?',[$id]);
        $this->db->run("UPDATE push_deliveries SET state='cancelled' WHERE device_id IN (SELECT id FROM notification_devices WHERE user_id=?) AND state IN ('pending','sending')",[$id]);
    }
    public function assign(int $id,?int $from,?int $to,?string $storeRole=null):void
    {
        CustomerAccountAssignment::assign($this->db,$id,$from,$to,$storeRole);
    }
}
