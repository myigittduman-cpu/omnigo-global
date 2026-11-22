<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxMapping
{
    private static function text(mixed $v,int $max): string
    {
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>$max)throw new InboxFailure(422);
        return $v;
    }
    public static function remoteId(mixed $v): string
    {
        $id=self::text($v,180);if(!preg_match('/^[A-Za-z0-9_-]+$/D',$id))throw new InboxFailure(422);return $id;
    }
    private static function recipientId(mixed $v): string
    {
        // Participant IDs are opaque values sent inside JSON, never URL path segments.
        $id=self::text($v,500);
        if(trim($id)===''||preg_match('/[\x00-\x1f\x7f]/u',$id))throw new InboxFailure(422);
        return $id;
    }
    private static function stamp(mixed $v): string
    {
        $value=self::text($v,40);$ts=strtotime($value);if($ts===false)throw new InboxFailure(422);return gmdate('c',$ts);
    }
    public static function thread(array $raw): ?array
    {
        $participants=$raw['current_participants']??[];
        // Operator-only conversations must never be mistaken for shopper questions.
        if(!array_filter($participants,fn($p)=>($p['type']??'')==='CUSTOMER'))return null;
        $recipients=[];
        foreach($raw['authorized_participants']??[] as $p) if(($p['type']??'')==='CUSTOMER') $recipients[]=['type'=>'CUSTOMER','id'=>self::recipientId($p['id']??null)];
        $entities=[];foreach($raw['entities']??[] as $e)if(in_array($e['type']??'', ['MMP_ORDER','MMP_OFFER'],true))$entities[]=['type'=>$e['type'],'id'=>self::text($e['id']??'',180),'label'=>self::text($e['label']??'',500)];
        $identifiers=InboxQuestionFilters::identifiers(['entities'=>$entities]);
        $messages=[];
        foreach($raw['messages']??[] as $m) {
            $sender=$m['from']['type']??'';
            if(!in_array($sender,['CUSTOMER_USER','SHOP_USER','OPERATOR_USER'],true))throw new InboxFailure(422);
            $messages[]=['id'=>self::remoteId($m['id']??null),'body'=>self::text($m['body']??null,100000),'created'=>self::stamp($m['date_created']??null),'sender'=>$sender,'attachments'=>count($m['attachments']??[])];
        }
        usort($messages,fn($a,$b)=>strcmp($a['created'],$b['created'])?:strcmp($a['id'],$b['id']));
        $fingerprint=hash('sha256',json_encode([$raw['metadata']['last_message_date']??null,$raw['metadata']['total_count']??null,array_column($messages,'id')],JSON_THROW_ON_ERROR));
        $waitingSince=$raw['metadata']['shop_reply_needed_since']??null;
        $lastCustomer=InboxQuestionWindow::latestCustomerMessage(['messages'=>$messages]);
        $shopMessages=array_filter($messages,static fn(array $m):bool=>$m['sender']==='SHOP_USER');
        $replyNeeded=is_string($waitingSince)&&strtotime($waitingSince)!==false&&$lastCustomer!==null
            && (!$shopMessages||max(array_column($shopMessages,'created'))<=$lastCustomer);
        return ['id'=>self::remoteId($raw['id']??null),'title'=>self::text($raw['topic']['value']??'Müşteri mesajı',1000),'updated'=>self::stamp($raw['date_updated']??null),'replyNeeded'=>$replyNeeded,'recipients'=>$recipients,'entities'=>$entities,'fingerprint'=>$fingerprint,'partial'=>(int)($raw['metadata']['total_count']??count($messages))>count($messages),'messages'=>$messages,...($identifiers?['questionIdentifiers'=>$identifiers]:[])];
    }
}
