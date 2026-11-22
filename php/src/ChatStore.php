<?php
declare(strict_types=1);
namespace Omnigo;

final class ChatStore
{
    public function __construct(private Database $db){}

    public function overview(array $user):array
    {
        $company=$user['role']==='client'?(int)$user['company_id']:null;
        $args=[$user['id']];
        if($company!==null)$args[]=$company;
        $scope=$company===null?'':' AND t.company_id=?';
        $unread="SELECT COUNT(*) FROM chat_messages b WHERE b.thread_id=t.id AND b.sender_id<>m.user_id AND b.id>m.read_id AND b.deleted_at IS NULL";
        $from='FROM chat_threads t JOIN chat_members m ON m.thread_id=t.id WHERE m.user_id=?'.$scope;
        $latest='SELECT COALESCE(MAX(b.id),0) FROM chat_messages b WHERE b.thread_id=t.id';
        if($user['role']==='admin'){
            $from.=' AND NOT EXISTS (SELECT 1 FROM chat_thread_dismissals d WHERE d.thread_id=t.id AND d.user_id=m.user_id'
                .' AND NOT EXISTS (SELECT 1 FROM chat_messages b WHERE b.thread_id=d.thread_id AND b.id>d.through_id))';
        }
        $count=$this->db->one("SELECT COALESCE(SUM(($unread)),0) unread $from",$args);
        $rows=$this->db->all("SELECT t.*,($unread) unread,($latest) latest_id $from ORDER BY t.updated DESC,t.id DESC LIMIT 100",$args);
        $names=[];
        if($rows){
            $in=implode(',',array_fill(0,count($rows),'?'));
            foreach($this->db->all("SELECT m.thread_id,u.name FROM chat_members m JOIN users u ON u.id=m.user_id WHERE m.user_id<>? AND m.thread_id IN ($in) ORDER BY u.name",[$user['id'],...array_column($rows,'id')]) as $person)
                $names[$person['thread_id']][]=$person['name'];
        }
        return ['unread'=>(int)$count['unread'],'threads'=>array_map(static fn(array $row)=>[
            'id'=>(int)$row['id'],'kind'=>$row['kind'],'title'=>$row['kind']==='direct'?($names[$row['id']][0]??'Birebir sohbet'):$row['title'],
            'unread'=>(int)$row['unread'],'updatedAt'=>(int)$row['updated'],'latestId'=>(int)$row['latest_id'],
        ],$rows)];
    }

    public function create(array $user,array $input):int
    {
        $kind=Validation::choice($input['kind']??null,['direct','group']);
        $ids=$input['members']??null;
        if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>29||($kind==='direct'&&count($ids)!==1))Http::fail(400,'Sohbet üyelerini seçin.');
        foreach($ids as $id)if(!is_int($id)||$id<1||$id===(int)$user['id'])Http::fail(400,'Sohbet üyesi geçersiz.');
        if(count(array_unique($ids))!==count($ids))Http::fail(400,'Sohbet üyesi tekrarlanamaz.');
        $ids[]=(int)$user['id'];sort($ids,SORT_NUMERIC);
        $title=$kind==='direct'?'':Validation::text($input['title']??null,'Grup adı',100);
        $nonce=$kind==='direct'?'':self::nonce($input['nonce']??null);
        $scopedGroup=$kind==='group'&&array_key_exists('company',$input);
        $scope=$scopedGroup&&$input['company']!==null?Validation::integer($input['company']):null;
        return TransactionRetry::run($this->db,function()use($user,$ids,$kind,$title,$nonce,$scopedGroup,$scope){
            $company=(new ChatAccess($this->db))->participants($user,$ids,$kind==='group');
            if($scopedGroup&&$company!==null&&$company!==$scope)Http::fail(403,'Bu gruba yalnız seçili mağazanın kullanıcılarını ekleyebilirsiniz.');
            $identity=hash('sha256',json_encode([$kind,$company,$ids,$title,$nonce],JSON_THROW_ON_ERROR));
            $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            $now=time();
            $created=$this->db->run("$insert INTO chat_threads(identity_key,company_id,title,kind,created,updated) VALUES(?,?,?,?,?,?)",
                [$identity,$company,$title,$kind,$now,$now]);
            $id=(int)$this->db->one('SELECT id FROM chat_threads WHERE identity_key=?'.$this->db->lock(),[$identity])['id'];
            if($created->rowCount()===1)foreach($ids as $member)$this->db->run('INSERT INTO chat_members(thread_id,user_id) VALUES(?,?)',[$id,$member]);
            elseif($user['role']==='admin')$this->db->run('DELETE FROM chat_thread_dismissals WHERE thread_id=? AND user_id=?',[$id,$user['id']]);
            return $id;
        });
    }

    public function detail(array $user,int $thread,int $before,bool $visibleOnly=false,int $knownFrom=0,int $knownTo=0):array
    {
        $record=(new ChatAccess($this->db))->thread($user,$thread);
        $args=[$thread];
        if($before)$args[]=$before;
        $range=$before?' AND b.id<?':'';
        if($visibleOnly)$range.=' AND b.deleted_at IS NULL';
        $messages=$this->db->all("SELECT b.*,u.name FROM chat_messages b LEFT JOIN users u ON u.id=b.sender_id WHERE b.thread_id=?$range ORDER BY b.id DESC LIMIT 41",$args);
        $members=$this->db->all('SELECT u.id,u.name,u.role,COALESCE(s.last_seen,0) last_seen FROM '.ChatAccess::ACCOUNTS.
            ' JOIN chat_members m ON m.user_id=u.id LEFT JOIN chat_presence s ON s.user_id=u.id WHERE '.ChatAccess::ACTIVE.
            " AND m.thread_id=? AND (u.role='admin' OR u.company_id=?) ORDER BY u.name",[$thread,$record['company_id']]);
        $result=['members'=>array_map(ChatAccess::publicAccount(...),$members),'hasMore'=>count($messages)>40,'messages'=>array_map(static fn(array $row)=>[
            'id'=>(int)$row['id'],'senderId'=>(int)$row['sender_id'],'sender'=>$row['name']??'Kullanıcı','text'=>$row['deleted_at']===null?$row['body']:'',
            'createdAt'=>(int)$row['created'],'deleted'=>$row['deleted_at']!==null,
            'canDelete'=>$row['deleted_at']===null&&$user['role']==='admin'&&(int)$row['sender_id']===(int)$user['id'],
        ],array_reverse(array_slice($messages,0,40)))];
        if($visibleOnly&&$knownFrom>0){
            $removed=$this->db->all('SELECT id FROM chat_messages WHERE thread_id=? AND id BETWEEN ? AND ? AND deleted_at IS NOT NULL',
                [$thread,$knownFrom,$knownTo]);
            $result['removedIds']=array_map('intval',array_column($removed,'id'));
        }
        return $result;
    }

    public function send(array $user,int $thread,array $input):int
    {
        $body=Validation::text($input['text']??null,'Mesaj',2000);
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$body))Http::fail(400,'Mesajdaki geçersiz karakterleri kaldırın.');
        $nonce=self::nonce($input['nonce']??null);
        return TransactionRetry::run($this->db,function()use($user,$thread,$body,$nonce){
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            $record=(new ChatAccess($this->db))->thread($user,$thread,true);
            $prior=$this->db->one('SELECT id,body,deleted_at FROM chat_messages WHERE thread_id=? AND sender_id=? AND nonce=?',[$thread,$user['id'],$nonce]);
            if($prior){
                if($prior['deleted_at']===null&&$prior['body']!==$body)Http::fail(409,'Mesaj isteği zaten kullanıldı.');
                return (int)$prior['id'];
            }
            $now=time();
            $recent=$this->db->one('SELECT COUNT(*) n FROM chat_messages WHERE sender_id=? AND created>?',[$user['id'],$now-60]);
            if((int)$recent['n']>=30)Http::fail(429,'Biraz bekleyip yeniden gönderin.');
            $this->db->run('INSERT INTO chat_messages(thread_id,sender_id,nonce,body,created) VALUES(?,?,?,?,?)',[$thread,$user['id'],$nonce,$body,$now]);
            $id=$this->db->insertId();
            $this->db->run('UPDATE chat_threads SET updated=? WHERE id=?',[$now,$thread]);
            (new ChatNotifications($this->db))->enqueue($record,(int)$user['id'],$id,$now);
            return $id;
        });
    }

    public function read(array $user,int $thread,int $id):void
    {
        (new ChatAccess($this->db))->thread($user,$thread);
        if(!$this->db->one('SELECT id FROM chat_messages WHERE thread_id=? AND id=?',[$thread,$id]))Http::fail(404,'Mesaj bulunamadı.');
        $this->db->run('UPDATE chat_members SET read_id=? WHERE user_id=? AND thread_id=? AND read_id<?',[$id,$user['id'],$thread,$id]);
    }

    public function delete(array $user,int $thread,int $id):void
    {
        (new ChatDeletion($this->db))->messages($user,$thread,['ids'=>[$id]]);
    }

    public function presence(array $user):void
    {
        $conflict=$this->db->mysql()?' ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)':' ON CONFLICT(user_id) DO UPDATE SET last_seen=excluded.last_seen';
        $this->db->run('INSERT INTO chat_presence(user_id,last_seen) VALUES(?,?)'.$conflict,[$user['id'],time()]);
    }

    private static function nonce(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$value))Http::fail(400,'Sohbet isteğini yenileyin.');
        return $value;
    }
}
