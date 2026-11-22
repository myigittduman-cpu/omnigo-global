<?php
declare(strict_types=1);
namespace Omnigo;

final class HepsiburadaQuestionMapper
{
    public const CLOSED=4;
    public const UNAVAILABLE=5;

    public static function thread(array $raw,string $merchantId): array
    {
        $id=(string)($raw['issueNumber']??'');
        if (!preg_match('/^[1-9][0-9]{0,9}$/D',$id)) throw new SyncFailure(422);
        $merchant=$raw['merchant']['id']??null;
        if (!is_string($merchant) || strtolower($merchant)!==$merchantId) throw new SyncFailure(422);
        $status=match($raw['status']??null) {
            'WaitingForAnswer',1=>0,
            'Answered','MerchantContacted','SellerContacted',2,7=>1,
            'Rejected',3=>3,
            'AutoClosed',4=>self::CLOSED,
            default=>self::UNAVAILABLE,
        };
        $modified=MarketplaceQuestionMapper::date($raw['lastModifiedAt']??$raw['createdAt']??null);
        if ($status===3 || $status===self::UNAVAILABLE) {
            return ['id'=>$id,'updated'=>$modified,'questionStatus'=>$status,'messages'=>[]];
        }

        $title=DeliveryMapper::text($raw['product']['name']??$raw['subject']['description']??'',500);
        if ($title==='') $title='Hepsiburada sorusu';
        $conversations=$raw['conversations']??null;
        if (!is_array($conversations) || !array_is_list($conversations) || count($conversations)>100) throw new SyncFailure(422);
        $messages=[];
        foreach ($conversations as $conversation) {
            $message=self::message($conversation);
            if ($message!==null) $messages[]=$message;
        }
        if (!$messages || !in_array('CUSTOMER_USER',array_column($messages,'sender'),true)) throw new SyncFailure(422);
        $extra=['updated'=>max($modified,max(array_column($messages,'created')))];
        $identifiers=InboxQuestionFilters::metadata($raw['product']['stockCode']??null,$raw['product']['sku']??null,$raw['orderNumber']??null);
        if($identifiers)$extra['questionIdentifiers']=$identifiers;
        if (isset($raw['expireDate'])) $extra['expiresAt']=MarketplaceQuestionMapper::date($raw['expireDate']);
        return MarketplaceQuestionMapper::thread($id,$title,$messages,$status,$extra);
    }

    private static function message(array $raw): ?array
    {
        $sender=match($raw['from']??null) {
            'Customer'=>'CUSTOMER_USER',
            'Merchant'=>'SHOP_USER',
            default=>'MARKETPLACE',
        };
        $body=$raw['content']??'';
        $attachments=is_array($raw['files']??null)?count($raw['files']):0;
        if (!is_string($body) || trim($body)==='') {
            if (!$attachments) return null;
            $body='Dosya ekli mesaj';
        }
        $id=$raw['id']??null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$id)) throw new SyncFailure(422);
        return MarketplaceQuestionMapper::message(
            $id,$body,MarketplaceQuestionMapper::date($raw['createdAt']??null),
            $sender==='SHOP_USER',['sender'=>$sender,'attachments'=>$attachments]
        );
    }
}
