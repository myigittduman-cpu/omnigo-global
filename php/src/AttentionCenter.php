<?php
declare(strict_types=1);
namespace Omnigo;

final class AttentionCenter
{
    public function __construct(private Database $db,private AttentionSources $sources){}
    private function saved(int $company):array
    {
        return array_column($this->db->all('SELECT * FROM operation_attention WHERE company_id=?',[$company]),null,'issue_key');
    }
    private static function resolved(array $item,?array $saved):bool
    {
        return $saved&&$saved['status']==='resolved'&&hash_equals($saved['fingerprint'],$item['fingerprint']);
    }
    public function pendingCount(array $user,int $company):array
    {
        $data=$this->sources->collect($user,$company);
        $saved=$this->saved($company);
        $pending=0;
        foreach($data['items'] as $item)if(!self::resolved($item,$saved[$item['key']]??null))$pending++;
        return ['pendingTotal'=>$pending,'checkedAt'=>$data['checkedAt']];
    }
    public function listing(array $user,int $company,array $query):array
    {
        Security::admin($user);$type=(string)($query['type']??'all');$state=(string)($query['status']??'open');
        if(!in_array($type,['all','inventory','shipments','payments','returns'],true)||!in_array($state,['open','in_progress','resolved','all'],true))Http::fail(400,'Kontrol filtresi geçersiz.');
        $data=$this->sources->collect($user,$company,$type);$data['items']=(new AttentionHistory($this->db,$this->sources))->reconcile($user,$company,$data['items'],$type);$saved=$this->saved($company);
        $users=$this->db->all("SELECT u.id,u.name FROM users u LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.role='admin' AND COALESCE(c.active,1)=1 ORDER BY u.name,u.id");$names=array_column($users,'name','id');$counts=['open'=>0,'in_progress'=>0,'resolved'=>0];$items=[];
        foreach($data['items'] as $item){$row=$saved[$item['key']]??null;
            if(self::resolved($item,$row))continue;
            $counts['in_progress']++;if($state==='resolved')continue;
            $items[]=[...$item,'status'=>'in_progress','version'=>(int)($row['version']??0),'assigneeId'=>null,'assignee'=>'Omnigo','note'=>''];
        }
        $page=filter_var($query['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);if($page===false)Http::fail(400,'Sayfa geçersiz.');$total=count($items);$page=min($page,max(0,(int)ceil($total/10)-1));
        return ['items'=>array_slice($items,$page*10,10),'counts'=>$counts,'total'=>$total,'page'=>$page,'pageSize'=>10,'pageCount'=>max(1,(int)ceil($total/10)),'users'=>$users,'notes'=>$data['notes'],'checkedAt'=>$data['checkedAt']];
    }
    public function save(array $user,int $company,array $data):array
    {
        Security::admin($user);$type=$data['type']??'';$key=$data['key']??'';$version=$data['version']??null;$status=$data['status']??'';$assignee=$data['assigneeId']??null;$note=$data['note']??'';
        if(!in_array($type,['inventory','shipments','payments','returns'],true)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_int($version)||$version<0||!in_array($status,['open','in_progress','resolved'],true)||!is_string($note)||mb_strlen($note)>500)Http::fail(400,'Kontrol kaydını doğrulayın.');
        $issues=$this->sources->collect($user,$company,$type)['items'];$matches=array_values(array_filter($issues,fn($item)=>hash_equals($item['key'],$key)));
        if(count($matches)!==1||!hash_equals($matches[0]['fingerprint'],(string)($data['fingerprint']??'')))Http::fail(409,'Kaynak bilgi değişti veya konu artık geçerli değil. Listeyi yenileyin.');
        $decision=$data['decision']??'';
        if($decision!==''){
            $allowed=['dismiss',...($type==='inventory'?['plan_open','keep_closed']:($type==='payments'&&isset($matches[0]['source']['differenceCents'])?['approve_amount']:[]))];
            if(!in_array($decision,$allowed,true))Http::fail(400,'Bu konu için işlem seçimi geçersiz.');
            $status=$decision==='plan_open'?'in_progress':'resolved';
            $note=match($decision){'plan_open'=>'Satışa açacağım.','keep_closed'=>'Satışa açmayacağım.','dismiss'=>'Bildirim takip listesinden kaldırıldı.','approve_amount'=>'Hesaba geçen tutar farkıyla birlikte onaylandı.'}.(trim($note)!==''?' '.trim($note):'');
            if(mb_strlen($note)>500)Http::fail(400,'İşlem notu çok uzun.');
        }
        $status=$status==='resolved'?'resolved':'in_progress';$assignee=null;$note='';
        return TransactionRetry::run($this->db,function()use($user,$company,$data,$key,$version,$status,$assignee,$note,$matches){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if($assignee!==null&&(!is_int($assignee)||!$this->db->one("SELECT u.id FROM users u LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.id=? AND u.role='admin' AND COALESCE(c.active,1)=1",[$assignee])))Http::fail(400,'Etkin bir Omnigo yetkilisi seçin.');
            $row=$this->db->one('SELECT * FROM operation_attention WHERE company_id=? AND issue_key=?'.$this->db->lock(),[$company,$key]);if((int)($row['version']??0)!==$version)Http::fail(409,'Bu konu başka bir yetkili tarafından güncellendi.');
            $now=gmdate('c');$values=[$matches[0]['fingerprint'],$status,$assignee,trim($note),$now,$company,$key];
            if($row)$this->db->run('UPDATE operation_attention SET fingerprint=?,status=?,assignee_id=?,note=?,updated=?,version=version+1 WHERE company_id=? AND issue_key=?',$values);
            else $this->db->run('INSERT INTO operation_attention(fingerprint,status,assignee_id,note,updated,company_id,issue_key,version) VALUES(?,?,?,?,?,?,?,1)',$values);
            if($status==='resolved')(new AttentionHistory($this->db,$this->sources))->suppress($company,$matches[0]);
            return ['saved'=>true,'version'=>$version+1];
        });
    }
}
