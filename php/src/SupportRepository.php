<?php
declare(strict_types=1);
namespace Omnigo;

final class SupportRepository
{
    public function __construct(private Database $db) {}
    public function ready(): bool
    {
        try{$this->db->one('SELECT id FROM support_tickets LIMIT 1');$this->db->one('SELECT id FROM support_messages LIMIT 1');return true;}
        catch(\PDOException $error){if(in_array((string)$error->getCode(),['42S02','HY000'],true))return false;throw $error;}
    }
    private function authorize(array $user,int $company): void
    {
        if($user['role']!=='admin' && (int)($user['company_id']??0)!==$company)Http::fail(403,'Bu talebe erişemezsiniz.');
        if(!$this->db->one('SELECT id FROM companies WHERE id=?',[$company]))Http::fail(404,'Firma bulunamadı.');
    }
    private function requestId(array $data): string
    {
        $value=$data['requestId']??null;
        if(!is_string($value)||!preg_match('/^[a-f0-9]{48}$/D',$value))Http::fail(400,'İstek kimliği geçersiz.');
        return $value;
    }
    private function write(callable $action): mixed
    {
        for($attempt=0;;$attempt++) {
            try{return $this->db->transaction($action);}
            catch(\PDOException $error){
                if($attempt>=2 || !in_array((int)($error->errorInfo[1]??0),[5,1205,1213],true))throw $error;
                usleep(50000*($attempt+1));
            }
        }
    }
    public function listing(array $user,int $company,int $page=0): array
    {
        $this->authorize($user,$company);
        if($page<0||$page>10000)Http::fail(400,'Sayfa geçersiz.');
        $offset=$page*20;
        $rows=$this->db->all("SELECT id,subject,category,status,created,updated FROM support_tickets WHERE company_id=? AND status<>'deleted' ORDER BY updated DESC,id DESC LIMIT 21 OFFSET $offset",[$company]);
        return ['available'=>true,'items'=>array_slice($rows,0,20),'hasMore'=>count($rows)>20];
    }
    private function ticket(array $user,int $company,int $id,bool $lock=false): array
    {
        $this->authorize($user,$company);
        $row=$this->db->one('SELECT * FROM support_tickets WHERE id=? AND company_id=?'.($lock?$this->db->lock():''),[$id,$company]);
        if(!$row||$row['status']==='deleted')Http::fail(404,'Destek talebi bulunamadı.');
        return $row;
    }
    public function detail(array $user,int $company,int $id,int $before=0): array
    {
        $ticket=$this->ticket($user,$company,$id);
        $args=[$id];$boundary='';if($before>0){$boundary=' AND m.id<?';$args[]=$before;}
        $rows=$this->db->all("SELECT m.id,m.body,m.sender_role,m.created FROM support_messages m WHERE m.ticket_id=?$boundary ORDER BY m.id DESC LIMIT 51",$args);
        return ['id'=>(int)$ticket['id'],'subject'=>$ticket['subject'],'category'=>$ticket['category'],'status'=>$ticket['status'],'messages'=>array_reverse(array_slice($rows,0,50)),'hasOlder'=>count($rows)>50];
    }
    public function create(array $user,int $company,array $data): int
    {
        $this->authorize($user,$company);
        $subject=Validation::text($data['subject']??null,'Konu',150);
        $body=Validation::text($data['body']??null,'Mesaj',3000);
        $category=Validation::choice($data['category']??null,['question','issue']);$nonce=$this->requestId($data);
        return $this->write(function()use($user,$company,$subject,$body,$category,$nonce){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $prior=$this->db->one('SELECT id FROM support_tickets WHERE company_id=? AND created_by=? AND request_id=?',[$company,$user['id'],$nonce]);if($prior)return (int)$prior['id'];
            $count=$this->db->one('SELECT COUNT(*) AS n FROM support_tickets WHERE company_id=? AND created_by=? AND created>?',[$company,$user['id'],gmdate('c',time()-3600)]);
            if((int)$count['n']>=10)Http::fail(429,'Çok sayıda talep açtınız. Bir süre sonra tekrar deneyin.');
            $now=gmdate('c');
            $this->db->run('INSERT INTO support_tickets(company_id,created_by,request_id,subject,category,status,created,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$user['id'],$nonce,$subject,$category,'open',$now,$now]);
            $id=$this->db->insertId();$this->insertMessage($id,$user,$nonce,$body,$now);
            $this->audit($user,$company,$id,'Destek talebi açıldı');return $id;
        });
    }
    private function insertMessage(int $id,array $user,string $nonce,string $body,string $now): void
    {
        $this->db->run('INSERT INTO support_messages(ticket_id,user_id,request_id,sender_role,body,created) VALUES(?,?,?,?,?,?)',[$id,$user['id'],$nonce,$user['role'],$body,$now]);
    }
    private function audit(array $user,int $company,int $id,string $action): int
    {
        $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$user['id'],$company,$action,$id,gmdate('c')]);
        $audit=$this->db->insertId();
        (new UserChangeNotifications($this->db))->record($user,$company,$id,$audit,$action);
        return $audit;
    }
    public function reply(array $user,int $company,int $id,array $data): void
    {
        $body=Validation::text($data['body']??null,'Mesaj',3000);$nonce=$this->requestId($data);
        $this->write(function()use($user,$company,$id,$body,$nonce){
            $ticket=$this->ticket($user,$company,$id,true);
            if($this->db->one('SELECT id FROM support_messages WHERE ticket_id=? AND user_id=? AND request_id=?',[$id,$user['id'],$nonce]))return;
            if($ticket['status']==='closed')Http::fail(409,'Bu talep kapalı. Önce yeniden açın.');
            $count=$this->db->one('SELECT COUNT(*) AS n FROM support_messages WHERE ticket_id=? AND user_id=? AND created>?',[$id,$user['id'],gmdate('c',time()-60)]);
            if((int)$count['n']>=10)Http::fail(429,'Mesajlar çok hızlı gönderiliyor. Bir dakika sonra tekrar deneyin.');
            $now=gmdate('c');$this->insertMessage($id,$user,$nonce,$body,$now);
            $this->db->run('UPDATE support_tickets SET status=?,updated=? WHERE id=?',[$user['role']==='admin'?'answered':'open',$now,$id]);
            $audit=$this->audit($user,$company,$id,'Destek mesajı eklendi');
            (new SupportResolutionNotifications($this->db))->record($user,$company,$ticket,$audit,'reply');
        });
    }
    public function status(array $user,int $company,int $id,string $status): void
    {
        if($user['role']!=='admin')Http::fail(403,'Destek talebinin durumunu yalnız Omnigo değiştirebilir.');
        if(!in_array($status,['closed','open'],true))Http::fail(400,'Talep durumu geçersiz.');
        $this->write(function()use($user,$company,$id,$status){
            $ticket=$this->ticket($user,$company,$id,true);if($ticket['status']===$status)return;
            $this->db->run('UPDATE support_tickets SET status=?,updated=? WHERE id=?',[$status,gmdate('c'),$id]);
            $audit=$this->audit($user,$company,$id,$status==='closed'?'Destek talebi kapatıldı':'Destek talebi yeniden açıldı');
            if($status==='closed')(new SupportResolutionNotifications($this->db))->record($user,$company,$ticket,$audit);
        });
    }

    public function delete(array $user,int $company,int $id): void
    {
        if($user['role']!=='admin')Http::fail(403,'Destek talebini yalnız Omnigo silebilir.');
        $this->write(function()use($user,$company,$id){
            $this->authorize($user,$company);
            $ticket=$this->db->one('SELECT status FROM support_tickets WHERE id=? AND company_id=?'.$this->db->lock(),[$id,$company]);
            if(!$ticket)Http::fail(404,'Destek talebi bulunamadı.');
            if($ticket['status']==='deleted')return;
            // Keep the conversation and audit history; remove it from both workspaces.
            $this->db->run("UPDATE support_tickets SET status='deleted',updated=? WHERE id=? AND company_id=?",[gmdate('c'),$id,$company]);
            $this->audit($user,$company,$id,'Destek talebi silindi');
        });
    }
}
