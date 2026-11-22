<?php
declare(strict_types=1);
namespace Omnigo;

/** Look up an exact question product in this store's own Pazarama order catalog. */
final class InboxPazaramaOrderPhotos
{
    // The barcode/model pair is confirmed by the store's listing. The Pazarama CDN rejects this legacy image.
    private const LOCAL_MODELS = ['4242006322809'=>'Profilo BMS623V1'];

    public function __construct(private Database $db, private ?InboxQuestionPhotoCatalog $catalog = null) {}

    public function find(int $company, string $title, array $expectedSkus): string
    {
        if ($title === '' || str_contains($title, ' · ')) return '';
        if ($this->db->mysql()) {
            $pattern = strtr($title, ['!'=>'!!','%'=>'!%','_'=>'!_']);
            $matches = $this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='order_pazarama'
                AND JSON_SEARCH(payload, 'one', ?, '!', '$.products[*].title') IS NOT NULL LIMIT 1001", [$company,$pattern]);
        } else {
            $matches = $this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='order_pazarama'
                AND EXISTS (SELECT 1 FROM json_each(payload, '$.products') p WHERE json_extract(p.value, '$.title')=?) LIMIT 1001", [$company,$title]);
        }
        if (count($matches)>1000) return '';
        $skus = $images = [];
        foreach ($matches as $row) {
            $order = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
            foreach ($order['products'] ?? [] as $product) {
                if (($product['title'] ?? null) !== $title) continue;
                $sku = ShipmentProductCode::normalize($product['sku'] ?? null);
                if ($sku === '' || ($expectedSkus && !in_array($sku, $expectedSkus, true))) return '';
                $skus[$sku] = true;
                $raw = KoctasProductMapper::imageUrl($product['imageUrl'] ?? null);
                $url = $raw && parse_url($raw,PHP_URL_HOST)==='img.pzrmcdn.com' ? $raw : '';
                if ($url !== '') $images[$url] = true;
                if (count($skus)>1 || count($images)>1) return '';
            }
        }
        if (count($skus)!==1 || count($images)!==1) return '';
        $url = (string)array_key_first($images);
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if (preg_match('~^/(?:mnresize/[1-9][0-9]{1,3}/[1-9][0-9]{1,3}/)?asset/([0-9]{13})/images/[A-Za-z0-9][A-Za-z0-9_.-]{1,210}\.(?:jpe?g|png|webp)$~iD', $path, $match)
            && isset(self::LOCAL_MODELS[$match[1]])) {
            $this->catalog ??= new InboxQuestionPhotoCatalog();
            $local = $this->catalog->url(self::LOCAL_MODELS[$match[1]]);
            if ($local !== '') return $local;
        }
        return $url;
    }
}
