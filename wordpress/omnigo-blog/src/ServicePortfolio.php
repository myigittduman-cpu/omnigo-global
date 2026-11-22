<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Public, explicitly authored service copy. Never reads portal or customer records. */
final class ServicePortfolio
{
    private static string $sections = '';

    /** Render before the site's output buffer starts; callbacks cannot open another buffer. */
    public static function prepare(): void
    {
        self::$sections = '';
        if (is_front_page() || is_page('uzmanlik-alanlarimiz')) {
            self::$sections = self::render('digital-services') . self::render('reference-summary');
        }
    }

    public static function render(string $template): string
    {
        if (!in_array($template, ['digital-services', 'reference-summary'], true)) return '';
        ob_start();
        require ROOT . '/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }

    public static function update(string $html): string
    {
        if (is_admin() || !str_contains($html, 'class="og-site"')) return $html;
        $html = strtr($html, [
            '<p class="og-f-tagline">E-Ticaret Danışmanlığı</p>' => '<p class="og-f-tagline">E-Ticaret ve Dijital Çözümler</p>',
            'Pazaryerlerinde bir sonraki adımı birlikte atalım.' => 'Markanızın dijital dünyadaki sonraki adımını birlikte atalım.',
            'Markanızın pazaryerlerindeki yolculuğunda strateji, operasyon ve gelişim için yanınızdayız.' => 'Pazaryeri yönetimi, e-ticaret siteleri, mobil uygulamalar ve kurumsal web çözümleriyle markanızın yanındayız.',
        ]);
        if (!is_front_page() && !is_page('uzmanlik-alanlarimiz')) return $html;
        if (is_front_page()) {
            $html = strtr($html, [
                '<span class="og-eyebrow">PAZARYERİ YÖNETİMİ</span><h1 class="og-slide-title">Pazaryerlerinizi<br><em>tek bir stratejiyle yönetin.</em></h1>' => '<span class="og-eyebrow">E-TİCARET VE DİJİTAL ÇÖZÜMLER</span><h1 class="og-slide-title">Dijital satış kanallarınızı<br><em>birlikte geliştirelim.</em></h1>',
                'Mağazanızı, ürünlerinizi ve günlük operasyonunuzu tek bir planla yönetiyoruz.' => 'Pazaryerlerinizi yönetiyor; e-ticaret sitenizi, mobil uygulamanızı ve kurumsal web sitenizi markanıza uygun biçimde geliştiriyoruz.',
                'Hizmetimiz dijital mağaza yönetimi ve danışmanlığıdır.' => 'Pazaryeri yönetimi ve danışmanlığının yanında e-ticaret sitesi, mobil uygulama ve kurumsal web sitesi geliştirme hizmetleri sunuyoruz.',
            ]);
        } else {
            $html = strtr($html, [
                'Mağazanız için<br>net hizmetler.' => 'Markanız için<br>dijital çözümler.',
                'Mağaza kurulumu, ürün içerikleri, günlük operasyon takibi ve raporlama. Hangi işleri üstlendiğimizi ve size neler sunacağımızı aşağıda inceleyin.' => 'Pazaryeri yönetimi ve raporlamadan e-ticaret sitelerine, mobil satış uygulamalarına ve kurumsal web tasarımına kadar hizmetlerimizi inceleyin.',
            ]);
        }
        if (str_contains($html, 'id="og-digital-services"')) return $html;
        // Insert inside the existing corporate main, before its closing tag. Other templates stay intact.
        return preg_replace('~</main>(\s*<footer class="og-footer-v2")~u', self::$sections . '</main>$1', $html, 1) ?? $html;
    }
}
