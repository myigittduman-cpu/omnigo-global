<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceQuestionMapper
{
    public static function date(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/D',$value))throw new SyncFailure(422);
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){throw new SyncFailure(422);}
        if(\DateTimeImmutable::getLastErrors()!==false)throw new SyncFailure(422);
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }
    public static function thread(string $id,string $title,array $messages,int $status,array $extra=[]): array
    {
        if($id===''||$title===''||!$messages||count($messages)>100)throw new SyncFailure(422);
        $thread=['id'=>$id,'title'=>$title,'updated'=>max(array_column($messages,'created')),'replyNeeded'=>$status===0,'questionStatus'=>$status,'recipients'=>[],'entities'=>[],'partial'=>false,'messages'=>$messages,...$extra];
        $thread['fingerprint']=hash('sha256',json_encode($thread,JSON_THROW_ON_ERROR));return $thread;
    }
    public static function message(string $id,string $body,string $created,bool $answer=false,array $extra=[]): array
    {
        if(trim($body)==='')throw new SyncFailure(422);
        return ['id'=>$id,'body'=>mb_substr($body,0,20000),'created'=>$created,'sender'=>$answer?'SHOP_USER':'CUSTOMER_USER','attachments'=>0,...$extra];
    }
    public static function idefix(array $raw,string $stream): array
    {
        $remote=(string)($raw['id']??'');if(!ctype_digit($remote))throw new SyncFailure(422);
        $date=self::date($raw['createdAt']??null);
        if($stream==='product'){
            $id='product:'.$remote;$answers=$raw['productQuestionAnswer']??null;if(!is_array($answers))throw new SyncFailure(422);
            $messages=[self::message($id.':question',(string)($raw['question']??''),$date)];
            foreach($answers as $answer){$key=(string)($answer['id']??'');if(!ctype_digit($key))throw new SyncFailure(422);$at=isset($answer['createdAt'])?self::date($answer['createdAt']):$date;
                $messages[]=self::message($id.':answer:'.$key,(string)($answer['answerBody']??''),$at,true,['dateUnavailable'=>!isset($answer['createdAt'])]);}
            return self::thread($id,(string)($raw['product']??''),$messages,$answers?1:0,['archived'=>(bool)($raw['isArchived']??false)]);
        }
        $id='order:'.$remote;$answered=$stream==='answered';$at=self::date($raw['lastMessageDate']??$raw['createdAt']??null);$body=(string)($raw['lastMessage']??'');
        $message=self::message($id.':'.hash('sha256',$at.$body),$body,$at,$answered);
        $identifiers=InboxQuestionFilters::metadata(orderNumber:$raw['orderNumber']??null);
        return self::thread($id,'Sipariş '.DeliveryMapper::text($raw['orderNumber']??'',100).' · '.DeliveryMapper::text($raw['reason']??'Sipariş sorusu',200),[$message],$answered?1:0,[
            'historyLimited'=>true,...($identifiers?['questionIdentifiers'=>$identifiers]:[]),
        ]);
    }
    public static function n11(array $raw,string $id): array
    {
        $status=$raw['status']??'';if(!in_array($status,['OPEN','CLOSED'],true))throw new SyncFailure(422);
        $date=self::date($raw['questionDate']??null);$messages=[self::message($id.':question',(string)($raw['question']??''),$date,false,['dateOnly'=>strlen($raw['questionDate'])===10])];
        if(trim($raw['answer']??'')!=='')$messages[]=self::message($id.':answer',(string)$raw['answer'],self::date($raw['answeredDate']??null),true,['dateOnly'=>strlen($raw['answeredDate'])===10]);
        $identifiers=InboxQuestionFilters::metadata(productCode:$raw['productId']??null);
        return self::thread($id,(string)($raw['productTitle']??''),$messages,$status==='OPEN'?0:1,$identifiers?['questionIdentifiers'=>$identifiers]:[]);
    }
}
