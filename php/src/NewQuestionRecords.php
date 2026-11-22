<?php
declare(strict_types=1);
namespace Omnigo;

/** Manual reads reuse the question clients and insert only previously unseen conversations. */
final class NewQuestionRecords
{
    public const PROVIDERS=['hepsiburada','n11','idefix','pazarama','koctas','trendyol'];

    public function __construct(private Database $db,private IntegrationVault $vault,private ?\Closure $reader=null) {}

    public function step(int $company,string $provider,array $cursor,int $deadline,?callable $checkpoint=null): array
    {
        $settings=$this->vault->get($company,$provider);
        if(empty($settings['enabled']))throw new SyncFailure(403);
        $revision=self::revision($settings);
        if(($cursor['revision']??$revision)!==$revision)throw new SyncFailure(409);
        $cursor['revision']=$revision;
        $through=(int)$cursor['through'];
        $target=$cursor['target']??null;
        $full=($target['mode']??'')==='full';
        $start=$full?TableSyncBoundary::first($this->db,$company,$provider,$settings,[],$through,'inbox'):NewRecordBoundary::started($this->db,$company,$provider,$settings);
        if($target){
            $start=max($start,NewRecordBoundary::epoch(InboxQuestionWindow::START),(int)($target['after']??0));
            $cursor['questionFrom']=$start;
        }
        [$threads,$done]=$this->reader?($this->reader)($company,$provider,$settings,$cursor,$deadline)
            :$this->page($company,$provider,$settings,$cursor,$deadline);
        return TransactionRetry::run($this->db,function()use($company,$provider,$revision,$threads,$start,$through,$cursor,$done,$checkpoint,$target,$full){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $fresh=$this->vault->get($company,$provider);
            if(empty($fresh['enabled'])||self::revision($fresh)!==$revision)throw new SyncFailure(409);
            $repo=new InboxRepository($this->db);
            $added=0;
            foreach($threads as $thread){
                if(!$thread)continue;
                if($target&&(InboxStatus::value($thread)!=='waiting'||!empty($thread['archived'])||!empty($thread['partial'])
                    ||($provider==='hepsiburada'&&NewRecordBoundary::epoch($thread['expiresAt']??null)<=time())))continue;
                $seen=$this->db->one('SELECT payload FROM inbox_threads WHERE company_id=? AND provider=? AND remote_id=?',[$company,$provider,(string)$thread['id']]);
                if($seen){
                    if(!$full)continue;
                    $local=json_decode($seen['payload'],true,64,JSON_THROW_ON_ERROR);
                    if(InboxStatus::value($local)!=='waiting'||!empty($local['localReplyState'])||!empty($local['archived']))continue;
                }
                $created=$this->firstCustomerMessage($thread);
                if($created<$start||$created>$through)continue;
                if($repo->save($company,$thread,$provider)>0&&!$seen)$added++;
            }
            $result=compact('cursor','done','added')+['delay'=>65];
            if($checkpoint!==null)$checkpoint($result);
            return $result;
        });
    }

    private function firstCustomerMessage(array $thread): int
    {
        if(!empty($thread['partial']))return 0;
        $events=array_filter($thread['messages']??[],static fn(array $event):bool=>$event['sender']==='CUSTOMER_USER');
        return $events?min(array_map([NewRecordBoundary::class,'epoch'],array_column($events,'created'))):0;
    }

    private static function revision(array $settings): string
    {
        return hash('sha256',json_encode($settings,JSON_THROW_ON_ERROR));
    }

    private function known(int $company,string $provider,string $id): bool
    {
        return $this->db->one('SELECT id FROM inbox_threads WHERE company_id=? AND provider=? AND remote_id=?',
            [$company,$provider,$id])!==null;
    }

    private function page(int $company,string $provider,array $settings,array &$cursor,int $deadline): array
    {
        $page=(int)($cursor['page']??1);
        if($page>400)throw new SyncFailure(422);
        return match($provider){
            'n11'=>$this->n11($company,$settings,$cursor,$deadline),
            'idefix'=>$this->idefix($settings,$cursor,$page,$deadline),
            'hepsiburada'=>$this->hepsiburada($settings,$cursor,$page,$deadline),
            'pazarama'=>$this->pazarama($settings,$cursor,$page,$deadline),
            'koctas'=>$this->koctas($settings,$cursor,$deadline),
            'trendyol'=>$this->trendyol($settings,$cursor,$page,$deadline),
            default=>throw new SyncFailure(422),
        };
    }

    private function n11(int $company,array $settings,array &$cursor,int $deadline): array
    {
        $client=new N11QuestionClient();
        $full=($cursor['target']['mode']??'')==='full';
        if(!empty($cursor['pending'])){
            $id=array_shift($cursor['pending']);
            $threads=(!$full&&$this->known($company,'n11',$id))?[]:[$client->detail($settings,$id,$deadline)];
            return [$threads,empty($cursor['pending'])&&!empty($cursor['listed'])];
        }
        $page=(int)($cursor['page']??0);
        $result=$client->listing($settings,$page,$deadline);
        $cursor['page']=$page+1;
        $cursor['listed']=$result['complete'];
        $cursor['pending']=$full?array_values($result['ids']):array_values(array_filter($result['ids'],fn(string $id):bool=>!$this->known($company,'n11',$id)));
        return [[],empty($cursor['pending'])&&$cursor['listed']];
    }

    private function idefix(array $settings,array &$cursor,int $page,int $deadline): array
    {
        $stream=$cursor['stream']??'unanswered';
        $data=(new IdefixQuestionClient())->page($settings,$stream,$page,$deadline);
        $done=$data['complete']&&$stream==='answered';
        $cursor['page']=$data['complete']?1:$page+1;
        if($data['complete']&&!$done)$cursor['stream']=$stream==='unanswered'?'product':'answered';
        return [$data['threads'],$done];
    }

    private function hepsiburada(array $settings,array &$cursor,int $page,int $deadline): array
    {
        $answered=$cursor['answered']??false;
        $data=(new HepsiburadaQuestionClient())->page($settings,$page,$deadline,
            answered:$answered,through:gmdate('c',$cursor['through']));
        if(isset($cursor['target'])){$cursor['page']=$page+1;return [$data['threads'],$data['complete']];}
        $cursor['page']=$data['complete']?1:$page+1;
        if($data['complete'])$cursor['answered']=true;
        return [$data['threads'],$data['complete']&&$answered];
    }

    private function pazarama(array $settings,array &$cursor,int $page,int $deadline): array
    {
        $token=(new PazaramaAuthClient())->authenticate(PazaramaCredentials::fromArray($settings),$deadline);
        PazaramaSellerIdentity::verify($token['accessToken'],$settings['sellerId']);
        $data=(new PazaramaReadClient())->questions($token['accessToken'],$page,$deadline);
        if(strcasecmp((string)($data['sellerId']??''),$settings['sellerId'])!==0)throw new SyncFailure(403);
        $cursor['page']=$page+1;
        return [array_map([PazaramaQuestionMapper::class,'thread'],$data['approvalAnswersByMerchantSearchs']),
            $page>=max(1,$data['pageResponse']['totalPages'])];
    }

    private function koctas(array $settings,array &$cursor,int $deadline): array
    {
        if($deadline-time()<7)throw new SyncFailure(425);
        if(($cursor['updatedSince']??null)!==InboxQuestionWindow::START){
            unset($cursor['token'],$cursor['tokens']);
            $cursor['updatedSince']=InboxQuestionWindow::START;
        }
        $data=(new KoctasInboxClient($settings['apiKey'],(int)$settings['shopId']))->threads($cursor['token']??null,$deadline);
        $next=$data['next_page_token']??null;
        if(!is_array($data['data']??null)||($next!==null&&(!is_string($next)||strlen($next)>2000)))throw new SyncFailure(422);
        if($next!==null){
            $signature=hash('sha256',$next);
            if(count($cursor['tokens']??[])>=100||in_array($signature,$cursor['tokens']??[],true))throw new SyncFailure(422);
            $cursor['tokens'][]=$signature;
            $cursor['token']=$next;
        }
        return [array_map([InboxMapping::class,'thread'],$data['data']),$next===null];
    }

    private function trendyol(array $settings,array &$cursor,int $page,int $deadline): array
    {
        $stream=$cursor['stream']??TrendyolQuestionClient::WAITING;
        $end=$cursor['questionEnd']??($cursor['through']*1000);
        $start=max(0,$end-TrendyolQuestionClient::WINDOW);
        if(isset($cursor['target'])){
            $floor=$cursor['questionFrom']*1000;
            if($end<$floor)return [[],true];
            $start=max($start,$floor);
        }
        $data=(new TrendyolQuestionClient())->page($settings,$stream,$start,$end,$page-1,$deadline);
        $cursor['page']=$data['complete']?1:$page+1;
        if(isset($cursor['target'])){
            if($data['complete'])$cursor['questionEnd']=$start-1;
            return [$data['threads'],$data['complete']&&$start===$floor];
        }
        if($data['complete'])$cursor['stream']=TrendyolQuestionClient::ANSWERED;
        return [$data['threads'],$data['complete']&&$stream===TrendyolQuestionClient::ANSWERED];
    }
}
