<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxQuestionFilters
{
    public const PROVIDERS = [
        'hepsiburada'=>'Hepsiburada', 'koctas'=>'Koçtaş', 'pazarama'=>'Pazarama',
        'n11'=>'n11', 'idefix'=>'idefix', 'trendyol'=>'Trendyol',
    ];

    public static function metadata(mixed $sku=null, mixed $productCode=null, mixed $orderNumber=null): array
    {
        $values = ['sku'=>$sku, 'productCode'=>$productCode, 'orderNumber'=>$orderNumber];
        foreach ($values as $name=>$value) {
            $values[$name] = is_string($value) || is_int($value) ? DeliveryMapper::text((string)$value,180) : '';
        }
        return array_filter($values, static fn(string $value): bool => $value !== '');
    }

    public static function identifiers(array $thread): array
    {
        $fields = $thread['questionIdentifiers'] ?? [];
        $order = $fields['orderNumber'] ?? null;
        foreach ($thread['entities'] ?? [] as $entity) {
            if (($entity['type'] ?? null) === 'MMP_ORDER' && !$order) $order = $entity['id'] ?? null;
        }
        if (!$order && str_starts_with((string)($thread['id'] ?? ''),'order:')
            && preg_match('/^Sipariş ([^ ·]+) · /u',(string)($thread['title'] ?? ''),$match)) {
            $order = $match[1];
        }
        return self::metadata($fields['sku'] ?? null,$fields['productCode'] ?? null,$order);
    }

    public static function selection(Database $db, ?string $provider, string $search): array
    {
        $filters = '';
        $values = [];
        if ($provider !== null) {
            $filters .= ' AND t.provider=?';
            $values[] = $provider;
        }
        if ($search === '') return [$filters,$values];

        $pattern = '%'.strtr(mb_strtolower($search,'UTF-8'),['='=>'==','%'=>'=%','_'=>'=_']).'%';
        $field = static fn(string $path): string => $db->mysql()
            ? "JSON_UNQUOTE(JSON_EXTRACT(t.payload,'$path'))"
            : "JSON_EXTRACT(t.payload,'$path')";
        $fields = ['sku','productCode','orderNumber'];
        $matches = array_map(static fn(string $key): string =>
            "LOWER(".$field('$.questionIdentifiers.'.$key).") LIKE ? ESCAPE '='",$fields);
        array_push($values,...array_fill(0,count($matches),$pattern));

        // Existing Mirakl messages keep their order number in the source entities.
        for ($index=0; $index<8; $index++) {
            $type = $field('$.entities['.$index.'].type');
            $id = $field('$.entities['.$index.'].id');
            $matches[] = "(t.provider='koctas' AND $type='MMP_ORDER' AND LOWER($id) LIKE ? ESCAPE '=')";
            $values[] = $pattern;
        }
        $title = $field('$.title');
        $number = $db->mysql() ? "SUBSTRING_INDEX(SUBSTRING($title,9),' · ',1)"
            : "SUBSTR($title,9,INSTR($title,' · ')-9)";
        $matches[] = "(t.provider='idefix' AND t.remote_id LIKE 'order:%' AND LOWER($number) LIKE ? ESCAPE '=')";
        $values[] = $pattern;
        return [$filters.' AND ('.implode(' OR ',$matches).')',$values];
    }
}
