<?php
declare(strict_types=1);
namespace Omnigo;

/** Cosmetic names only. Stable menu IDs, grants and job selections never change. */
final class CustomerMenuLabelScopes
{
    private static function key(int $company=0): string{return hash('sha256','omnigo-menu-labels:'.($company?:'global'));}
    private static ?\WeakMap $installed=null;
    private static function available(Database $db): bool
    {
        self::$installed??=new \WeakMap();if(self::$installed[$db]??false)return true;
        $ready=IntegrationImportSchema::tableAvailable($db,'user_preferences','user_id');if($ready)self::$installed[$db]=true;
        return $ready;
    }
    private static function preference(Database $db,int $user,int $company): array
    {
        if(!self::available($db))return ['version'=>0,'labels'=>[],'epochs'=>[]];
        $row=$db->one('SELECT payload,version FROM user_preferences WHERE user_id=? AND profile_key=?',[$user,self::key($company)]);
        $data=(array)json_decode($row['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        return [...$data,'labels'=>(array)($data['labels']??[]),'epochs'=>(array)($data['epochs']??[]),'version'=>(int)($row['version']??0)];
    }
    private static function store(Database $db,int $company,string|false|null $snapshot=false): array
    {
        if($snapshot===false)$snapshot=$db->one("SELECT JSON_EXTRACT(payload,'$.menuLabels') AS labels FROM company_profiles WHERE company_id=?",[$company])['labels']??null;
        $data=(array)json_decode($snapshot??'{}',true,32,JSON_THROW_ON_ERROR);
        return [...$data,'labels'=>(array)($data['labels']??[]),'epochs'=>(array)($data['epochs']??[]),'version'=>(int)($data['version']??0)];
    }
    public static function effective(Database $db,int $company,?array $user=null,string|false|null $storeSnapshot=false): array
    {
        $global=self::preference($db,0,0);$store=self::store($db,$company,$storeSnapshot);
        $sources=[$global,$store];
        if($user&&(($user['role']??'')==='admin'||(int)($user['company_id']??$user['companyId']??0)===$company))$sources[]=self::preference($db,(int)$user['id'],$company);
        return self::resolve($global,$store,$sources);
    }
    private static function resolve(array $global,array $store,array $sources): array
    {
        $labels=[];$epochs=[];$allowed=CustomerMenuLabels::allowed();
        foreach($sources as $source)foreach($source['labels'] as $key=>$label){
            if(!isset($allowed[$key])||!is_string($label)||$label==='')continue;
            $epoch=(int)($source['epochs'][$key]??0);
            if($epoch>=($epochs[$key]??-1)){$labels[$key]=$label;$epochs[$key]=$epoch;}
        }
        foreach($global['resets']??[] as $key=>$epoch)if((int)$epoch>($epochs[$key]??-1))unset($labels[$key]);
        return ['version'=>max((int)($global['revision']??0),$store['version']),'labels'=>(object)$labels];
    }
    public static function previews(Database $db,int $company,array $users): array
    {
        $global=self::preference($db,0,0);$store=self::store($db,$company);$saved=[];$result=[];
        if(self::available($db))foreach($db->all('SELECT user_id,payload FROM user_preferences WHERE profile_key=? AND user_id IN (SELECT id FROM users WHERE company_id=?)',[self::key($company),$company]) as $row)$saved[(int)$row['user_id']]=(array)json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
        foreach($users as $user)$result[(int)$user['id']]=self::resolve($global,$store,[$global,$store,$saved[(int)$user['id']]??['labels'=>[]]]);
        return ['store'=>self::resolve($global,$store,[$global,$store]),'users'=>(object)$result];
    }
    private static function activeFilter(Database $db): string
    {
        $sql='';
        if(IntegrationImportSchema::tableAvailable($db,'account_profiles','user_id'))$sql.=' AND NOT EXISTS (SELECT 1 FROM account_profiles p WHERE p.user_id=users.id AND p.deleted_at IS NOT NULL)';
        if(IntegrationImportSchema::tableAvailable($db,'account_controls','user_id'))$sql.=' AND NOT EXISTS (SELECT 1 FROM account_controls c WHERE c.user_id=users.id AND c.active=0)';
        return $sql;
    }
    private static function target(Database $db,array $actor,int $company,array $data,bool $lock=false): array
    {
        Security::admin($actor);$scope=$data['scope']??'store';
        if(!in_array($scope,['user','store','global'],true))Http::fail(400,'Adlandırma kapsamını seçin.');
        $id=0;
        if($scope==='user'){
            $id=filter_var($data['userId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id)Http::fail(400,'Kullanıcı seçin.');
            $target=$db->one('SELECT id,role,company_id FROM users WHERE id=?'.self::activeFilter($db).($lock?$db->lock():''),[$id]);
            if(!$target||!(($target['role']==='client'&&(int)$target['company_id']===$company)||($target['role']==='admin'&&$id===(int)$actor['id'])))Http::fail(403,'Bu kullanıcı seçili mağazada değil.');
        }
        return [$scope,(int)$id];
    }
    public static function read(Database $db,array $actor,int $company,array $data=[]): array
    {
        [$scope,$id]=self::target($db,$actor,$company,$data);
        $current=$scope==='store'?self::store($db,$company):self::preference($db,$id,$scope==='global'?0:$company);
        $users=$db->all("SELECT id,name FROM users WHERE ((role='client' AND company_id=?) OR id=?)".self::activeFilter($db)." ORDER BY name,id",[$company,(int)$actor['id']]);
        return ['scope'=>$scope,'userId'=>$id,'version'=>$current['version'],'labels'=>(object)$current['labels'],'users'=>$users,'targetDefaults'=>(object)($scope==='user'&&$id!==(int)$actor['id']?array_map(static fn(array $page):string=>$page['label'],array_intersect_key(CustomerPermissionCatalog::managementPages(),CustomerMenuLabels::allowed())):[]),'targetEffective'=>self::effective($db,$company,$scope==='user'?($id===(int)$actor['id']?$actor:['id'=>$id,'role'=>'client','company_id'=>$company]):null),'effective'=>self::effective($db,$company,$actor)];
    }
    public static function save(Database $db,array $actor,int $company,array $data): array
    {
        Security::admin($actor);
        if(!self::available($db))Http::fail(503,'Adlandırma ayarları henüz hazır değil.');
        $labels=$data['labels']??null;if($labels instanceof \stdClass)$labels=(array)$labels;
        if(!is_array($labels)||array_diff_key($labels,CustomerMenuLabels::allowed()))Http::fail(400,'Menü adlarını kontrol edin.');
        foreach($labels as $key=>$label){
            if(!is_string($label)||preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u',$label)!==0||mb_strlen(trim($label))>48)Http::fail(400,'Menü adı tek satır ve en fazla 48 karakter olmalı.');
            $label=preg_replace('/^\s+|\s+$/u','',$label);if($label==='')unset($labels[$key]);else $labels[$key]=$label;
        }
        ksort($labels);
        return TransactionRetry::run($db,function()use($db,$actor,$company,$data,$labels){
            $insert=$db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            $db->run($insert." INTO user_preferences(user_id,profile_key,payload,version,updated) VALUES(0,?,'{}',0,0)",[self::key()]);
            $db->one('SELECT version FROM user_preferences WHERE user_id=0 AND profile_key=?'.$db->lock(),[self::key()]);
            if(!$db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]))Http::fail(404,'Mağaza bulunamadı.');
            [$scope,$id]=self::target($db,$actor,$company,$data,true);
            $global=self::preference($db,0,0);
            $current=$scope==='store'?self::store($db,$company):($scope==='global'?$global:self::preference($db,$id,$company));
            if(($data['version']??null)!==$current['version'])Http::fail(409,'Menü adları değişti. Güncel ayarları yeniden açın.');
            $before=$current['labels'];ksort($before);
            if($before===$labels)return self::read($db,$actor,$company,$data);
            $epoch=max((int)($global['revision']??0)+1,(int)(new \DateTimeImmutable())->format('Uu'));
            $next=[...$current,'labels'=>$labels,'version'=>$current['version']+1];
            foreach(array_unique([...array_keys($before),...array_keys($labels)]) as $key)if(($before[$key]??null)!==($labels[$key]??null)){
                $next['epochs'][$key]=$epoch;
                if($scope==='global'){if(isset($labels[$key]))unset($next['resets'][$key]);else $next['resets'][$key]=$epoch;}
            }
            if($scope==='store'){
                $json=json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if($db->one('SELECT company_id FROM company_profiles WHERE company_id=?',[$company]))$db->run("UPDATE company_profiles SET payload=JSON_SET(payload,'$.menuLabels',JSON_EXTRACT(?,'$')) WHERE company_id=?",[$json,$company]);
                else $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['menuLabels'=>$next],JSON_THROW_ON_ERROR)]);
            }elseif($scope==='user')self::write($db,$id,$company,$next);
            if($scope==='global')$global=$next;else $global['version']++;
            $global['revision']=$epoch;self::write($db,0,0,$global);
            (new CustomerManagement($db))->audit($actor,$company,'Menü adları güncellendi: '.['user'=>'Kullanıcı','store'=>'Mağaza','global'=>'Tüm altyapı'][$scope],$id?:$company);
            return self::read($db,$actor,$company,$data);
        });
    }
    private static function write(Database $db,int $user,int $company,array $value): void
    {
        $key=self::key($company);$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($db->one('SELECT version FROM user_preferences WHERE user_id=? AND profile_key=?',[$user,$key]))$db->run('UPDATE user_preferences SET payload=?,version=?,updated=? WHERE user_id=? AND profile_key=?',[$json,$value['version'],time(),$user,$key]);
        else $db->run('INSERT INTO user_preferences(user_id,profile_key,payload,version,updated) VALUES(?,?,?,?,?)',[$user,$key,$json,$value['version'],time()]);
    }
}
