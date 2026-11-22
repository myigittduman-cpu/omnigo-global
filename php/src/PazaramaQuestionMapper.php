<?php
declare(strict_types=1);
namespace Omnigo;

final class PazaramaQuestionMapper
{
    public static function thread(array $raw): array
    {
        $id=PazaramaSalesMapper::id($raw['questionId']??null);
        $status=$raw['questionStatus']??null;
        if(!in_array($status,[0,1,2,3],true))throw new SyncFailure(422);
        $created=self::date($raw['questionDate']??null);
        $body=PazaramaSalesMapper::text($raw['question']??null,20000);
        $messages=[['id'=>$id.'-question','body'=>$body,'created'=>$created,'sender'=>'CUSTOMER_USER','attachments'=>0]];
        $updated=$created;
        if(is_string($raw['answer']??null)&&trim($raw['answer'])!==''){
            $answerDate=self::date($raw['answerDate']??null);$answer=PazaramaSalesMapper::text($raw['answer'],20000);
            $messages[]=['id'=>$id.'-answer-'.substr(hash('sha256',$answerDate.$answer),0,24),'body'=>$answer,'created'=>$answerDate,'sender'=>'SHOP_USER','attachments'=>0];
            $updated=max($updated,$answerDate);
        }
        $thread=['id'=>$id,'title'=>PazaramaSalesMapper::text($raw['productName']??null,1000),'updated'=>$updated,'replyNeeded'=>$status===0,'questionStatus'=>$status,
            'recipients'=>[],'entities'=>[],'partial'=>false,'messages'=>$messages];
        $thread['fingerprint']=hash('sha256',json_encode($thread,JSON_THROW_ON_ERROR));
        return $thread;
    }
    private static function date(mixed $raw): string
    {
        if(!is_string($raw)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/D',$raw))throw new SyncFailure(422);
        try{$date=new \DateTimeImmutable($raw,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){throw new SyncFailure(422);}
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
