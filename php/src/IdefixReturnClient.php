<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only pagination: approved financial lines and separate display projections. */
final class IdefixReturnClient
{
    public function __construct(private ?QuestionReadTransport $transport=null){}
    public function page(array $config,string $from,string $to,int $page,int $deadline): array
    {
        if($page<1||$page>1000)throw new SyncFailure(422);
        $zone=new \DateTimeZone('Europe/Istanbul');
        $query=['startDate'=>(new \DateTimeImmutable($from))->setTimezone($zone)->format('Y/m/d H:i:s'),'endDate'=>(new \DateTimeImmutable($to))->setTimezone($zone)->format('Y/m/d H:i:s'),'page'=>$page,'limit'=>50];
        $url='https://merchantapi.idefix.com/oms/'.rawurlencode($config['vendorId']).'/claim-list?'.http_build_query($query);
        $body=($this->transport??new QuestionReadTransport())->read($url,['Accept: application/json','X-API-KEY: '.base64_encode($config['apiKey'].':'.$config['apiSecret'])],$deadline);
        try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        if(!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])>50||!is_int($data['totalCount']??null)||$data['totalCount']<0||!is_int($data['pageCount']??null)||$data['pageCount']<0||$data['pageCount']>1000||(int)($data['currentPage']??0)!==$page)throw new SyncFailure(422);
        $claims=[];$requests=[];
        foreach($data['items'] as $claim){
            if(!is_array($claim['items']??null)||!array_is_list($claim['items'])||count($claim['items'])>1000)throw new SyncFailure(422);
            foreach($claim['items'] as $line){
                if(!is_string($line['state']??null))throw new SyncFailure(422);
                $requests[]=ReturnRequestMapper::idefix($claim,$line);
                if($line['state']!=='approved')continue;
                $id=(string)($line['id']??'');$lineId=(string)($line['orderLineId']??'');
                if(!ctype_digit($id)||!ctype_digit($lineId))throw new SyncFailure(422);
                $claims[]=['id'=>$id,'orderNumber'=>PazaramaSalesMapper::text($claim['orderNumber']??null,140),'lineId'=>$lineId,'amountCents'=>KoctasOrderMapper::cents($line['discountedTotalPrice']??null)];
            }
        }
        return ['claims'=>$claims,'requests'=>$requests,'count'=>count($data['items']),'total'=>$data['totalCount'],'pages'=>$data['pageCount'],'complete'=>$page>=max(1,$data['pageCount'])];
    }
}
