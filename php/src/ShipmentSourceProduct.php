<?php
declare(strict_types=1);
namespace Omnigo;

/** Retain the marketplace's product fields alongside its exact, encrypted order line. */
final class ShipmentSourceProduct
{
    public static function read(string $provider, array $line): array
    {
        $product = $provider === 'Pazarama' && is_array($line['product'] ?? null) ? $line['product'] : $line;
        $fields = match ($provider) {
            'Hepsiburada' => ['listingId'=>$line['listingId'] ?? null, 'catalogId'=>$line['hepsiburadaSku'] ?? $line['sku'] ?? null,
                'barcode'=>$line['productBarcode'] ?? null],
            'n11' => ['listingId'=>$line['productId'] ?? null, 'variantId'=>$line['stockKeepingUnitId'] ?? null,
                'barcode'=>$line['barcode'] ?? null],
            'Koçtaş' => ['listingId'=>$line['offer_id'] ?? null, 'catalogId'=>$line['product_sku'] ?? null],
            'Pazarama' => ['listingId'=>$product['code'] ?? null, 'barcode'=>$product['code'] ?? null],
            'idefix', 'Trendyol' => ['listingId'=>$line['barcode'] ?? null, 'barcode'=>$line['barcode'] ?? null],
            'PTT AVM' => self::ptt($line['urunId'] ?? null, $line['urunBarkod'] ?? null, $line['variantBarkod'] ?? null),
            default => [],
        };
        if ($provider==='Hepsiburada' && !isset($line['hepsiburadaSku'])
            && $fields['catalogId']===($line['merchantSKU'] ?? $line['merchantSku'] ?? null)) unset($fields['catalogId']);
        $fields = self::normalize([...$fields, 'model'=>self::model($product)]);
        return $fields ? ['sourceProduct'=>$fields] : [];
    }

    public static function model(array $product): string
    {
        foreach (['modelCode','modelNumber','model'] as $key) {
            $value=ShipmentProductCode::normalize($product[$key]??null);
            if ($value!=='') return $value;
        }
        $attributes=$product['productAttributes']??$product['attributes']??[];
        if (!is_array($attributes)) return '';
        foreach ($attributes as $attribute) {
            if (is_array($attribute)&&in_array($attribute['attributeName']??'', ['Model','Model Kodu','Model Numarası'], true)) {
                return ShipmentProductCode::normalize($attribute['attributeValueName']??$attribute['attributeValue']??null);
            }
        }
        return '';
    }

    public static function normalize(array $fields): array
    {
        $valid = [];
        foreach (['listingId','catalogId','variantId','barcode','model'] as $field) {
            $value = ShipmentProductCode::normalize($fields[$field] ?? null);
            if ($value === '' || strlen($value)>180 || $value==='0') continue;
            if ($field === 'barcode') $value = InventoryProductMatch::barcode($value);
            if ($field === 'model') $value = InventoryLiveSkuIdentity::normalize($value);
            if ($value !== '') $valid[$field] = $value;
        }
        return $valid;
    }

    public static function stored(string $provider, array $line): array
    {
        if (is_array($line['sourceProduct'] ?? null)) return self::normalize($line['sourceProduct']);
        // Old PTT delivery details already retained the API's ürunBarkod and ürün ID.
        return $provider==='PTT AVM'
            ?self::normalize(self::ptt($line['sourceProductId'] ?? null, $line['sku'] ?? null, null))
            :[];
    }

    public static function retain(array $order,array $previous): array
    {
        if (empty($order['details']['products'])||empty($previous['details']['products'])) return $order;
        $prior=array_column($previous['details']['products'],null,'lineId');
        foreach ($order['details']['products'] as &$line) {
            if (!isset($line['lineId'])) continue;
            $old=$prior[$line['lineId']??'']??[];
            $fields=$old['sourceProduct']??[];
            if (!$fields || ($old['sku']??null)!==($line['sku']??null) || ($old['title']??null)!==($line['title']??null)) continue;
            $observed=$line['sourceProduct']??[];
            if (array_diff_assoc(array_intersect_key($observed,$fields),$fields)) continue;
            $line['sourceProduct']=self::normalize($observed+$fields);
        }
        unset($line);
        return $order;
    }

    public static function references(array $order): array
    {
        $references=[];
        foreach ($order['details']['products']??[] as $line) {
            if (!empty($line['sourceProduct'])&&isset($line['lineId'])) $references[$line['lineId']]=$line['sourceProduct'];
        }
        return $references;
    }

    private static function ptt(mixed $id, mixed $barcode, mixed $variant): array
    {
        $id = ShipmentProductCode::normalize($id);
        $variant = ShipmentProductCode::normalize($variant);
        $listing = $variant!=='' && $id!=='' ? $id.':'.$variant : $id;
        return ['listingId'=>$listing, 'variantId'=>$variant, 'barcode'=>$variant!==''?$variant:$barcode];
    }
}
