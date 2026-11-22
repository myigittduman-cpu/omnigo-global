<?php
declare(strict_types=1);
namespace Omnigo;

/** Reports recorded statement lines without treating payout projections as expenses. */
final class FinanceDeductionsReport
{
    private const SECTIONS = ['commission', 'service', 'tax', 'adjustment'];
    private const TAX_BASES = ['included', 'excluded', 'mixed', 'unspecified'];
    // Expense labels from the existing marketplace statement form. Other lines remain visible.
    private const SUBTOTAL_LABELS = [
        'commission' => ['komisyon kesintisi', 'komisyon tutarı', 'komisyon tutar', 'komisyon ücretleri'],
        'service' => ['hizmet bedeli', 'tahsilat yönetim bedeli', 'kargo bedeli', 'pazarlama bedeli',
            'pazaryeri bedeli', 'ödeme altyapısı bedeli', 'kargo/desi farkı', 'kargo faturası',
            'hizmet ücreti', 'erken ödeme bedeli', 'entegrasyon tutarı', 'abonelik'],
        'tax' => ['e-ticaret stopajı', 'stopaj', 'stopaj tutarı', 'vergi kesintisi'],
        'adjustment' => ['ceza bedeli', 'ceza kesintisi', 'tedarik edilemedi bedeli', 'geciken kargo bedeli',
            'kusurlu/ayıplı/eksik ürün gönderimi', 'marka fatura belge uyuşmazlığı', 'kargo bilgi eksikliği',
            'platform rekabet ihlali', 'eksik fatura'],
    ];

    public function __construct(private Database $db) {}

    public function read(array $user, int $company, array $filters): array
    {
        Security::admin($user);
        $from = $filters['from'];
        $to = $filters['to'];
        $channel = $filters['channel'];
        ReportPeriod::range($from, $to);
        MarketplaceVisibility::requireVisible($channel);
        $page = self::pageNumber($filters['page'] ?? 1);
        $pageSize = self::pageNumber($filters['pageSize'] ?? 20);
        if (!in_array($pageSize, [10, 20, 50], true)) Http::fail(400, 'Sayfa boyutu geçersiz.');

        $repo = new Repository($this->db);
        $registered = $repo->records($company, 'channels', $user);
        $marketplaces = array_values(array_unique(array_column(
            MarketplaceVisibility::rows(CustomerMarketplaces::reportingChannels($this->db, $company, $registered), 'name'), 'name'
        )));
        $selected = $channel === 'all' ? $marketplaces : array_values(array_intersect($marketplaces, [$channel]));
        $records = [];
        foreach ($repo->records($company, 'payments', $user) as $payment) {
            if (!in_array($payment['channel'] ?? null, $selected, true)) continue;
            $record = self::statement($payment, $from, $to);
            if ($record !== null) $records[] = $record;
        }
        $overlaps = self::overlappingStatements($records);
        $official = in_array('Pazarama', $selected, true)
            ? (new PazaramaCommissionStore($this->db))->report($company, $from, $to)
            : ['coverage' => 'unavailable', 'items' => []];
        $officialItems = self::officialItems($official['items']);
        $officialCoverage = $official['coverage'];
        $officialTotal = ($official['reconciled'] ?? true) && $officialItems
            ? array_sum(array_column($officialItems, 'amountCents'))
            : ($officialCoverage === 'complete' ? 0 : null);
        $byChannel = array_fill_keys($selected, []);
        $manualItems = [];
        foreach ($records as $record) {
            foreach ($record['lines'] as $line) {
                $item = [...$line, ...$record['metadata']];
                $coveredByOfficial = self::overlapsOfficialCommission($item, $official);
                $reason = self::exclusion($item, $from, $to, isset($overlaps[$item['paymentId']]), $coveredByOfficial);
                $item['includedInSubtotal'] = $reason === null;
                $item['subtotalExclusion'] = $reason;
                $manualItems[] = $item;
                $byChannel[$item['channel']][] = $item;
            }
        }
        $items = [...$manualItems, ...$officialItems];
        usort($items, static fn(array $a, array $b): int =>
            strcmp($b['date'], $a['date']) ?: strcmp((string)$b['paymentId'], (string)$a['paymentId']) ?: ($a['index'] <=> $b['index'])
        );
        $channels = [];
        foreach ($byChannel as $name => $lines) {
            $channelSummary = self::summarize($lines);
            $hasOfficial = $name === 'Pazarama' && $officialCoverage !== 'unavailable';
            $channelSummary['officialCoverage'] = $hasOfficial ? $officialCoverage : 'unavailable';
            $channelSummary['officialCommissionCents'] = $hasOfficial ? $officialTotal : null;
            $channelSummary['officialReconciled'] = $hasOfficial ? ($official['reconciled'] ?? true) : null;
            $channelSummary['officialRecordCount'] = $hasOfficial ? count($officialItems) : 0;
            if ($hasOfficial) {
                $channelSummary['source'] = $lines ? 'mixed' : 'marketplace_api';
                $channelSummary['coverage'] = 'partial';
            }
            $channels[] = ['channel' => $name, ...$channelSummary];
        }
        $summary = self::summarize($manualItems);
        $officialSummary = [
            'channel' => 'Pazarama', 'coverage' => $officialCoverage, 'commissionCents' => $officialTotal,
            'reconciled' => $official['reconciled'] ?? null,
            'coverageFrom' => $official['coverageFrom'] ?? null, 'coverageTo' => $official['coverageTo'] ?? null,
            'updatedAt' => $official['updatedAt'] ?? null, 'recordCount' => count($officialItems),
        ];
        $totalItems = count($items);
        $page = min($page, max(1, (int)ceil($totalItems / $pageSize)));
        $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
        $source = $officialCoverage === 'unavailable' ? $summary['source'] : ($manualItems ? 'mixed' : 'marketplace_api');
        return compact('from', 'to', 'channel', 'marketplaces', 'summary', 'channels', 'items',
            'page', 'pageSize', 'totalItems', 'source', 'officialCoverage', 'officialSummary');
    }

    private static function statement(array $payment, string $from, string $to): ?array
    {
        $periodStart = self::storedDate($payment['periodStart'] ?? null);
        $periodEnd = self::storedDate($payment['periodEnd'] ?? null);
        $expectedDate = self::storedDate($payment['expectedDate'] ?? null);
        $paidDate = self::storedDate($payment['paidDate'] ?? null);
        if ($periodStart !== '' && $periodEnd !== '' && $periodStart <= $periodEnd) {
            if ($periodEnd < $from || $periodStart > $to) return null;
            $date = $periodEnd;
            $dateBasis = 'statement';
        } else {
            $periodStart = $periodEnd = '';
            $date = $expectedDate !== '' ? $expectedDate : $paidDate;
            if ($date === '' || $date < $from || $date > $to) return null;
            $dateBasis = $expectedDate !== '' ? 'expected' : 'paid';
        }
        $verificationSource = self::storedText($payment['verificationSource'] ?? null);
        $verification = ($payment['verification'] ?? null) === 'confirmed' && $verificationSource !== '' ? 'confirmed' : 'estimated';
        $taxBasis = in_array($payment['taxBasis'] ?? null, self::TAX_BASES, true) ? $payment['taxBasis'] : 'unspecified';
        $metadata = [
            'paymentId' => $payment['id'], 'channel' => $payment['channel'],
            'source' => 'manual', 'sourceLabel' => self::storedText($payment['source'] ?? null),
            'reference' => self::storedText($payment['reference'] ?? null),
            ...compact('periodStart', 'periodEnd', 'date', 'dateBasis', 'taxBasis', 'verification', 'verificationSource'),
        ];
        $lines = [];
        foreach ((array)($payment['details'] ?? []) as $index => $line) {
            if (!is_array($line) || !in_array($line['section'] ?? null, self::SECTIONS, true)) continue;
            $label = self::storedText($line['label'] ?? null);
            $amount = $line['amountCents'] ?? null;
            $rate = $line['rateBasisPoints'] ?? null;
            $amountCents = is_int($amount) && abs($amount) <= 100000000000 ? $amount : null;
            $rateBasisPoints = is_int($rate) && $rate >= 0 && $rate <= 10000 ? $rate : null;
            if ($label === '' || ($amountCents === null && $rateBasisPoints === null)) continue;
            $lines[] = ['section' => $line['section'], ...compact('index', 'label', 'amountCents', 'rateBasisPoints')];
        }
        return $lines ? compact('metadata', 'lines') : null;
    }

    private static function overlappingStatements(array $records): array
    {
        $byChannel = [];
        foreach ($records as $record) {
            $meta = $record['metadata'];
            if ($meta['dateBasis'] !== 'statement') continue;
            foreach ($record['lines'] as $line) {
                if ($line['amountCents'] !== null && self::knownExpense($line)) {
                    $byChannel[$meta['channel']][] = $meta;
                    break;
                }
            }
        }
        $overlaps = [];
        foreach ($byChannel as $statements) {
            usort($statements, static fn(array $a, array $b): int => strcmp($a['periodStart'], $b['periodStart']));
            $farthest = null;
            foreach ($statements as $statement) {
                if ($farthest !== null && $statement['periodStart'] <= $farthest['periodEnd']) {
                    $overlaps[$farthest['paymentId']] = $overlaps[$statement['paymentId']] = true;
                }
                if ($farthest === null || $statement['periodEnd'] > $farthest['periodEnd']) $farthest = $statement;
            }
        }
        return $overlaps;
    }

    private static function exclusion(array $item, string $from, string $to, bool $overlaps, bool $hasOfficial): ?string
    {
        if (!self::knownExpense($item)) return 'reference_only';
        if ($item['amountCents'] === null) return 'missing_amount';
        if ($hasOfficial) return 'separate_source';
        if ($item['verification'] !== 'confirmed') return 'unverified';
        if ($item['dateBasis'] !== 'statement') return 'no_statement_period';
        if ($item['periodStart'] < $from || $item['periodEnd'] > $to) return 'partial_period';
        if ($overlaps) return 'overlap';
        if (!in_array($item['taxBasis'], ['included', 'excluded'], true)) return 'tax_basis';
        return null;
    }

    private static function overlapsOfficialCommission(array $item, array $official): bool
    {
        if ($item['channel'] !== 'Pazarama' || $item['section'] !== 'commission' || $official['coverage'] === 'unavailable') {
            return false;
        }
        $from = $official['coverageFrom'] ?? null;
        $to = $official['coverageTo'] ?? null;
        if ($from === null || $to === null || $item['dateBasis'] !== 'statement') return true;
        return $item['periodEnd'] >= $from && $item['periodStart'] <= $to;
    }

    private static function officialItems(array $entries): array
    {
        return array_map(static fn(array $entry): array => [
            'paymentId' => $entry['id'], 'index' => 0, 'channel' => 'Pazarama', 'section' => 'commission',
            'label' => 'Günlük komisyon hareketleri', 'amountCents' => $entry['amountCents'], 'rateBasisPoints' => null,
            'periodStart' => '', 'periodEnd' => '', 'date' => $entry['date'], 'dateBasis' => 'transaction',
            'taxBasis' => $entry['taxBasis'] ?? 'unspecified', 'reference' => $entry['reference'] ?? '',
            'source' => 'marketplace_api', 'sourceLabel' => 'Pazarama resmi finans servisi',
            'verification' => ($entry['reconciled'] ?? true) ? 'confirmed' : 'source_total_unconfirmed',
            'verificationSource' => 'Pazarama resmi finans servisi',
            'includedInSubtotal' => false, 'subtotalExclusion' => 'separate_source',
        ], $entries);
    }

    private static function summarize(array $items): array
    {
        $totals = array_fill_keys(self::SECTIONS, []);
        $all = [];
        $records = [];
        foreach ($items as $item) {
            $records[$item['paymentId']] = true;
            if (!$item['includedInSubtotal']) continue;
            $section = $item['section'];
            $basis = $item['taxBasis'];
            $amount = $item['amountCents'];
            $totals[$section][$basis] = ($totals[$section][$basis] ?? 0) + $amount;
            $all[$basis] = ($all[$basis] ?? 0) + $amount;
        }
        $summary = [
            'coverage' => $items ? 'partial' : 'unavailable',
            'source' => $items ? 'manual' : 'unavailable',
            'officialCoverage' => 'unavailable',
            'recordCount' => count($records),
            'totalCents' => count($all) === 1 ? reset($all) : null,
            'taxBasis' => count($all) === 1 ? array_key_first($all) : ($all ? 'mixed' : 'unspecified'),
        ];
        foreach ($totals as $section => $amounts) {
            $summary[$section.'Cents'] = count($amounts) === 1 ? reset($amounts) : null;
        }
        return $summary;
    }

    private static function knownExpense(array $line): bool
    {
        $label = mb_strtolower(str_replace('İ', 'i', trim(preg_replace('/\s+/u', ' ', $line['label']))), 'UTF-8');
        return in_array($label, self::SUBTOTAL_LABELS[$line['section']], true);
    }

    private static function storedText(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function storedDate(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) return '';
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) ? $value : '';
    }

    private static function pageNumber(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) Http::fail(400, 'Sayfa numarası geçersiz.');
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) Http::fail(400, 'Sayfa numarası geçersiz.');
        return $number;
    }
}
