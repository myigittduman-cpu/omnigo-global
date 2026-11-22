<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads a bounded order batch through the existing marketplace clients. */
final class ProfitabilityCommissionSource
{
    public const PROVIDERS = ['hepsiburada'=>'h','idefix'=>'i','koctas'=>'k','n11'=>'n','pttavm'=>'t','pazarama'=>'p','trendyol'=>'y'];

    public function __construct(private ?N11ReadClient $n11Client = null) {}

    public function read(string $provider,array $settings,array $orders,\DateTimeImmutable $day,int $deadline,?array &$checkpoint=null): array
    {
        $numbers=array_fill_keys(array_column($orders,'order_number'),true);
        return match ($provider) {
            'hepsiburada'=>(new HepsiburadaProfitabilityLedger())->observe($settings,$numbers,$deadline,$checkpoint,
                fn()=>$this->hepsiburada($settings,$numbers,$deadline)),
            'idefix'=>$this->idefix($settings,$numbers,$day,$deadline),
            'koctas'=>$this->koctas($settings,$numbers,$deadline),
            'n11'=>$this->n11($settings,$numbers,$day,$deadline),
            'pttavm'=>$this->pttavm($settings,$numbers,$deadline),
            'pazarama'=>$this->pazarama($settings,$orders,$deadline),
            'trendyol'=>(new TrendyolProfitabilitySettlements())->read($settings,$orders,$day,$deadline,$checkpoint),
        };
    }

    private function hepsiburada(array $settings,array $numbers,int $deadline): array
    {
        $client=new HepsiburadaReadClient();
        $credentials=HepsiburadaCredentials::fromArray($settings);
        $result=[];
        foreach (array_keys($numbers) as $number) {
            $number=(string)$number;
            try { $raw=$client->order($credentials,$number,$deadline); }
            catch (SyncFailure $error) { if ($error->httpStatus===404) continue; throw $error; }
            $order=HepsiburadaOrderMapper::map($raw,$credentials->merchantId,$number)['order'];
            $result[$number]=ProfitabilityFinancialEvidence::hepsiburada($order,$raw['items']);
        }
        return array_filter($result);
    }

    private function pttavm(array $settings,array $numbers,int $deadline): array
    {
        $client=new MarketplaceReadClient();$result=[];
        foreach(array_keys($numbers) as $number){
            $raw=$client->pttavmDetail($settings,(string)$number,$deadline);
            $order=PttavmOrderMapper::map($raw)['order'];$quotes=[];
            foreach($raw['siparisUrunler'] as $line){
                $rate=self::rate($line['komisyon']??null);
                $base=self::decimal($line['kdvDahilToplamTutar']??null);
                $quotes[]=['cents'=>$rate!==null&&$base!==null?ProfitabilityCommission::amount($base,$rate):null,'rate'=>$rate];
            }
            $proof=self::proof($order,$quotes);
            if($proof!==null)$result[(string)$number]=[...$proof,'commissionSource'=>'marketplace_rate','checkedAt'=>gmdate('c')];
        }
        return $result;
    }

    private function pazarama(array $settings,array $orders,int $deadline): array
    {
        $credentials=PazaramaCredentials::fromArray($settings);
        $token=(new PazaramaAuthClient())->authenticate($credentials,$deadline)['accessToken'];
        PazaramaSellerIdentity::verify($token,$credentials->sellerId);
        $client=new FinanceClient();$result=[];
        foreach($orders as $saved){
            $order=$saved['sourceOrder']??null;
            if(!$order||(string)($order['externalId']??'')!==(string)$saved['external_id'])continue;
            $rows=$client->pazaramaOrder($token,(string)$saved['order_number'],$deadline);
            $proof=ProfitabilityFinancialEvidence::pazarama($order,$rows,(string)$saved['order_number']);
            if($proof!==null)$result[(string)$saved['order_number']]=$proof;
        }
        return $result;
    }

    private function idefix(array $settings,array $numbers,\DateTimeImmutable $day,int $deadline): array
    {
        $client=new MarketplaceReadClient();
        $packages=[];$quotes=[];
        $from=$day->format('Y-m-d').' 00:00:00';
        $to=$day->format('Y-m-d').' 23:59:59';
        for ($page=1;;$page++) {
            $data=$client->orders('idefix',$settings,$from,$to,$page,$deadline);
            foreach ($data['items'] as $raw) {
                $number=(string)($raw['orderNumber']??'');
                if (!isset($numbers[$number])) continue;
                $package=IdefixOrderMapper::map($raw)['order'];
                $packages[]=$package;
                if (!$package['orders']) continue;
                foreach ($raw['items'] as $index=>$line) {
                    $commission=ProfitabilityMarketplacePricing::idefixCommission($line,$package['products'][$index]);
                    $quotes[$number][]=['cents'=>$commission,'rate'=>null,'withholding'=>self::decimal($line['withholdingAmount']??null)];
                }
            }
            if ($data['complete']) break;
            if ($page>=15) throw new SyncFailure(422);
        }
        $result=[];
        foreach (IdefixOrderMapper::combine($packages) as $order) {
            $id=$order['externalId'];
            $lines=$quotes[$id]??[];$proof=self::proof($order,$lines);
            if($proof!==null)$proof['withholdingCents']=self::total($lines,'withholding');
            $result[$id]=$proof;
        }
        return array_filter($result);
    }

    private function koctas(array $settings,array $numbers,int $deadline): array
    {
        $client=new KoctasHttpClient($settings['apiKey']);
        $query=['shop_id'=>$settings['shopId'],'max'=>100,'order_tax_mode'=>'TAX_INCLUDED'];
        $result=[];
        foreach (array_chunk(array_keys($numbers),25) as $ids) {
            foreach ($ids as $id) if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/D',(string)$id)) throw new SyncFailure(422);
            $batch=array_fill_keys($ids,true);
            $data=$client->get('/api/orders',[...$query,'order_ids'=>implode(',',$ids)],$deadline);
            if (!is_array($data['orders']??null)||($data['total_count']??null)!==count($data['orders'])) throw new SyncFailure(422);
            foreach ($data['orders'] as $raw) {
                $id=$raw['order_id']??'';
                if (!isset($batch[$id])||array_key_exists($id,$result)) throw new SyncFailure(422);
                $order=KoctasOrderMapper::map($raw);
                $quotes=array_map(static function (array $line): array {
                    $fee=self::decimal($line['commission_fee']??null);
                    $vat=self::decimal($line['commission_vat']??null);
                    $total=self::decimal($line['total_commission']??null);
                    return ['cents'=>$fee!==null&&$vat!==null&&$total===$fee+$vat?$total:null,'rate'=>null,
                        'net'=>$fee,'vat'=>$vat,'pricing'=>ProfitabilityMarketplacePricing::koctas($line)];
                },$raw['order_lines']);
                $total=self::decimal($raw['total_commission']??null);
                $proof=$total!==null&&$total===self::total($quotes,'cents')?self::proof($order,$quotes):null;
                $pricing=ProfitabilityOrderPricing::sum(array_column($quotes,'pricing'));
                $lineIds=array_column($raw['order_lines'],'order_line_id');
                if(count($lineIds)!==count($quotes)||in_array('',array_map('strval',$lineIds),true)
                    ||count(array_unique($lineIds,SORT_STRING))!==count($quotes))continue;
                $linePricing=[];
                foreach($order['products'] as $index=>$product)
                    ProfitabilityOrderPricing::recordLine($linePricing,$product,$quotes[$index]['pricing']);
                $fees=$proof===null?[]:[...$proof,'commissionNetCents'=>self::total($quotes,'net'),'commissionTaxCents'=>self::total($quotes,'vat')];
                if($fees||$pricing!==ProfitabilityOrderPricing::UNKNOWN||ProfitabilityOrderPricing::hasLineEvidence($linePricing)){
                    $result[$id]=ProfitabilityFinancialEvidence::seal($order,[...$fees,'orderPricing'=>$pricing,'orderLinePricing'=>$linePricing,
                        'pricingFingerprint'=>ProfitabilityOrderPricing::fingerprint($order['products'])]);
                }
            }
        }
        return array_filter($result);
    }

    private function n11(array $settings,array $numbers,\DateTimeImmutable $day,int $deadline): array
    {
        $client=$this->n11Client ?? new N11ReadClient();
        $credentials=N11Credentials::fromArray($settings);
        $from=$day->getTimestamp()*1000;
        $to=$day->modify('+1 day')->getTimestamp()*1000-1;
        $packages=[];$quotes=[];$conflicts=[];$pageCount=null;
        for ($page=0;;$page++) {
            $data=$client->packages($credentials,$from,$to,$page,$deadline);
            if ($pageCount!==null&&$pageCount!==$data['totalPages']) throw new SyncFailure(409);
            $pageCount=$data['totalPages'];
            foreach ($data['content'] as $raw) {
                $number=(string)($raw['orderNumber']??'');
                if (!isset($numbers[$number])) continue;
                $package=N11SalesMapper::package($raw);
                $packages[]=$package;
                foreach ($package['products'] as $index=>$line) {
                    if ($package['status']==='Unpacked'||$line['status']==='Unpacked') continue;
                    $id=$line['lineId'];$rawLine=$raw['lines'][$index];
                    $pricing=ProfitabilityMarketplacePricing::n11($rawLine);
                    $rate=self::n11Rate($rawLine, $pricing, $line['invoiceCents'], $package['status']);
                    $quote=['cents'=>null,'rate'=>$rate,'pricing'=>$pricing,
                        'commissionDiscount'=>ProfitabilityMarketplacePricing::n11CommissionDiscount($rawLine),
                        'activeLine'=>['sku'=>$line['sku'],'title'=>$line['title'],'quantity'=>$line['quantity'],
                            'netQuantity'=>$line['quantity'],'grossCents'=>$line['invoiceCents'],'cancelCents'=>0,'returnCents'=>0]];
                    $quotes[$number]??=[];
                    if (!ProfitabilityMarketplacePricing::collectN11Quote($quotes[$number],(string)$id,$quote)) $conflicts[$number]=true;
                }
            }
            if ($page+1>=max(1,$pageCount)) break;
            if ($page>=14) throw new SyncFailure(422);
        }
        $result=[];
        foreach (N11SalesMapper::orders($packages,[],'1970-01-01T00:00:00Z','9999-12-31T23:59:59Z') as $order) {
            $id=$order['externalId'];
            $lines=array_values($quotes[$id]??[]);
            if (isset($conflicts[$id])) continue;
            $commission=self::n11Commission($order,$lines);
            $pricing=ProfitabilityOrderPricing::sum(array_column($lines,'pricing'));
            $discount=self::total($lines,'commissionDiscount');
            $linePricing=[];
            foreach($lines as $quote)ProfitabilityOrderPricing::recordLine($linePricing,$quote['activeLine'],$quote['pricing']);
            if (($discount===null&&$pricing===ProfitabilityOrderPricing::UNKNOWN&&!ProfitabilityOrderPricing::hasLineEvidence($linePricing))
                || count($lines)!==count($order['products'])) {
                $result[$id]=$commission;
                continue;
            }
            $result[$id]=ProfitabilityFinancialEvidence::seal($order,[...($commission??[]),'orderPricing'=>$pricing,'orderLinePricing'=>$linePricing,'commissionDiscountCents'=>$discount,
                'pricingFingerprint'=>ProfitabilityOrderPricing::fingerprint($order['products'])]);
        }
        return array_filter($result);
    }

    private static function n11Rate(array $line, array $pricing, int $invoiceCents, string $packageStatus): ?int
    {
        if (in_array($packageStatus, ['Cancelled','UnSupplied'], true)) return null;
        foreach (['currency','currencyCode'] as $key) {
            if (array_key_exists($key,$line) && !in_array($line[$key],['TRY','TL'],true)) return null;
        }
        foreach (['sellerCampaignCommissionRate','sellerCampaignCommissionDiscount'] as $key) {
            $value=$line[$key]??null;
            if ((!is_int($value)&&!is_float($value)&&!is_string($value))
                || !preg_match('/^0+(?:\.0+)?$/D',(string)$value)) return null;
        }
        $rawRate=$line['commissionRate']??null;
        if ((!is_int($rawRate)&&!is_float($rawRate)&&!is_string($rawRate))
            || !preg_match('/^\d{1,3}(?:\.\d{1,2}0*)?$/D',(string)$rawRate)) return null;
        if ($pricing['storePriceCents']!==null && $pricing['sellerDiscountCents']!==null
            && $pricing['storePriceCents']-$pricing['sellerDiscountCents']!==$invoiceCents) return null;
        return self::rate($rawRate);
    }

    private static function n11Commission(array $order, array $quotes): ?array
    {
        $rates=array_column($quotes,'rate');
        if (!$rates || in_array(null,$rates,true) || count($quotes)!==count($order['products'])) return null;
        // A single rate is rounded once on the order's total, as before.
        if (count(array_unique($rates))===1) return self::proof($order,$quotes);

        // Each invoiceCents is the full sellerInvoiceAmount for a unique n11 order line.
        // An effective rate is display-only. Reapplying it to the total loses cents.
        $bases=array_column($quotes,'activeLine');
        $totalBasis=self::total($bases,'grossCents');
        if ($totalBasis!==$order['grossCents'] || $totalBasis<=0) return null;
        $commission=0;
        foreach ($bases as $index=>$line) {
            $commission+=ProfitabilityCommission::amount($line['grossCents'],$rates[$index]);
        }
        return ProfitabilityFinancialEvidence::seal($order,[
            'commissionCents'=>$commission,
            'commissionRateBasisPoints'=>ProfitabilityCommission::share($commission,$totalBasis),
            'commissionSource'=>'marketplace_line_rates',
        ]);
    }

    private static function total(array $lines,string $field): ?int
    {
        if(!$lines)return null;
        $total=0;
        foreach($lines as $line){
            $amount=$line[$field]??null;
            if(!is_int($amount)||$amount<0||$amount>9007199254740991-$total)return null;
            $total+=$amount;
        }
        return $total;
    }

    private static function proof(array $order,array $quotes): ?array
    {
        $lines=$order['products'];$salesCents=$order['grossCents'];
        if (!$order['orders']||!$lines||$salesCents<=0||$order['cancelCents']||$order['returnCents']||count($lines)!==count($quotes)) return null;
        foreach ($lines as $line) if ($line['netQuantity']!==$line['quantity']||$line['cancelCents']||$line['returnCents']) return null;
        $orderedAt=strtotime($order['createdAt']);
        if (!$orderedAt) return null;
        $amounts=array_column($quotes,'cents');
        $rates=array_unique(array_column($quotes,'rate'),SORT_STRING);
        $commissionRateBasisPoints=count($rates)===1?$rates[0]:null;
        if (!in_array(null,$amounts,true)) {
            $commissionCents=array_sum($amounts);
            $commissionSource=$commissionRateBasisPoints===null?'marketplace_effective':'marketplace';
            $commissionRateBasisPoints??=ProfitabilityCommission::share($commissionCents,$salesCents);
        } elseif ($commissionRateBasisPoints!==null) {
            $commissionSource='marketplace_rate';
            $commissionCents=ProfitabilityCommission::amount($salesCents,$commissionRateBasisPoints);
        } else return null;
        $fingerprint=ProfitabilityCommission::fingerprint($lines);
        return [...compact('orderedAt','salesCents','fingerprint','commissionCents','commissionRateBasisPoints','commissionSource'),
            'pricingFingerprint'=>ProfitabilityOrderPricing::fingerprint($lines),'checkedAt'=>gmdate('c')];
    }

    private static function rate(mixed $value): ?int
    {
        $rate=self::decimal($value);
        return $rate!==null&&$rate<=10000?$rate:null;
    }

    private static function decimal(mixed $value): ?int
    {
        if ((!is_int($value)&&!is_float($value)&&!is_string($value))
            ||!preg_match('/^(\d{1,10})(?:\.(\d{1,8}))?$/D',(string)$value,$parts)) return null;
        $fraction=str_pad($parts[2]??'',3,'0');
        return (int)$parts[1]*100+(int)substr($fraction,0,2)+((int)$fraction[2]>=5?1:0);
    }
}
