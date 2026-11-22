<?php
declare(strict_types=1);
namespace Omnigo;

/** Local background audit; never contacts a marketplace or edits an order. */
final class ReconciliationMonitor
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(int $deadline,int $afterId=0): array
    {
        $kinds=array_map(static fn($provider)=>'sync_'.$provider,array_keys(ReconciliationReference::PROVIDERS));
        $slots=implode(',',array_fill(0,count($kinds),'?'));
        $rows=$this->db->all("SELECT id,company_id,kind FROM records WHERE kind IN ($slots) AND id>? ORDER BY id LIMIT 20",[...$kinds,$afterId]);
        $cursor=$afterId;$checked=0;$errors=0;$visited=0;
        foreach($rows as $row){
            if(time()>=$deadline)break;$cursor=(int)$row['id'];$visited++;
            try{if($this->check((int)$row['company_id'],substr($row['kind'],5))!==null)$checked++;}
            catch(\Throwable $error){$errors++;error_log('Omnigo sipariş mutabakatı: '.get_class($error));}
        }
        return ['cursor'=>count($rows)<20&&$visited===count($rows)?0:$cursor,'checked'=>$checked,'errors'=>$errors,'lastRun'=>gmdate('c')];
    }
    public function check(int $company,string $provider,bool $persist=true,?int $now=null): ?array
    {
        if(!isset(ReconciliationReference::PROVIDERS[$provider]))throw new \InvalidArgumentException('Pazaryeri geçersiz.');
        $now??=time();
        return $this->db->transaction(function()use($company,$provider,$persist,$now){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one('SELECT id,payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);if(!$row)return null;
            $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$ref=$state['reconciliationReference']??null;
            if($provider==='trendyol'&&!TrendyolReconciliation::current($state,$now))return null;
            if($provider==='hepsiburada'){
                $financeJob=$this->db->one("SELECT state,attempts FROM jobs WHERE company_id=? AND provider='Hepsiburada Satış'".$this->db->lock(),[$company]);
                if(!HepsiburadaReconciliationSource::current($state)||!$financeJob||$financeJob['state']!=='pending'||(int)$financeJob['attempts']>0)return null;
            }
            $job=$this->db->one('SELECT state,attempts FROM jobs WHERE company_id=? AND provider=?'.$this->db->lock(),[$company,ReconciliationReference::PROVIDERS[$provider]]);
            // A partial, stale, failed or currently running read cannot prove either a discrepancy or recovery.
            if(!$ref||isset($state['scan'])||isset($state['scanEnd'])||!empty($state['failure'])||!empty($state['recentOrders']['historyBackoff'])||($state['phase']??'')==='error'||!$job||$job['state']!=='pending'||(int)$job['attempts']>0||$now-strtotime($ref['capturedAt'])>300)return null;
            $channelActive=false;foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='channels'",[$company]) as $channel){$channel=json_decode($channel['payload'],true,16,JSON_THROW_ON_ERROR);if(($channel['name']??'')===ReconciliationReference::PROVIDERS[$provider]&&($channel['status']??'')==='active')$channelActive=true;}
            if(!$channelActive||!MarketplaceVisibility::visible(ReconciliationReference::PROVIDERS[$provider]))return null;
            $previous=$state['reconciliationCheck']??[];
            if($persist&&$now-strtotime($previous['checkedAt']??'1970-01-01')<45)return null;
            $result=(new ReconciliationCompare($this->db,$this->cipher))->inspect($company,$provider,$ref);$result['checkedAt']=gmdate('c',$now);
            if(!$persist)return $result;
            $changed=$result['fingerprint']!==($previous['fingerprint']??null);
            $recovered=$result['total']===0&&($previous['total']??0)>0;
            if(($result['total']>0&&$changed)||$recovered){
                (new OperationalMailEvents($this->db,$this->cipher))->record($company,(int)$row['id'],[
                    'events'=>[$recovered?'reconciliation_recovered':'reconciliation_issue'],'provider'=>ReconciliationReference::PROVIDERS[$provider],
                    'reconciliation'=>$result,'audience'=>'admin']);
            }
            $state['reconciliationCheck']=$result;
            $this->db->run('UPDATE records SET payload=?,version=version+1,updated=? WHERE id=?',[json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c',$now),$row['id']]);
            return $result;
        });
    }
}
