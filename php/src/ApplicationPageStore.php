<?php
declare(strict_types=1);
namespace Omnigo;

/** Versioned installation-wide pages built only from approved presentation fields. */
final class ApplicationPageStore
{
    private const DRAFT='application_pages_draft';
    private const PUBLISHED='application_pages_published';
    private const PREVIOUS='application_pages_previous';
    private const GROUPS=['management','operations','finance','system'];
    private const ROLES=['admin','client','both'];
    private const LAYOUTS=['standard','wide','two-column'];
    public function __construct(private Database $db){$this->ensure();}
    private function ensure():void
    {
        if((int)($this->db->one('SELECT COUNT(*) AS total FROM application_preferences WHERE preference_key IN (?,?,?)',[self::DRAFT,self::PUBLISHED,self::PREVIOUS])['total']??0)===3)return;
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        foreach([self::DRAFT,self::PUBLISHED,self::PREVIOUS] as $key)$this->db->run("$insert INTO application_preferences(preference_key,preference_value,version,updated) VALUES(?,?,1,?)",[$key,'[]',time()]);
    }
    private function state(bool $lock=false):array
    {
        $rows=$this->db->all('SELECT preference_key,preference_value,version,updated FROM application_preferences WHERE preference_key IN (?,?,?) ORDER BY preference_key'.($lock?$this->db->lock():''),[self::DRAFT,self::PUBLISHED,self::PREVIOUS]);
        $state=[];
        foreach($rows as $row){
            $pages=json_decode($row['preference_value'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($pages)||!array_is_list($pages))throw new \RuntimeException('Sayfa yapılandırması okunamadı.');
            $state[$row['preference_key']]=['items'=>$pages,'version'=>(int)$row['version'],'updated'=>(int)$row['updated']];
        }
        if(count($state)!==3)throw new \RuntimeException('Sayfa yapılandırması okunamadı.');
        return ['draft'=>$state[self::DRAFT],'published'=>$state[self::PUBLISHED],'previous'=>$state[self::PREVIOUS],'canRollback'=>$state[self::PREVIOUS]['version']>1];
    }
    public function management():array{return $this->state();}
    public function visible(array $user,int $company):array
    {
        $role=(string)($user['role']??'');
        if(!in_array($role,['admin','client'],true)||$company<1)Http::fail(403,'Bu sayfalara erişemezsiniz.');
        if($role==='client'&&(int)($user['company_id']??0)!==$company)Http::fail(403,'Bu mağazaya erişemezsiniz.');
        $row=$this->db->one('SELECT preference_value,version FROM application_preferences WHERE preference_key=?',[self::PUBLISHED]);
        $items=json_decode($row['preference_value'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($items)||!array_is_list($items))throw new \RuntimeException('Sayfa yapılandırması okunamadı.');
        $published=['items'=>$items,'version'=>(int)$row['version']];
        return ['items'=>array_values(array_filter($published['items'],fn($page)=>in_array($page['role'],[$role,'both'],true)&&($page['scope']==='all'||in_array($company,$page['companyIds'],true)))),'version'=>$published['version']];
    }
    public function saveDraft(array $body):array
    {
        $version=$body['version']??null;if(!is_int($version)||$version<1)Http::fail(400,'Taslak sürümünü kontrol edin.');
        return $this->replace(self::DRAFT,$this->validate($body['items']??null),$version,'Taslak başka bir cihazda değişti. Sayfaları yeniden açın.');
    }
    public function publish(array $body):array
    {
        $version=$body['version']??null;if(!is_int($version)||$version<1)Http::fail(400,'Taslak sürümünü kontrol edin.');
        return $this->db->transaction(function()use($version){
            $state=$this->state(true);$draft=$state['draft'];if($draft['version']!==$version)Http::fail(409,'Taslak başka bir cihazda değişti.');
            $published=$state['published'];$previous=$state['previous'];
            $this->replace(self::PREVIOUS,$published['items'],$previous['version'],'Yayın geçmişi değişti.');
            $this->replace(self::PUBLISHED,$draft['items'],$published['version'],'Yayın başka bir cihazda değişti.');
            $this->replace(self::DRAFT,$draft['items'],$draft['version'],'Taslak başka bir cihazda değişti.');
            return $this->state();
        });
    }
    public function rollback(array $body):array
    {
        $version=$body['version']??null;if(!is_int($version)||$version<1)Http::fail(400,'Yayın sürümünü kontrol edin.');
        return $this->db->transaction(function()use($version){
            $state=$this->state(true);$published=$state['published'];if($published['version']!==$version)Http::fail(409,'Yayın başka bir cihazda değişti.');
            $previous=$state['previous'];if(!$state['canRollback'])Http::fail(409,'Geri alınabilecek önceki yayın bulunmuyor.');
            $draft=$state['draft'];$this->replace(self::DRAFT,$previous['items'],$draft['version'],'Taslak değişti.');
            $this->replace(self::PUBLISHED,$previous['items'],$published['version'],'Yayın değişti.');
            $this->replace(self::PREVIOUS,$published['items'],$previous['version'],'Yayın geçmişi değişti.');
            return $this->state();
        });
    }
    private function replace(string $key,array $items,int $version,string $message):array
    {
        $next=$version+1;$updated=time();$json=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $result=$this->db->run('UPDATE application_preferences SET preference_value=?,version=?,updated=? WHERE preference_key=? AND version=?',[$json,$next,$updated,$key,$version]);
        if($result->rowCount()!==1)Http::fail(409,$message);
        return ['items'=>$items,'version'=>$next,'updated'=>$updated];
    }
    private function validate(mixed $value):array
    {
        $items=json_decode(json_encode($value,JSON_THROW_ON_ERROR),true);
        if(!is_array($items)||!array_is_list($items)||count($items)>24)Http::fail(400,'En fazla 24 özel sayfa oluşturabilirsiniz.');
        $slugs=[];$result=[];
        foreach($items as $page){
            if(!is_array($page))Http::fail(400,'Sayfa kaydını kontrol edin.');
            if(!is_string($page['slug']??null))Http::fail(400,'Sayfa kısa adını kontrol edin.');
            $slug=mb_strtolower(trim($page['slug']));
            if(!preg_match('/^[a-z][a-z0-9-]{2,39}$/D',$slug)||isset($slugs[$slug]))Http::fail(400,'Sayfa kısa adı benzersiz olmalı ve yalnız küçük harf, rakam, tire içermelidir.');
            $slugs[$slug]=true;$role=$page['role']??'both';$scope=$page['scope']??'all';$layout=$page['layout']??'standard';$group=$page['menuGroup']??'management';
            if(!in_array($role,self::ROLES,true)||!in_array($layout,self::LAYOUTS,true)||!in_array($group,self::GROUPS,true)||!in_array($scope,['all','selected'],true))Http::fail(400,'Sayfa kapsamını kontrol edin.');
            $companyIds=$page['companyIds']??[];
            if(!is_array($companyIds)||!array_is_list($companyIds)||count($companyIds)>1000)Http::fail(400,'Seçili mağazaları kontrol edin.');
            foreach($companyIds as $id)if(!is_int($id)||$id<1)Http::fail(400,'Seçili mağazaları kontrol edin.');
            $companyIds=array_values(array_unique($companyIds));
            if($scope==='selected'&&!$companyIds)Http::fail(400,'Seçili mağaza kapsamı için en az bir mağaza seçin.');
            if($scope==='selected'&&count($this->db->all('SELECT id FROM companies WHERE id IN ('.implode(',',array_fill(0,count($companyIds),'?')).')',$companyIds))!==count($companyIds))Http::fail(400,'Seçilen mağazalardan biri bulunamadı.');
            $result[]=['slug'=>$slug,'title'=>Validation::text($page['title']??null,'Sayfa adı',80),'summary'=>Validation::text($page['summary']??'','Sayfa açıklaması',240,true),'role'=>$role,'scope'=>$scope,'companyIds'=>$scope==='all'?[]:$companyIds,'layout'=>$layout,'menuGroup'=>$group,'content'=>Validation::text($page['content']??null,'Sayfa içeriği',8000),'notice'=>Validation::text($page['notice']??'','Bilgi notu',500,true),'actionLabel'=>Validation::text($page['actionLabel']??'','Bağlantı metni',60,true),'actionUrl'=>ApplicationPageUrl::validate($page['actionUrl']??null)];
        }
        return $result;
    }
}
