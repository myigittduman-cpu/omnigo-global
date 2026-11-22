<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class SitePages
{
    public const PRIVACY = '/gizlilik-ve-kisisel-veriler/';
    public const REFERENCES = '/referanslarimiz/';

    public static function boot(): void
    {
        add_action('wp', static function (): void {
            if (self::privacy() || self::references()) {
                global $wp_query;
                $wp_query->is_404 = false;
                status_header(200);
            }
            if (is_category('uncategorized') && (int) (get_queried_object()->count ?? 0) === 0) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
            }
        }, 5);
        add_action('template_redirect', static function (): void {
            if (self::path() === '/contact/') { wp_safe_redirect(home_url('/gorusme-talebi/'), 301); exit; }
        }, 0);
        add_filter('template_include', static function (string $template): string {
            if (self::privacy()) return ROOT . '/templates/privacy.php';
            if (self::references()) return ROOT . '/templates/references.php';
            if (is_404()) return ROOT . '/templates/not-found.php';
            return $template;
        }, 101);
    }

    public static function path(): string
    {
        return '/' . trim((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') . '/';
    }

    public static function privacy(): bool { return self::path() === self::PRIVACY; }
    public static function references(): bool { return self::path() === self::REFERENCES; }

    public static function details(): ?array
    {
        $pages = [
            '' => ['E-Ticaret Danışmanlığı ve Pazaryeri Yönetimi', 'Pazaryeri yönetimi ve e-ticaret danışmanlığı; markanıza özel e-ticaret sitesi, mobil satış uygulaması ve kurumsal web çözümleri. Omnigo Global ile tanışın.'],
            'uzmanlik-alanlarimiz' => ['Pazaryeri Yönetimi, Web ve Mobil Çözümler', 'Mağaza kurulumu, ürün içerikleri, sipariş takibi ve raporlama. E-ticaret sitesi, mobil satış uygulaması ve kurumsal web geliştirme hizmetlerimizi inceleyin.'],
            'referanslarimiz' => ['E-Ticaret, Web ve Mobil Proje Referansları', 'Vizyona için e-ticaret sitesi, Vizyona Online mobil uygulaması, kurumsal web sitesi ve pazaryeri yönetimi çalışmalarımızın kapsamını inceleyin.'],
            'omnigo-yaklasimi' => ['Omnigo Yaklaşımı', 'Mevcut durumu analiz ediyor, öncelikleri birlikte belirliyor ve e-ticaret operasyonunu düzenli takip ediyoruz. Omnigo Global çalışma yaklaşımını inceleyin.'],
            'is-birligi-modelimiz' => ['İş Birliği Modelimiz', 'Pazaryeri yönetimi, web ve mobil projelerde ihtiyaç analizi, tasarım, geliştirme, yayın ve takip adımlarımızı keşfedin.'],
            'hakkimizda' => ['Hakkımızda: E-Ticaret ve Dijital Çözümler', 'Omnigo Global E-Ticaret Operasyon ve Danışmanlık Limited Şirketi. Pazaryeri yönetimi, e-ticaret, mobil ve kurumsal web projelerinde çalışma biçimimizi tanıyın.'],
            'iletisim' => ['İletişim ve Ankara Ofisimiz', 'Pazaryeri yönetimi, e-ticaret sitesi veya mobil uygulama projenizi konuşalım. Omnigo Global iletişim bilgileri ve Çankaya, Ankara ofis adresi.'],
            'gorusme-talebi' => ['Görüşme Talebi', 'Markanızın satış kanallarını ve ihtiyaçlarını paylaşın. Omnigo Global ile pazaryeri yönetimi, e-ticaret sitesi, mobil uygulama veya kurumsal web projeniz için görüşme planlayın.'],
            'gizlilik-ve-kisisel-veriler' => ['Gizlilik ve Kişisel Veriler', 'Omnigo Global web sitesi ve görüşme formunda kişisel verilerin işlenmesi, kullanım amaçları ve başvuru yolları hakkında bilgilendirme.'],
        ];
        $key = self::references() ? trim(self::REFERENCES, '/') : (is_front_page() ? '' : (self::privacy() ? trim(self::PRIVACY, '/') : (is_page() ? get_post_field('post_name', get_queried_object_id()) : null)));
        return $key !== null && isset($pages[$key]) ? ['title' => $pages[$key][0] . ' | Omnigo Global', 'description' => $pages[$key][1], 'url' => home_url($key ? '/' . $key . '/' : '/')] : null;
    }
}
