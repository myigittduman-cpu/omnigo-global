<?php
declare(strict_types=1);
namespace Omnigo;

final class FinanceStore
{
    public function __construct(private Database $db) {}
    public function state(int $company,string $provider): ?array{return $this->db->one('SELECT payload,version FROM finance_sync WHERE company_id=? AND provider=?',[$company,$provider]);}
    public function writeState(int $company,string $provider,array $state,?array $before): void
    {
        $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($before)$this->db->run('UPDATE finance_sync SET payload=?,version=version+1 WHERE company_id=? AND provider=?',[$json,$company,$provider]);
        else $this->db->run('INSERT INTO finance_sync(company_id,provider,payload) VALUES(?,?,?)',[$company,$provider,$json]);
    }
    public function publish(int $company,string $provider,array $entries,string $from,string $to): void
    {
        // Called inside the company transaction only after every page/window has succeeded.
        $keep=[];$stamp=gmdate('c');
        foreach($entries as $entry){
            $key=$entry['key'];if(isset($keep[$key]))throw new SyncFailure(422);$keep[$key]=true;
            $old=$this->db->one('SELECT external_key FROM finance_entries WHERE company_id=? AND provider=? AND external_key=?',[$company,$provider,$key]);
            $values=[$entry['transactionDate'],$entry['expectedDate'],$entry['netCents'],$entry['state'],$stamp,$company,$provider,$key];
            if($old)$this->db->run('UPDATE finance_entries SET transaction_date=?,expected_date=?,net_cents=?,state=?,updated=? WHERE company_id=? AND provider=? AND external_key=?',$values);
            else $this->db->run('INSERT INTO finance_entries(transaction_date,expected_date,net_cents,state,updated,company_id,provider,external_key) VALUES(?,?,?,?,?,?,?,?)',$values);
        }
        foreach($this->db->all('SELECT external_key FROM finance_entries WHERE company_id=? AND provider=? AND transaction_date>=? AND transaction_date<=?',[$company,$provider,$from,$to]) as $row)if(!isset($keep[$row['external_key']]))$this->db->run('DELETE FROM finance_entries WHERE company_id=? AND provider=? AND external_key=?',[$company,$provider,$row['external_key']]);
        $groups=$this->db->all('SELECT expected_date,state,SUM(net_cents) AS cents,COUNT(*) AS n FROM finance_entries WHERE company_id=? AND provider=? GROUP BY expected_date,state',[$company,$provider]);
        $this->publishGroups($company,$provider,$groups,$stamp);
    }
    /** A previously verified complete API snapshot can seed the same projections without fake ledger IDs. */
    public function publishGroups(int $company,string $provider,array $groups,string $stamp): void
    {
        if(!in_array($provider,['koctas','pazarama','hepsiburada','n11','idefix'],true))throw new SyncFailure(422);
        $existing=[];foreach($this->db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='payments'",[$company]) as $record){$value=json_decode($record['payload'],true);if(($value['integration']??null)===$provider)$existing[$value['sourceKey']??'legacy-'.$record['id']]=$record;}
        $channel=['pazarama'=>'Pazarama','koctas'=>'Koçtaş','hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix'][$provider];
        if($provider==='idefix'&&!$groups)$groups=[['expected_date'=>'','state'=>'earnings','cents'=>0,'n'=>0]];
        foreach($groups as $group){
            if((int)$group['cents']===0&&$provider!=='idefix')continue;$sourceKey=$provider.':'.$group['expected_date'].':'.$group['state'];$old=$existing[$sourceKey]??null;unset($existing[$sourceKey]);
            $value=['channel'=>$channel,'expectedDate'=>$group['expected_date'],'expectedCents'=>(int)$group['cents'],'status'=>$group['state']==='estimated'?'pending':$group['state'],'source'=>$channel.' API · '.(int)$group['n'].' finans hareketi','actualCents'=>null,'paidDate'=>'','confirmation'=>'','verification'=>$group['state']==='estimated'?'estimated':'confirmed','verificationSource'=>$group['state']==='estimated'?'':$channel.' resmi finans servisi','integration'=>$provider,'sourceKey'=>$sourceKey,'lastSyncAt'=>$stamp];
            if($provider==='idefix')$value=[...$value,'status'=>'pending','paymentStateUnknown'=>true,'source'=>'idefix API · '.(int)$group['n'].' sipariş satırı hakedişi','verificationSource'=>'idefix resmi sipariş servisi · earningAmount'];
            $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($old)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$json,$stamp,$old['id']]);
            else $this->db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,$json,$stamp]);
        }
        foreach($existing as $old)$this->db->run("DELETE FROM records WHERE id=? AND company_id=? AND kind='payments'",[$old['id'],$company]);
    }
}
