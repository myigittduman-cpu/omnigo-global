<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent, read-only reporting snapshots. Manual payments are never written here. */
final class UpcomingPayoutStore
{
    private const PILOT = 'VİZYONA';

    public function __construct(private Database $db) {}

    public function pilot(): ?int
    {
        $rows = $this->db->all('SELECT id, name FROM companies WHERE name=?', [self::PILOT]);
        $matches = array_values(array_filter($rows, static fn(array $row): bool => $row['name'] === self::PILOT));
        return count($matches) === 1 ? (int)$matches[0]['id'] : null;
    }

    public function connection(int $company, string $provider = 'n11'): ?string
    {
        $row = $this->db->one('SELECT id, version FROM records WHERE company_id=? AND kind=?', [$company,'integration_'.$provider]);
        return $row ? $row['id'].':'.$row['version'] : null;
    }

    public function rows(int $company, array $channels, string $today, ?int $now = null): array
    {
        if (!PazaramaCommissionStore::available($this->db)) return [];
        $pilot = $company === $this->pilot();
        $providers = UpcomingPayoutAdapter::JOBS + ($pilot ? UpcomingPayoutSource::PANEL_CHANNELS : []);
        $now ??= time();
        $finance = new FinanceStore($this->db);
        $rows = [];
        foreach (array_keys($providers) as $provider) {
            $channel = CustomerMarketplaces::PROVIDERS[$provider];
            if (!in_array($channel,$channels,true)) continue;
            $snapshot = $finance->state($company,UpcomingPayoutAdapter::stateKey($provider));
            $state = json_decode($snapshot['payload'] ?? '{}',true) ?: [];
            $published = $state['published'] ?? null;
            $updated = (int)($published['checkedAt'] ?? 0);
            $connection = $this->connection($company,$provider);
            if(!$connection)continue;
            $automatic=($published['connection']??null)===$connection?$updated:0;
            $days=$this->fresh($automatic,$now)?($published['days']??[]):[];
            $observed=[];
            $manual=$finance->state($company,NewPayoutRecords::stateKey($provider));
            $supplement=json_decode($manual['payload']??'{}',true)?:[];
            $complete=$supplement['fullPublished']??null;
            if($complete&&($complete['connection']??null)===$connection&&$complete['requestedAt']>$automatic
                &&$this->fresh($complete['checkedAt'],$now)){
                $days=array_diff_key($complete['days'],NewPayoutRecords::removedDates($this->db,$company,$channel,$today,$complete['requestedAt']));
                $updated=$automatic=$complete['checkedAt'];
            }
            $newDates=$supplement['newDates']??[];
            $removed=$newDates?NewPayoutRecords::removedDates($this->db,$company,$channel,$today):[];
            foreach($newDates as $day=>$entry){
                $at=(int)($entry['observedAt']??0);
                if(!isset($removed[$day])&&!array_key_exists($day,$days)&&($entry['connection']??null)===$connection
                    &&$at>$automatic&&$this->fresh($at,$now)){
                    $days[$day]=$entry['amount'];
                    $observed[$day]=$at;
                }
            }
            foreach ($days as $day => $amount) {
                if (is_int($amount) && $amount >= 0 && $day >= $today) {
                    $rows[] = ['id'=>$provider.':'.$day, 'channel'=>$channel, 'date'=>$day, 'amountCents'=>$amount,
                        'verification'=>$provider === 'n11' ? 'confirmed' : 'estimated',
                        'source'=>'marketplace', 'observedAt'=>$observed[$day]??$updated,
                        ...(!$pilot ? ['sourceConnection'=>$connection] : [])];
                }
            }
        }
        return $rows;
    }

    private function fresh(int $at,int $now): bool
    {
        return $at>0&&$at<=$now&&$now-$at<=2*UpcomingPayoutAdapter::REFRESH_INTERVAL;
    }
}
