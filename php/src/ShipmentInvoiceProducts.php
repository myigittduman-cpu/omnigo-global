<?php
declare(strict_types=1);
namespace Omnigo;

/** Read only verified product references from the existing order and catalog transfers. */
final class ShipmentInvoiceProducts
{
    public function __construct(private Database $db) {}

    public function identities(int $company, array $rows): array
    {
        $references = [];
        foreach ($rows as $lines) foreach ($lines as [$provider,$line]) {
            $evidence = ShipmentSourceProduct::stored($provider,$line);
            foreach (['listingId','catalogId'] as $field) {
                if (isset($evidence[$field])) $references[$provider][$evidence[$field]] = true;
            }
        }
        $catalog = $this->catalog($company,$references);
        $identities = [];
        foreach ($rows as $id=>$lines) {
            $proof = [];
            foreach ($lines as [$provider,$line]) {
                $evidence = ShipmentSourceProduct::stored($provider,$line);
                if (!$evidence) continue;
                $key = self::identity($provider,$line,$evidence,$catalog[$provider] ?? []);
                if ($key === null) continue 2;
                $proof[$key] = true;
            }
            if (count($proof)===1) $identities[$id] = (string)array_key_first($proof);
        }
        return $identities;
    }

    private function catalog(int $company, array $references): array
    {
        if (!$references || !$this->db->one($this->db->mysql()
            ? "SHOW TABLES LIKE 'inventory_listings'"
            : "SELECT 1 FROM sqlite_master WHERE type='table' AND name='inventory_listings'")) return [];
        $catalog = [];
        $catalogId = $this->db->mysql()
            ? "JSON_UNQUOTE(JSON_EXTRACT(evidence,'$.invoiceCatalogId'))"
            : "json_extract(evidence,'$.invoiceCatalogId')";
        $oldId = str_replace('invoiceCatalogId','sourceCatalogId',$catalogId);
        foreach ($references as $provider=>$ids) foreach (array_chunk(array_keys($ids),100) as $batch) {
            $idsText = array_map('strval',$batch);
            $binds = implode(',',array_fill(0,count($batch),'?'));
            $rows = $this->db->all("SELECT remote_id,evidence FROM inventory_listings
                WHERE company_id=? AND provider=? AND remote_id NOT LIKE 'order-sku:%'
                  AND (listing_key IN ($binds) OR COALESCE(NULLIF($catalogId,''),$oldId) IN ($binds))",
                [$company,$provider,...array_map(static fn($id)=>hash('sha256',$id),$idsText),...$idsText]);
            foreach ($rows as $row) {
                $source = json_decode($row['evidence'],true,32);
                if (!is_array($source)) continue;
                $id = $row['remote_id'];
                $fields = ShipmentSourceProduct::normalize([
                    'barcode'=>($source['barcode'] ?? '') ?: ($source['metadataBarcode'] ?? ''),
                    'model'=>($source['invoiceModel'] ?? '') ?: ($source['model'] ?? null),
                ]);
                $catalog[$provider]['listing'][$id] = $fields;
                $product = ($source['invoiceCatalogId'] ?? '') ?: ($source['sourceCatalogId'] ?? '');
                if ($product!=='') $catalog[$provider]['product'][$product][$id] = $fields;
            }
        }
        return $catalog;
    }

    private static function identity(string $provider,array $line,array $evidence,array $catalog): ?string
    {
        $listing = $evidence['listingId'] ?? '';
        $product = $evidence['catalogId'] ?? '';
        $barcode = $evidence['barcode'] ?? '';
        $variant = $evidence['variantId'] ?? '';
        $model = $evidence['model'] ?? '';
        if ($listing==='' && $product==='' && $barcode==='' && $variant==='') return null;
        $matches = isset($catalog['listing'][$listing]) ? [$catalog['listing'][$listing]]
            : (isset($catalog['listing'][$product]) ? [$catalog['listing'][$product]] : ($catalog['product'][$product] ?? []));
        $barcodes = $barcode!=='' ? [$barcode=>true] : [];
        $models = $model!=='' ? [$model=>true] : [];
        foreach ($matches as $match) {
            if (isset($match['barcode'])) $barcodes[$match['barcode']] = true;
            if (isset($match['model'])) $models[$match['model']] = true;
        }
        if (count($barcodes)>1 || count($models)>1) return null;
        $barcode = (string)(array_key_first($barcodes) ?? '');
        $model = (string)(array_key_first($models) ?? '');
        $sourceTitle = $line['title'] ?? '';
        $title = InventoryProductMatch::name($sourceTitle) ?: InventoryLiveSkuIdentity::normalize($sourceTitle);
        // Group-only catalogue IDs and a repeated seller SKU are insufficient for a variant.
        $identity = $barcode!=='' ? ['barcode',$barcode] : ['listing',$listing,$product];
        return hash('sha256',json_encode([$provider,$identity,$variant,$model,$barcode!==''&&$model!==''?'':$title],JSON_THROW_ON_ERROR));
    }
}
