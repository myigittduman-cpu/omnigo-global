<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer-owned store lifecycle. Historical orders and financial records are never deleted. */
final class CustomerMarketplaces
{
    public const PROVIDERS=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pttavm'=>'PTT AVM','pazarama'=>'Pazarama','koctas'=>'Koçtaş','trendyol'=>'Trendyol'];
    public function __construct(private Database $db){}
    public static function selection(mixed $names): array
    {
        if(!is_array($names)||!array_is_list($names)||count($names)>count(self::PROVIDERS))Http::fail(400,'Çalışılan pazaryerlerini listeden seçin.');
        foreach($names as $name)if(!is_string($name)||!in_array($name,self::PROVIDERS,true))Http::fail(400,'Pazaryeri seçimi geçersiz.');
        return array_values(array_unique($names));
    }
    public static function shown(array $row): bool
    {
        return empty($row['hidden'])&&self::available($row);
    }
    public static function available(array $row): bool
    {
        return $row['name']!=='Trendyol'||!empty($row['selected'])||!empty($row['historical']);
    }
    public static function historyChannels(Database $db,int $company,array $channels,array $sales): array
    {
        $known=array_column($channels,'name');$historic=[];
        $historyNames=array_column([...$sales,...(new Repository($db))->records($company,'payments',['role'=>'admin'])],'channel');
        foreach(self::PROVIDERS as $provider=>$name){
            if(in_array($name,$known,true)||!MarketplaceVisibility::visible($name))continue;
            if(in_array($name,$historyNames,true)||$db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]))
                $historic[]=['name'=>$name,'status'=>'inactive','storeName'=>'','connection'=>'not_connected','hidden'=>true,'historical'=>true];
        }
        return $historic;
    }
    public static function reportingChannels(Database $db,int $company,array $channels): array
    {
        $sales=(new Repository($db))->records($company,'sales',['role'=>'admin']);
        $history=self::historyChannels($db,$company,$channels,$sales);
        return array_values(array_filter([...$channels,...$history],[self::class,'available']));
    }

    /** A manual payout can be reported before its marketplace is connected. */
    public static function payoutChannels(array $channels,array $payments): array
    {
        $known=array_fill_keys(array_column($channels,'name'),true);
        foreach($payments as $payment){
            $name=$payment['channel'];
            if(isset($known[$name])||!in_array($name,Repository::CHANNELS,true)||!MarketplaceVisibility::visible($name))continue;
            $known[$name]=true;
            $channels[]=['name'=>$name,'status'=>'inactive','historical'=>true];
        }
        return $channels;
    }
    public function listing(int $company): array
    {
        $stores=[];foreach((new Repository($this->db))->records($company,'channels',['role'=>'admin']) as $row)$stores[$row['name']]=$row;
        $items=[];
        foreach(self::PROVIDERS as $provider=>$name){
            $row=$stores[$name]??null;$selected=$row&&($name!=='Trendyol'||!empty($row['selected']));
            $items[]=['provider'=>$provider,'name'=>$name,'selected'=>(bool)$selected,'id'=>$row['id']??null,'version'=>$row['version']??0,'storeName'=>$row['storeName']??'',
                'hidden'=>!$selected||!self::shown($row),'configured'=>(bool)$this->db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider])];
        }
        return $items;
    }
    public function change(array $actor,int $company,string $provider,array $data): void
    {
        Security::admin($actor);$name=self::PROVIDERS[$provider]??null;
        if(!$name)Http::fail(400,'Pazaryeri geçersiz.');
        $action=$data['action']??'';
        if(!in_array($action,['add','edit','show','hide','disconnect','delete'],true))Http::fail(400,'Mağaza işlemi geçersiz.');
        $write=function()use($actor,$company,$provider,$name,$data,$action){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))Http::fail(404,'Müşteri bulunamadı.');
            $row=null;
            foreach($this->db->all("SELECT * FROM records WHERE company_id=? AND kind='channels'",[$company]) as $candidate)if(json_decode($candidate['payload'],true)['name']===$name){$row=$candidate;break;}
            if(($data['version']??null)!==(int)($row['version']??0))Http::fail(409,'Mağaza bilgileri değişti. Listeyi yenileyin.');
            if(!$row&&$action!=='add')Http::fail(404,'Mağaza tanımlı değil.');
            if($action==='delete'&&($data['unconfiguredOnly']??false)===true
                &&$this->db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider])){
                Http::fail(409,'API bağlantısı kaydedilmiş. Bu pazaryerini Pazaryeri ayarlarından kaldırabilirsiniz.');
            }
            $value=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):['name'=>$name,'status'=>'active','storeName'=>'','connection'=>'not_connected'];
            if(in_array($action,['disconnect','delete'],true))$this->disconnect($company,$provider,$name);
            if($action==='delete')$this->db->run("DELETE FROM records WHERE company_id=? AND id=? AND kind='channels'",[$company,$row['id']]);
            else{
                if(in_array($action,['add','edit'],true))$value['storeName']=Validation::text($data['storeName']??'','Mağaza adı',150,$action==='add');
                if($action==='add'){$value['selected']=true;$value['hidden']=false;$value['status']='active';}
                if(in_array($action,['show','hide'],true))$value['hidden']=$action==='hide';
                if($action==='disconnect')$value['connection']='not_connected';
                $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if($row)$this->db->run('UPDATE records SET payload=?,version=version+1,updated=? WHERE id=?',[$payload,gmdate('c'),$row['id']]);
                else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'channels',$payload,gmdate('c')]);
            }
            $labels=['add'=>'eklendi','edit'=>'düzenlendi','show'=>'gösterildi','hide'=>'gizlendi','disconnect'=>'bağlantısı kaldırıldı','delete'=>'mağaza tanımı silindi; geçmiş kayıtlar korundu'];
            (new CustomerManagement($this->db))->audit($actor,$company,$name.' '.$labels[$action],(int)($row['id']??$this->db->insertId()));
        };
        if($this->db->pdo->inTransaction())$write();else TransactionRetry::run($this->db,$write);
    }
    private function disconnect(int $company,string $provider,string $name): void
    {
        $jobs=[$name,$name.' Sorular',$name.' Satış',$name.' Hakediş'];
        if($provider==='pazarama')$jobs[]=PazaramaCommissionAdapter::JOB;
        if(isset(UpcomingPayoutAdapter::JOBS[$provider]))$jobs[]=UpcomingPayoutAdapter::JOBS[$provider];
        if($provider==='hepsiburada')$jobs[]=HepsiburadaQuestionClient::JOB;
        if($provider==='trendyol')$jobs[]=TrendyolClaimAdapter::JOB;
        $marks=implode(',',array_fill(0,count($jobs),'?'));
        $running=$this->db->one("SELECT id FROM jobs WHERE company_id=? AND provider IN ($marks) AND state='running' AND lease_until>?".$this->db->lock(),[$company,...$jobs,time()]);
        if($running)Http::fail(409,'Aktarım sürüyor. Kayıtların korunması için kısa süre sonra yeniden deneyin.');
        $this->db->run('DELETE FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        $this->db->run("DELETE FROM jobs WHERE company_id=? AND provider IN ($marks)",[$company,...$jobs]);
    }
}
