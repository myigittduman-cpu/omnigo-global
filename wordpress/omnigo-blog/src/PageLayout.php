<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class PageLayout
{
    public static function update(string $html, string $path): string
    {
        if (!str_contains($html, 'class="og-site"')) return $html;
        // Retain Astra's layout hooks while leaving the corporate main landmark unique.
        $open = '<main id="main" class="site-main">';
        $close = '</main><!-- #main -->';
        if (substr_count($html, $open) === 1 && substr_count($html, $close) === 1
            && preg_match_all('~<main\b[^>]*>~i', $html) === 2) {
            $html = str_replace([$open, $close], ['<div id="main" class="site-main">', '</div><!-- #main -->'], $html);
        }
        if (!in_array($path, ['//', '/', '/uzmanlik-alanlarimiz/'], true)) return $html;
        $html = str_replace('class="og-site"', 'class="og-site og-compact"', $html);
        return strtr($html, [
            'Yeni bir mağaza açıyor veya mevcut kanallarınızı yönetiyor olabilirsiniz. Başlangıç noktamız, markanızın bugün neye ihtiyaç duyduğunu birlikte netleştirmek.' => 'Yeni mağazanız veya mevcut satış kanallarınız için ihtiyaçları birlikte belirleyelim.',
            'Ürünlerinizi, satış yaptığınız pazaryerlerini ve ekibinizin zorlandığı işleri birlikte değerlendiriyoruz.' => 'Ürünlerinizi, satış kanallarınızı ve ekibinizin ihtiyaçlarını değerlendiriyoruz.',
            'Mağaza kurulumu, ürün içeriği veya günlük takip: hangi işlerin ele alınacağını ve görev paylaşımını netleştiriyoruz.' => 'Ele alınacak işleri ve görev paylaşımını netleştiriyoruz.',
            'Mutabık kaldığımız kapsam için çalışma takvimini, iletişim düzenini ve raporlamayı birlikte belirliyoruz.' => 'Çalışma takvimini, iletişim ve raporlama düzenini belirliyoruz.',
            'Eksik bir başlık, müşterinin ürününüzü anlamasını zorlaştırabilir. Markanızdan aldığımız ve doğruluğunu teyit ettiğimiz bilgileri başlık, özellik ve varyant alanlarında anlaşılır bir düzene getiririz.' => 'Doğruluğunu teyit ettiğimiz ürün bilgilerini başlık, özellik ve varyant alanlarında anlaşılır biçimde düzenleriz.',
            'E-ticarete geçiş, bir mağaza hesabı açmaktan daha fazlasıdır. Ürün bilgilerinizin, kategori yapınızın ve operasyon akışınızın satışa uygun biçimde hazırlanması gerekir. Başlangıç adımlarını markanızın kaynaklarına ve hedeflerine göre planlarız.' => 'Ürün bilgilerini, kategorileri ve operasyon akışını satışa hazırlarız. Başlangıç adımlarını markanızın kaynaklarına ve hedeflerine göre planlarız.',
            'Birden fazla satış kanalını yönetirken ürün, fiyat, kampanya ve sipariş süreçlerinin birbirini desteklemesi gerekir. Günlük ihtiyaçları takip eder, belirlenen sorumluluklar çerçevesinde süreçleri koordine ederiz.' => 'Ürün, fiyat, kampanya ve sipariş süreçlerini birlikte takip eder; mutabık kaldığımız sorumluluklar kapsamında koordine ederiz.',
            'Ürün sayfası, müşterinizin karar verdiği temel temas noktalarından biridir. Bilgilerin anlaşılır, tutarlı ve satış kanalının gereksinimleriyle uyumlu olmasına odaklanırız.' => 'Ürün bilgilerini anlaşılır, tutarlı ve satış kanalının gereksinimlerine uygun biçimde düzenleriz.',
            'Kanal performansını düzenli değerlendirmek, hangi alanlara odaklanmanız gerektiğini görünür kılar. Satış ve operasyon verilerini ele alır, bulguları uygulanabilir önceliklere dönüştürürüz.' => 'Satış ve operasyon verilerini düzenli değerlendirir, bulguları uygulanabilir önceliklere dönüştürürüz.',
        ]);
    }
}
