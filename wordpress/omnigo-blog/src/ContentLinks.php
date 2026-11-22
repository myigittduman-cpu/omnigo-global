<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Link each existing guide to the public service it explains. */
final class ContentLinks
{
    public static function boot(): void
    {
        add_filter('the_content', [self::class, 'serviceContext'], 35);
    }

    public static function serviceContext(string $content): string
    {
        if (!is_singular(ContentType::POST_TYPE) || !in_the_loop() || !is_main_query()
            || post_password_required() || str_contains($content, 'ogb-service-context')) return $content;
        $links = [
            'musteri-portalinda-neleri-takip-edebilirsiniz' => ['/musteri-portali/', 'müşteri portalında çalışma ve rapor takibini'],
            'pazaryeri-yonetimi-nedir' => ['/uzmanlik-alanlarimiz/#yonetim', 'pazaryeri yönetimi ve operasyon desteğimizi'],
            'birden-fazla-pazaryerinde-satis-7-nokta' => ['/uzmanlik-alanlarimiz/#analiz', 'pazaryeri performans analizi ve raporlama kapsamımızı'],
            'e-ticaret-sitesi-projesine-baslamadan-once' => ['/uzmanlik-alanlarimiz/#e-ticaret-sitesi', 'e-ticaret sitesi geliştirme hizmetimizi'],
            'mobil-satis-uygulamasi-kapsami' => ['/uzmanlik-alanlarimiz/#mobil-uygulama', 'mobil satış uygulaması geliştirme kapsamımızı'],
            'kurumsal-web-sitesi-ve-e-ticaret-farki' => ['/uzmanlik-alanlarimiz/#kurumsal-web', 'kurumsal web sitesi tasarımı hizmetimizi'],
        ];
        $slug = get_post_field('post_name', get_queried_object_id());
        if (!isset($links[$slug])) return $content;
        [$path, $label] = $links[$slug];
        return $content . '<p class="ogb-service-context">Bu konuyu markanız için değerlendirmek isterseniz '
            . '<a href="' . esc_url(home_url($path)) . '">' . esc_html($label) . '</a> inceleyebilirsiniz.</p>';
    }
}
