<?php
declare(strict_types=1);
namespace Omnigo;

/** Recover optional historic prices only for an exact marketplace order and line. */
final class ShipmentPaymentSnapshots
{
    private const RECORDS = ['idefix'=>'order_idefix', 'Pazarama'=>'order_pazarama'];
    private const RECENT = ['PTT AVM'=>'pttavm', 'Trendyol'=>'trendyol'];

    public function __construct(private Database $db) {}

    public function read(int $company, array $links): array
    {
        $lookups = [];
        foreach ($links as $references) foreach ($references as $reference) {
            [$provider, $number, $line]=$reference;
            if (isset(self::RECORDS[$provider]) || isset(self::RECENT[$provider])) $lookups[$provider][$number][$line] = $reference[3]??true;
        }
        $found = [];
        foreach ($lookups as $provider => $orders) {
            foreach (array_chunk(array_keys($orders), 150) as $batch) {
                foreach ($this->orders($company, $provider, $batch) as $row) {
                    $order = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
                    $number = $order['externalId'];
                    foreach ($order['products'] ?? [] as $line) {
                        $id = (string)($line['lineId'] ?? '');
                        if (!isset($orders[$number][$id]) || ($provider==='Pazarama'&&is_int($orders[$number][$id])&&$orders[$number][$id]!==($line['quantity']??null))) continue;
                        $amounts = ShipmentPaymentAmounts::pricing($line['pricing'] ?? null);
                        if ($provider === 'Pazarama') {
                            $paid = PazaramaInvoiceAmount::fromPricing($line['pricing'] ?? null, $line['grossCents'] ?? null);
                            unset($amounts['netProductCents']);
                            if ($paid !== null) $amounts['netProductCents'] = $paid;
                        }
                        if (!$amounts) continue;
                        $found[$provider][$number][$id][json_encode($amounts, JSON_THROW_ON_ERROR)] = $amounts;
                    }
                }
            }
        }
        $result = [];
        foreach ($links as $id => $references) {
            $matches = [];
            foreach ($references as [$provider, $number, $line]) {
                $matches += $found[$provider][$number][$line] ?? [];
            }
            if (count($matches) === 1) $result[$id] = reset($matches);
        }
        return $result;
    }

    private function orders(int $company, string $provider, array $numbers): iterable
    {
        $in = implode(',', array_fill(0, count($numbers), '?'));
        if (isset(self::RECENT[$provider])) {
            if (!RecentOrderSchema::available($this->db)) return [];
            return $this->db->run("SELECT payload FROM recent_orders WHERE company_id=? AND provider=? AND external_id IN ($in)",
                [$company, self::RECENT[$provider], ...$numbers]);
        }
        $expression = $this->db->mysql() ? "JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))" : "JSON_EXTRACT(payload,'$.externalId')";
        return $this->db->run("SELECT payload FROM records WHERE company_id=? AND kind=? AND $expression IN ($in)",
            [$company, self::RECORDS[$provider], ...array_map('strval', $numbers)]);
    }
}
