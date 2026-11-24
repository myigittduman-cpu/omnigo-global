<?php
declare(strict_types=1);
namespace Omnigo;

/** Brand labels for the visible catalogue use the same product identity as the stock list. */
final class InventoryProductBrands
{
    public const UNKNOWN = 'unknown';
    private const PREFIX = 'b:';
    private const LABEL = "COALESCE(NULLIF(TRIM(file_manual.brand),''),file_catalog.brand_label)";

    public function __construct(private Database $db) {}

    public static function selection(mixed $value): string
    {
        if (is_string($value) && ($value === '' || $value === self::UNKNOWN
            || preg_match('/^b:.{1,180}$/usD',$value) === 1)) return $value;
        Http::fail(400,'Marka filtresini kontrol edin.');
    }

    public function options(int $company,string $where,array $parameters): array
    {
        $brandKey = $this->key(self::LABEL);
        $rows = $this->db->all('SELECT '.$brandKey.' AS brand_key, MIN('.self::LABEL.') AS brand_label
            FROM inventory_products p '.$this->joins().' WHERE '.$where.'
            GROUP BY brand_key ORDER BY brand_key IS NULL,brand_label',[$company,...$parameters]);
        return array_map(static fn(array $row): array => $row['brand_key'] === null
            ? ['value'=>self::UNKNOWN,'label'=>'Markası belirsiz']
            : ['value'=>self::PREFIX.$row['brand_key'],'label'=>$row['brand_label']],$rows);
    }

    public function joins(): string
    {
        $label = "COALESCE(".$this->evidenceText('brand').",".$this->evidenceText('imageBrand').")";
        $key = $this->key('source.brand_label');
        return " LEFT JOIN inventory_manual_products file_manual
                ON file_manual.company_id=p.company_id AND file_manual.product_key=p.product_key
            LEFT JOIN (
                SELECT source.product_key,MIN(source.brand_label) AS brand_label
                FROM (
                    SELECT l.product_key,$label AS brand_label
                    FROM inventory_listings l WHERE l.company_id=? AND l.remote_id NOT LIKE 'order-sku:%'
                ) source
                WHERE source.brand_label IS NOT NULL
                GROUP BY source.product_key
                HAVING COUNT(DISTINCT $key)=1
            ) file_catalog ON file_catalog.product_key=p.product_key";
    }

    public function filter(string $brand): array
    {
        if ($brand === self::UNKNOWN) return [self::LABEL.' IS NULL',[]];
        return [$this->key(self::LABEL).'='.$this->binary('?'),[substr($brand,strlen(self::PREFIX))]];
    }

    private function evidenceText(string $field): string
    {
        $path = "'$.$field'";
        $extract = "JSON_EXTRACT(l.evidence,$path)";
        $mysql = $this->db->mysql();
        $type = $mysql ? "JSON_TYPE($extract)='STRING'" : "JSON_TYPE(l.evidence,$path)='text'";
        $text = $mysql ? "JSON_UNQUOTE($extract)" : $extract;
        return "CASE WHEN JSON_VALID(l.evidence) THEN CASE WHEN $type
            THEN NULLIF(TRIM(SUBSTR(TRIM($text),1,180)),'') END END";
    }

    private function key(string $label): string
    {
        // Match the existing brand normalization and make it consistent in SQLite and MySQL.
        foreach (['I'=>'i','İ'=>'i','ı'=>'i','Ç'=>'ç','Ğ'=>'ğ','Ö'=>'ö','Ş'=>'ş','Ü'=>'ü'] as $from=>$to) {
            $label = "REPLACE($label,'$from','$to')";
        }
        return $this->binary("LOWER($label)");
    }

    private function binary(string $value): string
    {
        return $this->db->mysql() ? "CAST($value AS BINARY)" : "CAST($value AS BLOB)";
    }
}
