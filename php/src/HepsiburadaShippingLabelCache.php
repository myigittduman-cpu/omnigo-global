<?php
declare(strict_types=1);
namespace Omnigo;

/** Keeps package feed evidence until the existing order queue consumes it. */
final class HepsiburadaShippingLabelCache
{
    private array $entries = [];
    private bool $changed = false;

    public function __construct(
        private int $company,
        private string $merchant,
        private ?DeliveryCipher $cipher = null,
        array $saved = [],
    ) {
        if ($cipher && ($saved['merchant'] ?? '') === $merchant && isset($saved['envelope'])) {
            $this->entries = $cipher->open($company, 'hb-shipping-labels:'.$merchant, $saved['envelope']);
        }
    }

    public function rememberFeed(array $items, bool $fullPackage=true): void
    {
        $observed = [];
        foreach ($items as $item) {
            if (!$fullPackage && !array_key_exists('barcode',$item) && !array_key_exists('Barcode',$item)) continue;
            $package = (string)($item['packageNumber'] ?? $item['PackageNumber'] ?? '');
            if (!preg_match('/^[0-9]{1,30}$/D', $package)) continue;
            $orders = HepsiburadaOrderDiscovery::numbers([$item], $this->merchant);
            $code = DeliveryShippingLabels::code($item['barcode'] ?? $item['Barcode'] ?? null);
            foreach ($orders as $order) {
                $key = $order.':'.$package;
                if (array_key_exists($key,$observed) && $observed[$key] !== $code) {
                    $observed[$key] = null;
                } else {
                    $observed[$key] = $code;
                }
            }
        }
        foreach ($observed as $key=>$code) {
            [$order,$package] = explode(':',$key,2);
            $this->put($order,$package,$code);
        }
    }

    public function put(string $order, string $package, ?string $value): void
    {
        $key = $order.':'.$package;
        if ($value === null && !isset($this->entries[$key])) return;
        if (isset($this->entries[$key]) && $this->entries[$key] === $value) return;
        unset($this->entries[$key]);
        if ($value !== null) $this->entries[$key] = $value;
        if (count($this->entries) > 10000) array_shift($this->entries);
        $this->changed = true;
    }

    public function read(string $order, string $package): ?string
    {
        return $this->entries[$order.':'.$package] ?? null;
    }

    public function save(array &$state): void
    {
        if ($this->changed && $this->cipher) {
            $state['packageLabels'] = ['merchant'=>$this->merchant,
                'envelope'=>$this->cipher->seal($this->company, 'hb-shipping-labels:'.$this->merchant, $this->entries)];
        }
    }
}
