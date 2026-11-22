<?php
declare(strict_types=1);
namespace Omnigo;

/** A single bell showing the most recent authorised events, with each store's own read boundary. */
final class AdminNotificationInbox
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null) {}

    private function companies(array $user): array
    {
        if(($user['role']??'')!=='admin')Http::fail(403,'Bu bildirim alanı Omnigo yöneticilerine aittir.');
        return array_map('intval',array_column($this->db->all('SELECT id FROM companies ORDER BY id'),'id'));
    }

    public function listing(array $user,string $filter,string $category): array
    {
        $companies=$this->companies($user);
        $feed=new NotificationFeed($this->db,$this->cipher);
        $items=[];$counts=['all'=>0,'unread'=>0,'read'=>0];
        $categories=array_fill_keys(array_keys(NotificationCategory::TYPES),$counts);
        $through=[];$totalUnread=0;$available=[];
        foreach($companies as $company){
            $page=$feed->listing((int)$user['id'],$company,0,$filter,$category,false);
            foreach($page['items'] as $item)$items[]=['companyId'=>$company,...$item];
            usort($items,static fn($a,$b)=>strcmp($b['created'],$a['created'])?:strcmp($a['kind'],$b['kind'])?:($b['id']<=>$a['id']));
            $items=array_slice($items,0,30);
            $available=array_unique([...$available,...$page['availableAdminCategories']]);
            $through[$company]=$page['through'];
            foreach($counts as $key=>$_)$counts[$key]+=$page['counts'][$key];
            foreach($page['categoryCounts'] as $key=>$states)foreach($states as $state=>$count)$categories[$key][$state]+=$count;
            $totalUnread+=$page['totalUnread'];
        }
        $groups=[];$presented=[];
        foreach($items as $position=>$item)$groups[$item['companyId']][$position]=$item;
        foreach($groups as $company=>$rows){
            $position=array_keys($rows);
            foreach($feed->present($company,array_values($rows),$user) as $index=>$item)$presented[$position[$index]]=['companyId'=>$company,...$item];
        }
        ksort($presented);
        $undo=$this->db->one('SELECT dismissal_id FROM notification_states WHERE user_id=? AND dismissal_id IS NOT NULL AND '.NotificationCategory::statePredicate($category).' ORDER BY dismissed_at DESC LIMIT 1',[(int)$user['id']]);
        return ['items'=>array_values($presented),'hasMore'=>false,'category'=>$category,'counts'=>$counts,'categoryCounts'=>$categories,'totalUnread'=>$totalUnread,'availableAdminCategories'=>array_values($available),'through'=>(object)$through,'undoId'=>$undo['dismissal_id']??null];
    }

    public function bulk(array $user,array $through,bool $dismiss,string $category): ?string
    {
        $allowed=$this->companies($user);
        foreach($through as $company=>$snapshot)if(!in_array($company,$allowed,true)||!is_object($snapshot))Http::fail(400,'Mağaza bildirim listesini yenileyin.');
        $state=new NotificationState($this->db);$batch=$dismiss?bin2hex(random_bytes(24)):null;
        TransactionRetry::run($this->db,function()use($through,$user,$state,$dismiss,$category,$batch,$allowed){
            foreach($allowed as $company)if(isset($through[$company]))$state->bulk((int)$user['id'],$company,(array)$through[$company],$dismiss,$category,$batch);
        });
        return $batch;
    }

    public function undo(array $user,string $batch,string $category): void
    {
        $companies=$this->companies($user);$state=new NotificationState($this->db);
        TransactionRetry::run($this->db,function()use($companies,$user,$state,$batch,$category){
            foreach($companies as $company)$state->undo((int)$user['id'],$company,$batch,$category);
        });
    }
}
