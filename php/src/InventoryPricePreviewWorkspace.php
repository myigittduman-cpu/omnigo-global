<?php
declare(strict_types=1);
namespace Omnigo;

/** Isolated assumptions per store and stock card. No purchase or price outbox writes. */
final class InventoryPricePreviewWorkspace
{
    private const KIND = 'inventory_price_preview';

    public function __construct(private Database $db) {}

    public function enabled(int $company): bool
    {
        $row = $this->db->one('SELECT name FROM companies WHERE id=?', [$company]);
        return $row !== null && InventoryLiveSkuIdentity::normalize(str_replace('İ','I',$row['name'])) === 'TERZIOĞLU SHOP';
    }

    public function get(int $company, mixed $key): array
    {
        $sku = $this->sku($company, $key);
        $record = $this->record($company, $key);
        $draft = $record ? json_decode($record['payload'],true,16,JSON_THROW_ON_ERROR) : [];
        return $this->view($sku, $draft, (int)($record['version']??0));
    }

    public function save(int $company, array $input): array
    {
        $key = $input['productKey']??null;
        $sku = $this->sku($company,$key);
        $version = $input['version']??null;
        $cost = $input['costNetCents']??null;
        $rules = $input['rules']??null;
        if (!is_int($version) || $version < 0 || ($cost !== null && (!is_int($cost) || $cost < 0 || $cost > 10000000000))
            || !is_array($rules) || array_diff(array_keys($rules),InventoryCatalog::PROVIDERS)) {
            throw new \InvalidArgumentException('Fiyat önizlemesi bilgilerini kontrol edin.');
        }
        $cleanRules = [];
        $fields = array_flip(['shippingNetCents','otherNetCents','salesVatBasisPoints','effectiveCommissionBasisPoints',
            'targetNetMarginBasisPoints','roundUpCents']);
        foreach ($rules as $provider=>$rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException('Pazaryeri fiyat kuralı geçersiz.');
            }
            $clean = array_intersect_key($rule,$fields);
            InventoryChannelPricePreview::quote($cost??0,$clean);
            $cleanRules[$provider] = $clean;
        }
        $draft = ['productKey'=>$key,'costNetCents'=>$cost,'rules'=>$cleanRules];
        return TransactionRetry::run($this->db,function() use ($company,$sku,$key,$version,$draft) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if ($this->sku($company,$key) !== $sku) {
                throw new \DomainException('Stok kartı değişti. Pencereyi yeniden açın.');
            }
            $record = $this->record($company,$key);
            $current = (int)($record['version']??0);
            if ($version !== $current) {
                throw new \DomainException('Kurallar başka bir oturumda değişti. Pencereyi yeniden açın.');
            }
            $view = $this->view($sku,$draft,$current+1);
            $json = json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $now = gmdate('c');
            if ($record) {
                $this->db->run('UPDATE records SET payload=?,version=version+1,updated=? WHERE id=? AND company_id=?',
                    [$json,$now,$record['id'],$company]);
            } else {
                $this->db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,1,?)',
                    [$company,self::KIND,$json,$now]);
            }
            return $view;
        });
    }

    private function view(string $sku, array $draft, int $version): array
    {
        $rules = $draft['rules']??[];
        $cost = $draft['costNetCents']??null;
        $preview = (new InventoryCentralPricePreview($this->db))->read($sku,$rules,$cost);
        return [...$preview,'version'=>$version,'draftCostNetCents'=>$cost,'rules'=>$rules];
    }

    private function sku(int $company, mixed $key): string
    {
        if (!$this->enabled($company)) {
            throw new \OutOfBoundsException('Fiyat önizlemesi bu mağazada açık değil.');
        }
        if (!is_string($key) || !preg_match('/^[0-9a-f]{64}$/D',$key)) {
            throw new \InvalidArgumentException('Stok kartı geçersiz.');
        }
        $main = InventoryStockCard::primaryProductSql($this->db);
        $card = $this->db->one("SELECT p.sku FROM inventory_products p WHERE p.company_id=? AND p.product_key=? AND $main",
            [$company,$key]);
        if (!$card || trim($card['sku']) === '' || (new InventoryVisibility($this->db))->removed($company,$key)) {
            throw new \OutOfBoundsException('Stok kartı bulunamadı.');
        }
        return $card['sku'];
    }

    private function record(int $company, string $key): ?array
    {
        $field = "JSON_EXTRACT(payload,'$.productKey')";
        if ($this->db->mysql()) {
            $field = "JSON_UNQUOTE($field)";
        }
        return $this->db->one("SELECT id,version,payload FROM records WHERE company_id=? AND kind=? AND $field=?",
            [$company,self::KIND,$key]);
    }
}
