<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads the claim feeds whose rows carry a source creation date. */
final class NewReturnPage
{
    public function __construct(private ?HepsiburadaReadClient $hepsiburada=null) {}

    public function read(string $provider,array $settings,array &$cursor,int $deadline): array
    {
        $from=(int)$cursor['from'];
        $through=(int)$cursor['through'];
        $page=(int)($cursor['page']??1);
        if($page>1000)throw new SyncFailure(422);
        $cursor['page']=$page+1;
        return match($provider){
            'hepsiburada'=>$this->hepsiburada($settings,$cursor,$deadline),
            'n11'=>$this->n11($settings,$from,$through,$page,$deadline),
            'idefix'=>$this->idefix($settings,$from,$through,$page,$deadline),
            'koctas'=>$this->koctas($settings,$cursor,$deadline),
            'trendyol'=>$this->trendyol($settings,$from,$through,$page,$cursor,$deadline),
            default=>throw new SyncFailure(422),
        };
    }

    private function hepsiburada(array $settings,array &$cursor,int $deadline): array
    {
        $stream=(int)($cursor['stream']??0);
        $status=HepsiburadaClaims::STATUSES[$stream];
        $credentials=HepsiburadaCredentials::fromArray($settings);
        $offset=(int)($cursor['offset']??0);
        $result=($this->hepsiburada??new HepsiburadaReadClient())->claims($credentials,$status,$offset,
            $this->date($cursor['from'],'Y-m-d H:i'),$this->date($cursor['through']+60,'Y-m-d H:i'),$deadline);
        $items=[];
        foreach($result['items'] as $raw){
            $claim=HepsiburadaClaims::map($raw,$status,$credentials->merchantId);
            if(!HepsiburadaClaims::reportsReturn($claim))continue;
            $created=HepsiburadaClaims::date($raw['claimDate']??null);
            $items[]=[...ReturnRequestMapper::hepsiburada($claim),'createdAt'=>$created->format(DATE_ATOM)];
        }
        $cursor['offset']=$result['hasMore']?$offset+10:0;
        $cursor['stream']=$result['hasMore']?$stream:$stream+1;
        return [$items,$cursor['stream']===count(HepsiburadaClaims::STATUSES)];
    }

    private function n11(array $settings,int $from,int $through,int $page,int $deadline): array
    {
        $result=(new N11ReturnClient())->claims(N11Credentials::fromArray($settings),
            $this->date($from),$this->date($through),$page-1,$deadline);
        return [array_map([ReturnRequestMapper::class,'n11'],$result['claims']),$page>=max(1,$result['pageCount'])];
    }

    private function idefix(array $settings,int $from,int $through,int $page,int $deadline): array
    {
        $result=(new IdefixReturnClient())->page($settings,gmdate('c',$from),gmdate('c',$through),$page,$deadline);
        return [$result['requests'],$result['complete']];
    }

    private function koctas(array $settings,array &$cursor,int $deadline): array
    {
        $query=empty($cursor['token'])?['shop_id'=>$settings['shopId'],'limit'=>100,'sort'=>'date_created,ASC',
            'return_creation_date_from'=>gmdate('c',$cursor['from']),'return_creation_date_to'=>gmdate('c',$cursor['through'])]
            :['page_token'=>$cursor['token']];
        $result=KoctasReturnMapper::page((new KoctasHttpClient($settings['apiKey']))->get('/api/returns',$query,$deadline));
        if($result['next']!==null){
            $signature=hash('sha256',$result['next']);
            if(isset($cursor['tokens'][$signature]))throw new SyncFailure(422);
            $cursor['tokens'][$signature]=true;
            $cursor['token']=$result['next'];
        }
        return [$result['items'],$result['next']===null];
    }

    private function trendyol(array $settings,int $from,int $through,int $page,array &$cursor,int $deadline): array
    {
        $created=$cursor['createdOnly']??false;
        $result=(new TrendyolClaimClient())->page($settings,$from*1000,$through*1000+999,$page-1,$created,$deadline);
        $ids=array_fill_keys($result['ids'],true);
        if(array_intersect_key($ids,$cursor['claimIds']??[])||($cursor['total']??$result['total'])!==$result['total'])throw new SyncFailure(409);
        $cursor['claimIds']=($cursor['claimIds']??[])+$ids;
        $cursor['total']=$result['total'];
        if($result['complete']&&!$created){
            $cursor['createdOnly']=true;
            $cursor['page']=1;
            unset($cursor['claimIds'],$cursor['total']);
        }
        $items=$result['items'];
        foreach($items as &$item)unset($item['sourceUpdatedMs']);
        return [$items,$result['complete']&&$created];
    }

    private function date(int $epoch,string $format='Y-m-d'): string
    {
        return (new \DateTimeImmutable('@'.$epoch))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format($format);
    }
}
