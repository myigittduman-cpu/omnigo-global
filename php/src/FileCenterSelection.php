<?php
declare(strict_types=1);
namespace Omnigo;

final class FileCenterSelection
{
    public static function products(Database $db, int $company, int $actor, mixed $selection): int
    {
        $keys = self::items($selection, static fn(mixed $key): bool =>
            is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key) === 1);
        if (!FileCenterSchema::available($db, FileCenterSchema::HIDDEN_PRODUCTS)) FileCenterSchema::install($db);
        return TransactionRetry::run($db, static function() use ($db,$company,$actor,$keys) {
            $db->one('SELECT id FROM companies WHERE id=?'.$db->lock(), [$company]);
            $markers = implode(',', array_fill(0, count($keys), '?'));
            $present = $db->all("SELECT product_key FROM inventory_products WHERE company_id=? AND product_key IN ($markers)",
                [$company,...$keys]);
            if (count($present) !== count($keys)) Http::fail(409, 'Ürün listesi değişti. Listeyi yenileyip tekrar deneyin.');
            $removed = 0;
            foreach ($keys as $key) {
                $scope = [$company,$key];
                if ($db->one('SELECT product_key FROM file_center_hidden_products WHERE company_id=? AND product_key=?', $scope)) continue;
                $db->run('INSERT INTO file_center_hidden_products(company_id,product_key,actor_id,removed_at) VALUES(?,?,?,?)',
                    [...$scope,$actor,gmdate('c')]);
                $removed++;
            }
            return $removed;
        });
    }

    public static function items(mixed $selection, callable $valid): array
    {
        if (!is_array($selection) || !array_is_list($selection) || !$selection || count($selection) > 10) {
            Http::fail(400, 'Bu sayfadan en fazla 10 kayıt seçin.');
        }
        foreach ($selection as $item) {
            if (!$valid($item)) Http::fail(400, 'Seçili kayıt bilgisi geçersiz.');
        }
        return array_values(array_unique($selection));
    }
}
