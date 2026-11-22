<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Authored corporate content only; no portal or customer data access. */
final class WebsiteReview
{
    private static string $navigation = '';
    private static string $delivery = '';

    public static function prepare(): void
    {
        self::$navigation = self::$delivery = '';
        if (is_front_page() || is_page('uzmanlik-alanlarimiz')) self::$navigation = self::render('service-navigation');
        if (is_page('is-birligi-modelimiz') || is_page('hakkimizda')) self::$delivery = self::render('project-delivery');
    }

    private static function render(string $name): string
    {
        ob_start();
        require ROOT . '/templates/' . $name . '.php';
        return (string) ob_get_clean();
    }

    public static function update(string $html): string
    {
        if (!str_contains($html, 'class="og-site"')) return $html;
        $html = strtr($html, [
            'Omnigo Global, markaların pazaryeri mağazalarını kurmasına ve yönetmesine destek veren bir e-ticaret danışmanlığı şirketidir. Ürün içerikleri, günlük operasyon takibi ve performans raporlamasını birlikte yürütürüz.' => 'Omnigo Global; pazaryeri yönetimi, e-ticaret sitesi, mobil satış uygulaması ve kurumsal web geliştirme alanlarında markalarla çalışır. Dijital satış kanallarını, içerikleri ve günlük operasyonu ortak bir planla ele alırız.',
            'Mağaza kurulumundan ürün içeriklerine, pazaryeri operasyon takibinden performans raporlamasına kadar dijital satış süreçlerinizi yönetiyoruz. Her çalışma, üzerinde anlaşılan kanal ve ürün kapsamıyla yürütülür.' => 'Pazaryeri yönetiminden e-ticaret sitesi, mobil uygulama ve kurumsal web projelerine kadar dijital kanallarınızı birlikte geliştiriyoruz. Operasyon işleri ile yazılım projelerinin kapsamını, teslimlerini ve sorumluluklarını ayrı ayrı netleştiririz.',
            'Ürünlerinizi, mevcut satış kanallarınızı, operasyon yapınızı ve hedeflerinizi dinleriz. Öncelikli ihtiyaçları birlikte belirleriz.' => 'Ürünlerinizi, satış kanallarınızı, mevcut web veya mobil altyapınızı ve hedeflerinizi dinleriz. Yönetim desteği mi, yeni bir dijital kanal mı gerektiğini birlikte belirleriz.',
            'Kurulum, katalog hazırlığı veya belirli bir iyileştirme ihtiyacı için sınırları ve teslimatları tanımlanmış çalışma.' => 'Mağaza kurulumu, e-ticaret sitesi, mobil uygulama, kurumsal web veya belirli bir iyileştirme için kapsamı ve teslimleri tanımlanmış çalışma.',
            'Örn. Trendyol, Hepsiburada; henüz başlamadım' => 'Pazaryerleri, mevcut web siteniz veya henüz başlamadım',
            'Mağaza kurulumu, yönetim, ürün içerikleri veya raporlama…' => 'Pazaryeri yönetimi, e-ticaret sitesi, mobil uygulama veya kurumsal web ihtiyacınızı anlatın…',
        ]);
        if (self::$navigation !== '' && !str_contains($html, 'id="og-service-navigation"')) {
            $html = preg_replace('~(<main\b[^>]*>\s*<section\b.*?</section>)~su', '$1' . self::$navigation, $html, 1) ?? $html;
        }
        if (self::$delivery !== '' && !str_contains($html, 'id="og-project-delivery"')) {
            $html = preg_replace('~</main>(\s*<footer class="og-footer-v2")~u', self::$delivery . '</main>$1', $html, 1) ?? $html;
        }
        return $html;
    }
}
