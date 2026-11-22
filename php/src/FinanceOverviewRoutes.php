<?php
declare(strict_types=1);
namespace Omnigo;

/** Finance detail pages and commission settings are administrator-only. */
final class FinanceOverviewRoutes
{
    public function __construct(private Database $db, private Security $security) {}

    public function handle(string $path, string $method, array $user): void
    {
        if ($path==='/api/finance/profitability/commission-rates') {
            Security::admin($user);
            $rates=new ProfitabilityCommissionRates($this->db);
            if ($method==='GET') {
                $company=$this->security->company($user,$_GET['company']??null);
                Http::json(['rates'=>$rates->all($company)]);
            }
            if ($method==='POST') {
                $data=Http::body(4096);
                $company=$this->security->company($user,$data['company']??null);
                $rates->save($company,$data);
                Http::json(['rates'=>$rates->all($company)]);
            }
            Http::fail(405,'İşlem desteklenmiyor.');
        }
        if (!in_array($path, ['/api/finance/deductions', '/api/finance/refunds', '/api/finance/profitability'], true)) return;
        Security::admin($user);
        if ($method !== 'GET') Http::fail(405, 'Bu rapor yalnızca görüntülenebilir.');

        $company = $this->security->company($user, $_GET['company'] ?? null);
        $channel = Validation::choice($_GET['channel'] ?? 'all', ['all', ...Repository::CHANNELS]);
        MarketplaceVisibility::requireVisible($channel);
        $filters = [
            ...array_intersect_key($_GET, array_flip(['page', 'pageSize', 'orderNumber'])),
            'from' => Validation::date($_GET['from'] ?? null),
            'to' => Validation::date($_GET['to'] ?? null),
            'channel' => $channel,
        ];
        Http::json(match($path){
            '/api/finance/profitability'=>(new ProfitabilityReport($this->db))->read($user,$company,$filters),
            '/api/finance/deductions'=>(new FinanceDeductionsReport($this->db))->read($user,$company,$filters),
            '/api/finance/refunds'=>(new FinanceRefundsReport($this->db))->read($company,$filters),
        });
    }
}
