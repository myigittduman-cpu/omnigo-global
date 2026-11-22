<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class PortalPage
{
    public static function boot(): void
    {
        add_filter('template_include', static fn(string $template): string => self::active() ? ROOT . '/templates/portal.php' : $template, 100);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
    }

    public static function active(): bool
    {
        return is_page('musteri-portali');
    }

    public static function assets(): void
    {
        if (self::active()) wp_enqueue_style('omnigo-portal-showcase', plugins_url('assets/portal-showcase.css', ROOT . '/omnigo-blog.php'), ['omnigo-site-design'], VERSION);
    }

    // Matches the customer navigation allow-list in public/app.js; no admin features.
    public static function features(): array
    {
        return [
            ['01', 'Genel bakış', 'Pazaryeri satışlarını, dönem özetlerini, hakedişleri ve devam eden çalışmaları ortak bir ekrandan izleyin.'],
            ['02', 'Siparişler ve iadeler', 'Aktarılan siparişleri ve iade durumlarını inceleyin; ürün, tutar ve teslimat ayrıntılarına ulaşın.'],
            ['03', 'Stok Yönetimi', 'Pazaryeri ve mağaza stoklarını, ürün bazında satışları ve kaydedilmiş stok hareketlerini görüntüleyin.'],
            ['04', 'Şehir Dışı Sevkiyat', 'Siparişleri ay, pazaryeri ve şehre göre süzün. Size verilen yetkiler kapsamında sevkiyat kayıtları ve belgeleriyle çalışın.'],
            ['05', 'Teslimat Evrakları', 'Şehre göre teslimat listelerini ve sipariş durumlarını takip edin. Belge hazırlama ve dışa aktarım, hesabınıza tanımlanan yetkilere bağlıdır.'],
            ['06', 'Analiz ve raporlar', 'En çok satan ürünleri, satış eğilimlerini ve dönem karşılaştırmalarını inceleyin; izin verilen raporları indirin.'],
            ['07', 'Hakediş takvimi', 'Omnigo tarafından elle kaydedilen planlanan ödemeleri ve teyit durumlarını takvim üzerinde görün.'],
            ['08', 'Çalışma takibi', 'Markanız için yürütülen işleri, paylaşılan çalışma raporlarını ve sonraki adımları takip edin.'],
            ['09', 'Destek ve bildirimler', 'Omnigo ekibiyle aynı konuşmada yazışın; hesabınız için açık olan işlem bildirimlerinden haberdar olun.'],
        ];
    }
}
