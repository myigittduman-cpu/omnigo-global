<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxRoutes
{
    public function __construct(private Database $db,private Security $security) {}
    public function handle(string $path,string $method,array $user): void
    {
        if(!str_starts_with($path,'/api/inbox/'))return;
        Security::admin($user);$repo=new InboxRepository($this->db);
        $scope=isset($_GET['company'])?$this->security->company($user,$_GET['company']):null;
        if($scope!==null&&!CustomerSetupSelection::hasLive($this->db,$scope,'inbox')
            &&!CustomerSetupSelection::hasManual($this->db,$scope,'questionReply')){
            Http::fail(403,'Pazaryeri Soruları bu mağazanın çalışma kapsamında etkin değil.');
        }
        if(!$repo->ready()){
            if($path==='/api/inbox/notifications'&&$method==='GET')Http::json(['available'=>false,'items'=>[],'unreadCount'=>0,'jobs'=>[],'hasMore'=>false]);
            Http::fail(503,'Mesajlar için veritabanı güncellemesi gerekiyor.');
        }
        if($path==='/api/inbox/notifications'&&$method==='GET'){
            $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
            if($page===false)Http::fail(400,'Sayfa geçersiz.');
            $status=Validation::choice($_GET['status']??'waiting',InboxStatus::FILTERS);
            $provider=Validation::choice($_GET['provider']??'all',['all',...array_keys(InboxQuestionFilters::PROVIDERS)]);
            $search=Validation::text($_GET['q']??'','SKU veya sipariş numarası',100,true);
            $size=(int)Validation::choice($_GET['size']??'50',['10','50']);
            $sort=Validation::choice($_GET['sort']??'newest',['newest','oldest']);
            $table=in_array($_GET['presentation']??'',['table','download'],true);
            Http::json($repo->summary((int)$user['id'],$page,($_GET['unread']??'')==='1',$scope,$status==='all'?'waiting':$status,
                $provider==='all'?null:$provider,$search,$size,$sort,$table));
        }
        if($path==='/api/inbox/scan'&&$method==='POST'){
            $company=$this->security->company($user,$_GET['company']??null);
            if(!CustomerSetupSelection::hasLive($this->db,$company,'inbox'))Http::fail(403,'Canlı soru aktarımı bu mağazada etkin değil.');
            if(!$this->db->one("SELECT id FROM records WHERE company_id=? AND kind='integration_koctas'",[$company]))Http::fail(409,'Önce bu firmanın Koçtaş bağlantısını kurun.');
            Http::json(['jobId'=>(new InboxService($this->db))->enqueue($company,(int)$user['id'],'scan')],202);
        }
        if(preg_match('#^/api/inbox/jobs/([a-f0-9]{48})/resolve$#D',$path,$match)&&$method==='POST'){
            $data=Http::body();$resolution=$data['resolution']??null;
            if(!in_array($resolution,['confirmed_sent','cancelled'],true)||($data['checkedMarketplace']??false)!==true)Http::fail(400,'Önce pazaryerindeki konuşmayı kontrol edin.');
            $this->db->transaction(function()use($match,$resolution,$user,$scope){
                $job=$this->db->one("SELECT * FROM inbox_jobs WHERE id=?".$this->db->lock(),[$match[1]]);
                if($scope!==null&&(!$job||(int)$job['company_id']!==$scope))Http::fail(404,'Gönderim bulunamadı.');
                if(!$job||$job['kind']!=='reply'||!in_array($job['state'],['unknown','failed'],true)
                    ||!$this->db->one('SELECT reply.id FROM inbox_threads t'.InboxStatus::replyJoin()
                        .' WHERE t.id=? AND t.company_id=? AND reply.id=? AND '.InboxStatus::waiting(),[$job['thread_id'],$job['company_id'],$job['id']]))
                    Http::fail(409,'Kontrol bekleyen bir gönderim bulunamadı.');
                $this->db->run('UPDATE inbox_jobs SET state=?,error=? WHERE id=?',[$resolution,'Sonuç yönetici tarafından pazaryerinden kontrol edildi.',$job['id']]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[$user['id'],$job['company_id'],'Mesaj gönderimi elle doğrulandı',$job['thread_id'],json_encode(['state'=>$resolution]),gmdate('c')]);
            });Http::json(['message'=>'Gönderim sonucu kaydedildi.']);
        }
        if(preg_match('#^/api/inbox/notifications/(\d+)/read$#D',$path,$m)&&$method==='POST'){$repo->markRead((int)$user['id'],(int)$m[1],$scope);Http::json(['message'=>'Okundu olarak işaretlendi.']);}
        if(preg_match('#^/api/inbox/threads/(\d+)(?:/(refresh|reply|attachments|review-rejection))?$#D',$path,$m)){
            $id=(int)$m[1];$thread=$repo->thread($id);if(!$thread)Http::fail(404,'Konuşma bulunamadı.');
            if($scope!==null&&(int)$thread['company_id']!==$scope)Http::fail(404,'Konuşma bulunamadı.');
            $company=$this->security->company($user,$thread['company_id']);$action=$m[2]??'';
            if(!CustomerSetupSelection::hasLive($this->db,$company,'inbox')
                &&!CustomerSetupSelection::hasManual($this->db,$company,'questionReply'))Http::fail(403,'Pazaryeri Soruları bu mağazada etkin değil.');
            if($method==='GET'&&$action==='')Http::json($repo->detail($id));
            if($method==='POST'&&$action==='review-rejection'){
                (new InboxRejections($this->db))->review((int)$user['id'],$company,$id);
                Http::json(['message'=>'İnceleme tamamlandı.']);
            }
            if($method==='POST'&&in_array($action,['refresh','attachments','reply'],true)){
                $snapshot=$repo->replySnapshot($thread);
                if(InboxStatus::value($snapshot)==='waiting'&&!InboxQuestionWindow::contains($snapshot['latestCustomerAt'])){
                    Http::fail(409,'Bu soru 1 Kasım 2026 öncesine ait.');
                }
            }
            if($method==='POST'&&$action==='attachments'){
                CustomerSetupSelection::requireManual($this->db,$company,'questionReply');
                if($thread['provider']!=='koctas')Http::fail(409,'Bu pazaryerinin yanıt servisi dosya eki desteklemiyor.');
                $file=$_FILES['file']??null;
                if(!$file||!is_string($file['tmp_name']??null)||($file['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))Http::fail(400,'Dosya yüklenemedi. En fazla 2 MB PDF veya görsel seçin.');
                if(filesize($file['tmp_name'])>2097152)Http::fail(413,'Her dosya en fazla 2 MB olabilir.');
                $store=InboxAttachments::local();$store->cleanup($this->db);
                Http::json($store->save($company,$id,(int)$user['id'],$file['name'],file_get_contents($file['tmp_name'])),201);
            }
            if($method==='POST'&&!InboxReplyPolicy::supported($thread['provider']))Http::fail(409,'Bu pazaryerinde yanıt bağlantısı henüz etkin değil.');
            if($method==='POST'&&$action==='refresh')Http::json(['jobId'=>(new InboxService($this->db))->enqueue($company,(int)$user['id'],'detail',[],$id)],202);
            if($method==='POST'&&$action==='reply'){
                CustomerSetupSelection::requireManual($this->db,$company,'questionReply');
                $data=Http::body();$body=Validation::text($data['body']??null,'Yanıt',2000);$nonce=$data['requestId']??'';$fingerprint=$data['fingerprint']??'';
                if($thread['provider']==='trendyol'&&!TrendyolQuestionClient::validReply($body))Http::fail(400,'Trendyol yanıtı 10 ile 2000 karakter arasında olmalı.');
                if(!is_string($nonce)||!preg_match('/^[a-f0-9]{48}$/D',$nonce)||!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))Http::fail(400,'Yanıt bilgileri geçersiz.');
                $attachments=$data['attachments']??[];if(!is_array($attachments))Http::fail(400,'Dosya listesi geçersiz.');
                if($old=$this->db->one('SELECT * FROM inbox_jobs WHERE id=?',[$nonce])){
                    $prior=json_decode($old['payload'],true,64,JSON_THROW_ON_ERROR);
                    if((int)$old['company_id']!==$company||(int)$old['thread_id']!==$id||(int)$old['user_id']!==(int)$user['id']||$old['kind']!=='reply'||($prior['body']??null)!==$body||($prior['fingerprint']??null)!==$fingerprint||($prior['attachments']??[])!==$attachments)Http::fail(409,'İşlem kimliği farklı bir isteğe ait.');
                    Http::json(['jobId'=>$nonce],202);
                }
                if(!hash_equals($snapshot['fingerprint'],$fingerprint))Http::fail(409,'Konuşma değişti. Yeni mesajları inceleyin.');
                if(!InboxReplyPolicy::mayReply($thread['provider'],$snapshot))Http::fail(409,'Bu soru şu anda yanıt beklemiyor veya konuşma henüz tam alınmadı.');
                if($attachments&&$thread['provider']!=='koctas')Http::fail(409,'Bu pazaryerinde dosya eki desteklenmiyor.');
                if($attachments)InboxAttachments::local()->load($company,$id,(int)$user['id'],$attachments);
                $payload=['body'=>$body,'fingerprint'=>$fingerprint,'recipients'=>$snapshot['recipients']];if($attachments)$payload['attachments']=$attachments;
                Http::json(['jobId'=>(new InboxService($this->db))->enqueue($company,(int)$user['id'],'reply',$payload,$id,$nonce)],202);
            }
        }
        Http::fail(405,'Mesaj işlemi desteklenmiyor.');
    }
}
