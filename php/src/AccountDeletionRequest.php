<?php
declare(strict_types=1);
namespace Omnigo;

/** An authenticated request initiates deletion; it never deletes shared company records. */
final class AccountDeletionRequest
{
    public function __construct(private Database $db,private array $config){}
    public function create(array $actor,array $data): array
    {
        if(($data['confirmed']??false)!==true)Http::fail(400,'Silme talebini onaylayın.');
        if(!empty($this->config['store_review']))return StoreReviewDeletionRequest::create($this->db,$actor);
        $mail=new MailQueue($this->db,$this->config);
        if(!$mail->ready())Http::fail(503,'Talep şu an iletilemiyor. Lütfen daha sonra tekrar deneyin.');
        return $this->db->transaction(function()use($actor,$mail){
            $user=$this->db->one('SELECT id,email,name,role FROM users WHERE id=?'.$this->db->lock(),[$actor['id']]);
            if(!$user)Http::fail(401,'Hesap bulunamadı.');
            $key='privacy-delete-'.$user['id'].'-'.gmdate('Ymd');
            $old=$this->db->one('SELECT id,state FROM mail_outbox WHERE id=?',[$key]);
            if(!$old){
                $payload=$mail->encrypt(['type'=>'privacy-deletion','to'=>'admin@omnigoglobal.com','requestId'=>$key,'userId'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role'],'createdAt'=>gmdate('c')]);
                $this->db->run("INSERT INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,'pending',?,?)",[$key,$user['id'],$payload,time(),gmdate('c')]);
            }elseif(in_array($old['state'],['failed','cancelled'],true)){
                Http::fail(503,'Talebiniz kaydedilmiş ancak iletilememiş. admin@omnigoglobal.com üzerinden talep numaranızla iletişime geçebilirsiniz: '.$key);
            }
            return ['requestId'=>$key,'message'=>'Hesap ve veri silme talebiniz kaydedildi ve sorumlu ekibe iletilmek üzere sıraya alındı. Hesabınız henüz silinmedi. Kapsam ve tamamlanma bilgisi kayıtlı e-posta adresinize iletilecek.'];
        });
    }
    public static function message(array $payload): array
    {
        if(($payload['to']??'')!=='admin@omnigoglobal.com'||!filter_var($payload['email']??'',FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Silme talebi doğrulanamadı.');
        return ['subject'=>'Omnigo Global — Hesap ve veri silme talebi','body'=>"Talep: ".$payload['requestId']."\nTarih: ".$payload['createdAt']."\nHesap: ".$payload['email']."\nAd: ".$payload['name']."\nKullanıcı kimliği: ".$payload['userId']."\nUygulama: ".($payload['role']==='admin'?'Yönetici':'Müşteri')."\n\nKullanıcı, oturum açarak kendi hesabının ve saklanması gerekmeyen kişisel verilerinin kalıcı olarak silinmesini talep etti. Ortak işletme kayıtları veya başka hesaplar için silme yetkisi verilmedi. Talebi işleme alıp kapsam, saklama istisnaları ve tamamlanma zamanını kayıtlı e-posta adresine iletin.\n\nBu bildirim hesabın silindiği anlamına gelmez."];
    }
}
