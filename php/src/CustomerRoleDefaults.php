<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared presets are read only when describing, creating or explicitly resetting an account. */
final class CustomerRoleDefaults
{
    public function __construct(private Database $db) {}

    public static function install(Database $db): void
    {
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS store_role_defaults (
            store_role VARCHAR(32) PRIMARY KEY, payload TEXT NULL, version INT NOT NULL DEFAULT 0,
            updated_by INT NULL, updated_at VARCHAR(35) NULL
        )'.$suffix);
        foreach(array_keys(CustomerAccountRoles::LABELS) as $role){
            $sql=$db->mysql()
                ?'INSERT INTO store_role_defaults(store_role) VALUES(?) ON DUPLICATE KEY UPDATE store_role=store_role'
                :'INSERT INTO store_role_defaults(store_role) VALUES(?) ON CONFLICT(store_role) DO NOTHING';
            $db->run($sql,[$role]);
        }
    }

    public function read(): array
    {
        return ['roles'=>$this->roles(),'permissionsCatalog'=>array_filter(CustomerPermissionCatalog::pages(),
            static fn(array $page): bool=>empty($page['adminOnly'])),'actions'=>CustomerPermissionCatalog::ACTIONS];
    }

    public function roles(): array
    {
        $rows=[];
        try{$rows=array_column($this->db->all('SELECT store_role,payload,version FROM store_role_defaults'),null,'store_role');}
        catch(\PDOException $error){if(!$this->missingTable($error))throw $error;}
        $roles=[];
        foreach(CustomerAccountRoles::LABELS as $role=>$label){
            $row=$rows[$role]??null;
            $baseline=$role===CustomerAccountRoles::LOGISTICS?CustomerStandardPermissions::logistics():CustomerStandardPermissions::grants();
            $stored=isset($row['payload'])?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
            $merged=CustomerTableVisibility::withStatusDefaults(array_replace_recursive($baseline,$stored),$stored);
            $grants=CustomerPermissionCatalog::validateGrants($merged,$role);
            $roles[$role]=['label'=>$label,'version'=>(int)($row['version']??0),'grants'=>$grants];
        }
        return $roles;
    }

    public function save(array $actor,array $data): array
    {
        Security::admin($actor);
        $role=CustomerAccountRoles::validate($data['role']??null);
        $version=$data['version']??null;
        if(!is_int($version)||$version<0)Http::fail(400,'Rol varsayılanının sürümü geçersiz.');
        $value=$data['grants']??null;
        if(!is_array($value)&&!($value instanceof \stdClass))Http::fail(400,'Rol yetkilerini kontrol edin.');
        $value=CustomerTableVisibility::preserveOmitted((array)$value,$this->roles()[$role]['grants']);
        foreach(CustomerPermissionCatalog::pages() as $page=>$definition){
            if(empty($definition['adminOnly']))continue;
            $value[$page]??=array_fill_keys($definition['actions'],false);
        }
        $grants=CustomerPermissionCatalog::validateGrants($value,$role);
        foreach(CustomerPermissionCatalog::pages() as $page=>$definition)if(!empty($definition['adminOnly'])&&in_array(true,$grants[$page],true)){
            Http::fail(400,'Omnigo yönetim yetkileri mağaza rollerine verilemez.');
        }
        if($role===CustomerAccountRoles::LOGISTICS&&$grants['support']['view'])Http::fail(400,'Destek talepleri bu mağaza rolünde kullanılamaz.');
        $payload=json_encode($grants,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        try{
            TransactionRetry::run($this->db,function()use($actor,$role,$version,$payload):void{
                $row=$this->db->one('SELECT payload,version FROM store_role_defaults WHERE store_role=?'.$this->db->lock(),[$role]);
                if(!$row)Http::fail(503,'Rol varsayılanları henüz hazır değil.');
                if((int)$row['version']!==$version)Http::fail(409,'Rol varsayılanı değişti. Güncel yetkileri açıp tekrar kaydedin.');
                if($row['payload']===$payload)return;
                $this->db->run('UPDATE store_role_defaults SET payload=?,version=version+1,updated_by=?,updated_at=? WHERE store_role=?',
                    [$payload,(int)$actor['id'],gmdate('c'),$role]);
            });
        }catch(\PDOException $error){
            if(!$this->missingTable($error))throw $error;
            Http::fail(503,'Rol varsayılanları henüz hazır değil.');
        }
        return $this->read();
    }

    private function missingTable(\PDOException $error): bool
    {
        $driverCode=(int)($error->errorInfo[1]??0);
        return $this->db->mysql()?$driverCode===1146:$driverCode===1&&str_contains($error->getMessage(),'no such table: store_role_defaults');
    }
}
