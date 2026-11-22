<?php
declare(strict_types=1);
namespace Omnigo;

/** A manual scan has its own checkpoints, retries and lease. */
final class NewRecordRun
{
    public function __construct(private Database $db) {}

    public static function available(Database $db): bool
    {
        return IntegrationImportSchema::tableAvailable($db,'new_record_runs','company_id');
    }

    public static function install(Database $db): void
    {
        $text=$db->mysql()?'LONGTEXT':'TEXT';
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS new_record_runs (
            company_id INT PRIMARY KEY, actor_id INT NOT NULL, state VARCHAR(12) NOT NULL,
            requested_at INT NOT NULL, finished_at INT, next_at INT NOT NULL,
            lease_until INT NOT NULL DEFAULT 0, lease_token CHAR(32), sources $text NOT NULL,
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$tail);
        $db->run("CREATE TABLE IF NOT EXISTS table_sync_requests (
            company_id INT NOT NULL, request_id CHAR(36) NOT NULL, run_id CHAR(36) NOT NULL,
            table_key VARCHAR(32) NOT NULL, mode VARCHAR(12) NOT NULL, PRIMARY KEY(company_id,request_id),
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$tail);
        $db->run("CREATE TABLE IF NOT EXISTS table_sync_checkpoints (
            company_id INT NOT NULL, table_key VARCHAR(32) NOT NULL, source_key VARCHAR(64) NOT NULL,
            through_at INT NOT NULL, PRIMARY KEY(company_id,table_key,source_key),
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$tail);
    }

    private function row(int $company): ?array
    {
        return $this->db->one('SELECT * FROM new_record_runs WHERE company_id=?',[$company]);
    }

    public function status(int $company): array
    {
        $row=$this->row($company);
        if(!$row)return ['run'=>null];
        $sources=[];
        $steps=self::sources($row);
        $first=reset($steps);
        foreach($steps as $key=>$source){
            $sources[]=['key'=>$key, 'state'=>$source['state'], 'added'=>$source['added'], 'error'=>$source['error']];
        }
        return ['run'=>[
            'state'=>$row['state'],
            'table'=>$first['table']??null,'mode'=>$first['mode']??'incremental',
            'requestedAt'=>gmdate(DATE_ATOM,(int)$row['requested_at']),
            'finishedAt'=>$row['finished_at']===null?null:gmdate(DATE_ATOM,(int)$row['finished_at']),
            'sources'=>$sources,
        ]];
    }

    public function remembered(int $company,string $requestId): bool
    {
        return IntegrationImportSchema::tableAvailable($this->db,'table_sync_requests','request_id')
            &&(bool)$this->db->one('SELECT request_id FROM table_sync_requests WHERE company_id=? AND request_id=?',[$company,$requestId]);
    }

    public function start(int $company,int $actor,array $keys,array $request=[]): array
    {
        return TransactionRetry::run($this->db,function()use($company,$actor,$keys,$request){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company])){
                Http::fail(404,'Mağaza bulunamadı.');
            }
            $row=$this->row($company);
            $steps=$row?self::sources($row):[];
            $first=reset($steps);
            $active=$row&&in_array($row['state'],['queued','running'],true);
            if($request){
                $request=TableSyncTargets::request($request);
                if(!CustomerSetupSelection::hasLive($this->db,$company,$request['table']))Http::fail(403,'Bu tablo mağazada etkin değil.');
                $keys=TableSyncTargets::keys($request['table'],$keys);
                if(!IntegrationImportSchema::tableAvailable($this->db,'table_sync_requests','request_id'))Http::fail(503,'Tablo aktarım kuyruğu henüz hazır değil.');
                $receipt=$this->db->one('SELECT * FROM table_sync_requests WHERE company_id=? AND request_id=?',[$company,$request['requestId']]);
                if($receipt){
                    if($receipt['table_key']!==$request['table']||$receipt['mode']!==$request['mode'])Http::fail(409,'Bu işlem kimliği başka bir seçim için kullanılmış.');
                    if($receipt['run_id']!==($first['runId']??null))Http::fail(409,'Bu aktarım isteği daha önce işlendi. Güncel durumu yenileyin.');
                    return $this->status($company);
                }
                if($active&&(($first['table']??null)!==$request['table']||($first['mode']??null)!==$request['mode']))
                    Http::fail(409,'Bu mağazada başka bir aktarım sürüyor. Tamamlandığında tekrar deneyin.');
                $runId=$active?$first['runId']:$request['requestId'];
                $this->db->run('INSERT INTO table_sync_requests(company_id,request_id,run_id,table_key,mode) VALUES(?,?,?,?,?)',
                    [$company,$request['requestId'],$runId,$request['table'],$request['mode']]);
            }elseif($active&&isset($first['table']))Http::fail(409,'Bu mağazada bir tablo aktarımı sürüyor.');
            if($active)return $this->status($company);
            if(!$keys)Http::fail(409,'Bu mağazada yeni kayıt alınabilecek etkin bağlantı yok.');
            $now=time();
            $sources=array_fill_keys($keys,[
                'state'=>'queued', 'added'=>0, 'error'=>null, 'attempts'=>0,
                'nextAt'=>$now, 'cursor'=>['through'=>$now],
            ]);
            foreach($sources as $key=>&$source){
                if($request){
                    $source+=['table'=>$request['table'],'mode'=>$request['mode'],'runId'=>$runId,'requestedAt'=>$now];
                    if($request['mode']==='incremental'){
                        $last=$this->db->one('SELECT through_at FROM table_sync_checkpoints WHERE company_id=? AND table_key=? AND source_key=?',[$company,$request['table'],$key]);
                        $source['after']=(int)($last['through_at']??0);
                    }
                }
                if(!str_starts_with($key,'orders:')||($request['mode']??null)==='full')continue;
                $baseline=NewOrderRecords::baseline($this->db,$company,substr($key,7),$now);
                if($baseline!==null)$source['cursor']['ordBaseline']=$baseline;
            }
            unset($source);
            $json=self::encode($sources);
            if($row){
                $this->db->run("UPDATE new_record_runs SET actor_id=?,state='queued',requested_at=?,finished_at=NULL,
                    next_at=?,lease_until=0,lease_token=NULL,sources=? WHERE company_id=?",[$actor,$now,$now,$json,$company]);
            }else{
                $this->db->run("INSERT INTO new_record_runs(company_id,actor_id,state,requested_at,next_at,sources)
                    VALUES(?,?,'queued',?,?,?)",[$company,$actor,$now,$now,$json]);
            }
            return $this->status($company);
        });
    }

    public function claim(int $now): ?array
    {
        return TransactionRetry::run($this->db,function()use($now){
            $row=$this->db->one("SELECT * FROM new_record_runs WHERE state IN ('queued','running')
                AND next_at<=? AND lease_until<=? ORDER BY next_at,requested_at,company_id LIMIT 1".$this->db->lock(),[$now,$now]);
            if(!$row)return null;
            $sources=self::sources($row);
            $due=array_filter($sources,static fn(array $source):bool=>
                in_array($source['state'],['queued','running'],true)&&$source['nextAt']<=$now);
            uasort($due,static fn(array $a,array $b):int=>$a['nextAt']<=>$b['nextAt']);
            $key=array_key_first($due);
            if($key===null)throw new \LogicException('Aktarım sırası geçersiz.');
            $sources[$key]['state']='running';
            $token=bin2hex(random_bytes(16));
            $this->db->run("UPDATE new_record_runs SET state='running',lease_until=?,lease_token=?,sources=? WHERE company_id=?",
                [$now+90,$token,self::encode($sources),$row['company_id']]);
            return ['company'=>(int)$row['company_id'],'actor'=>(int)$row['actor_id'],'token'=>$token,'key'=>$key,'source'=>$sources[$key]];
        });
    }

    public function finish(array $lease, array $source, bool $requireLease=false): void
    {
        $write=function()use($lease,$source,$requireLease){
            $company=$lease['company'];
            $row=$this->db->one('SELECT * FROM new_record_runs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$row||$row['lease_token']!==$lease['token']){
                if($requireLease)throw new \RuntimeException('Yeni kayıt aktarımının çalışma sırası değişti.');
                return;
            }
            $sources=self::sources($row);
            $sources[$lease['key']]=$source;
            if(isset($source['table'])&&$source['state']==='complete'){
                $upsert=$this->db->mysql()?'ON DUPLICATE KEY UPDATE through_at=GREATEST(through_at,VALUES(through_at))':
                    'ON CONFLICT(company_id,table_key,source_key) DO UPDATE SET through_at=MAX(through_at,excluded.through_at)';
                $this->db->run("INSERT INTO table_sync_checkpoints(company_id,table_key,source_key,through_at) VALUES(?,?,?,?) $upsert",[$company,$source['table'],$lease['key'],$source['requestedAt']]);
            }
            $pending=array_filter($sources,static fn(array $item):bool=>in_array($item['state'],['queued','running'],true));
            $next=$pending?min(array_column($pending,'nextAt')):0;
            $state=$pending?'running':(in_array('failed',array_column($sources,'state'),true)?'failed':'complete');
            $this->db->run('UPDATE new_record_runs SET state=?,finished_at=?,next_at=?,lease_until=0,lease_token=NULL,sources=?
                WHERE company_id=?',[$state,$pending?null:time(),$next,self::encode($sources),$company]);
        };
        if($this->db->pdo->inTransaction())$write();
        else TransactionRetry::run($this->db,$write);
    }

    private static function sources(array $row): array
    {
        return json_decode($row['sources'],true,64,JSON_THROW_ON_ERROR);
    }

    private static function encode(array $sources): string
    {
        return json_encode($sources,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
}
