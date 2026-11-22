<?php
declare(strict_types=1);
namespace Omnigo;

/** Scan by transaction date because a delivered order can settle days after its order date. */
final class TrendyolProfitabilitySettlements
{
    private const WINDOW_MS=15*86400000;
    private const PAGE_SIZE=500;
    private const ORIGIN='https://apigw.trendyol.com/integration/finance/che/sellers/';

    public function __construct(private ?QuestionReadTransport $transport=null) {}

    /** One page per leased worker turn. Incomplete results stay private to the checkpoint. */
    public function read(array $settings,array $orders,\DateTimeImmutable $day,int $deadline,?array &$checkpoint=null): array
    {
        $seller=(string)($settings['supplierId']??'');
        if (empty($settings['enabled']) || !preg_match('/^[1-9]\d{0,15}$/D',$seller)
            || !is_string($settings['apiKey']??null) || $settings['apiKey']===''
            || !is_string($settings['apiSecret']??null) || $settings['apiSecret']==='') throw new SyncFailure(401);
        $sources=[];
        foreach ($orders as $saved) {
            $order=$saved['sourceOrder']??null;
            $number=(string)$saved['order_number'];
            if (is_array($order) && (string)($order['externalId']??'')===$number && !empty($order['products'])
                && !empty($order['orders']) && empty($order['returnCents']) && empty($order['cancelCents'])) $sources[$number]=$order;
        }
        if (!$sources) { $checkpoint=null; return []; }
        $shape=[];
        foreach ($sources as $number=>$order) {
            $shape[$number]=[$order['createdAt']??null,ProfitabilityCommission::fingerprint($order['products']),
                array_column($order['products'],'lineId')];
        }
        $batch=hash('sha256',json_encode($shape,JSON_THROW_ON_ERROR));
        $start=$day->getTimestamp()*1000;
        if (($checkpoint['batch']??null)!==$batch) $checkpoint=[
            'batch'=>$batch,'through'=>time()*1000,'from'=>$start,'page'=>0,'total'=>null,'movements'=>[],
        ];
        $from=$checkpoint['from'];
        $until=min($from+self::WINDOW_MS-1,$checkpoint['through']);
        $page=$checkpoint['page'];
        if ($from>$until) { $checkpoint=null; return []; }
        $query=http_build_query(['startDate'=>$from,'endDate'=>$until,'transactionType'=>'Sale','size'=>self::PAGE_SIZE,'page'=>$page]);
        $headers=['Accept: application/json','User-Agent: '.$seller.' - OmnigoGlobal',
            'Authorization: Basic '.base64_encode($settings['apiKey'].':'.$settings['apiSecret'])];
        $body=($this->transport??new QuestionReadTransport())->read(self::ORIGIN.$seller.'/settlements?'.$query,$headers,$deadline);
        try { $reply=json_decode($body,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
        catch (\JsonException) { throw new SyncFailure(502); }
        $rows=$reply['content']??null;
        $total=$reply['totalElements']??null;
        $pages=$reply['totalPages']??null;
        if (!is_array($rows) || !array_is_list($rows) || !is_int($total) || $total<0 || $total>100000000
            || ($reply['page']??null)!==$page || ($reply['size']??null)!==self::PAGE_SIZE
            || !is_int($pages) || ($pages!==intdiv($total+self::PAGE_SIZE-1,self::PAGE_SIZE) && !($total===0 && $pages===1))) throw new SyncFailure(422);
        if ($checkpoint['total']!==null && $checkpoint['total']!==$total) {
            // Do not mix two versions of a shifting paginated result. Restart after this leased turn.
            $checkpoint=['batch'=>null];
            return [];
        }
        if (count($rows)!==min(self::PAGE_SIZE,max(0,$total-$page*self::PAGE_SIZE))) throw new SyncFailure(422);
        foreach ($rows as $raw) {
            $owner=is_array($raw)?($raw['sellerId']??null):null;
            if ((!is_int($owner) && !is_string($owner)) || (string)$owner!==$seller) throw new SyncFailure(403);
            if (!is_int($raw['transactionDate']??null) || $raw['transactionDate']<$from || $raw['transactionDate']>$until) throw new SyncFailure(422);
            $external=$raw['orderNumber']??null;
            if (!is_int($external) && !is_string($external)) throw new SyncFailure(422);
            $number=(string)$external;
            if (!isset($sources[$number]) || ($checkpoint['movements'][$number]??[])===false) continue;
            $movement=TrendyolSaleCommission::movement($raw,$sources[$number]);
            if ($movement===null || isset($checkpoint['movements'][$number][$movement['line']])
                || count($checkpoint['movements'][$number]??[])>=count($sources[$number]['products'])) {
                $checkpoint['movements'][$number]=false;
            } else $checkpoint['movements'][$number][$movement['line']]=$movement;
        }
        if ($page+1<$pages) {
            $checkpoint['page']=$page+1;
            $checkpoint['total']=$total;
            return [];
        }
        if ($until<$checkpoint['through']) {
            $checkpoint['from']=$until+1;
            $checkpoint['page']=0;
            $checkpoint['total']=null;
            return [];
        }
        $result=[];
        foreach ($sources as $number=>$order) {
            $entries=$checkpoint['movements'][$number]??null;
            if (!is_array($entries)) continue;
            $proof=TrendyolSaleCommission::evidence($order,$entries);
            if ($proof!==null) $result[$number]=$proof;
        }
        $checkpoint=null;
        return $result;
    }
}
