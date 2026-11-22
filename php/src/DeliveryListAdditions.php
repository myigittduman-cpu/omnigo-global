<?php
declare(strict_types=1);
namespace Omnigo;

/** Compatibility for older clients. Shipped packages are already in delivery planning. */
final class DeliveryListAdditions
{
    public function __construct(private Database $db, private DeliveryRepository $orders) {}

    public function add(int $company, array $input): array
    {
        $city = Validation::choice($input['city'] ?? null, ['all', ...DeliveryMapper::CITIES]);
        $cities = DeliveryCities::parse($input['cities'] ?? null);
        $entries = DeliveryRecordSelection::entries($input['orders'] ?? null);
        return $this->db->transaction(function () use ($company, $city, $cities, $entries): array {
            $rows = DeliveryRecordSelection::rows($this->db, $company, $entries, true);
            $allowed = CustomerCities::read($this->db, $company);
            $key = null;
            foreach (array_chunk(array_values($rows), 200) as $batch) {
                foreach ($this->orders->operationalBatch($batch) as $order) {
                    $orderCity = $order['city'];
                    if (!in_array($orderCity, $allowed, true) || ($city !== 'all' && $orderCity !== $city)
                        || ($cities !== null && !in_array($orderCity, $cities, true))) {
                        Http::fail(409, 'Sipariş seçili teslimat iline ait değil.');
                    }
                    if (!$order['planningProducts']['shipped'] || $order['issues']) {
                        Http::fail(409, 'Sevk edilen sipariş artık listede bulunmuyor. Listeyi yenileyin.');
                    }
                    $orderKey = DeliveryDispatchScope::key($order);
                    $key ??= $orderKey;
                    if ($key !== $orderKey) Http::fail(400, 'Her işlemde aynı şehirdeki tek siparişi ekleyin.');
                }
            }
            return ['selectionKey' => $key, 'revision' => ShipmentRevision::read($this->db, $company)];
        });
    }
}
