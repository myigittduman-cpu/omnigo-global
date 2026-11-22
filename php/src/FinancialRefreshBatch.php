<?php
declare(strict_types=1);
namespace Omnigo;

/** Drain durable financial pages within one worker budget, publishing only complete snapshots. */
final class FinancialRefreshBatch implements ScheduledReadAdapter
{
    private const MAX_PAGES=8;
    private const MIN_REMAINING_SECONDS=8;
    public function __construct(private Database $db,private string $provider,private ScheduledReadAdapter $reader,private ?\Closure $pause=null,private ?\Closure $clock=null)
    {
        if(!in_array($provider,['n11','pazarama','idefix'],true))throw new \InvalidArgumentException('Pazaryeri geçersiz.');
    }
    public function nextDelay():int{return 60;}
    public function dueAt(array $state):int
    {
        $next=(int)($state['recentOrders']['historyNextAt']??0);
        // Retire the old healthy 15-minute timer, never a provider error/backoff timer.
        if(!empty($state['failure'])||($state['phase']??'')==='error'||!empty($state['recentOrders']['historyBackoff']))return $next;
        $last=strtotime($state['lastSuccessAt']??'')?:0;
        return $last?min($next,$last+$this->nextDelay()):$next;
    }
    public function syncReadOnly(int $company,int $deadline):void
    {
        for($page=0;$page<self::MAX_PAGES;$page++){
            if($deadline-$this->now()<self::MIN_REMAINING_SECONDS)return;
            if($page>0){
                if($this->pause)($this->pause)(1);else sleep(1);
                if($deadline-$this->now()<self::MIN_REMAINING_SECONDS)return;
            }
            // A failed/limited request exits immediately; the queue keeps Retry-After.
            $this->reader->syncReadOnly($company,$deadline);
            $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$this->provider]);
            $state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
            if(!isset($state['scan']))return;
        }
    }
    private function now():int{return $this->clock?($this->clock)():time();}
}
