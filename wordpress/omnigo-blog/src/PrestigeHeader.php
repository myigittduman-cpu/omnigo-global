<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** One public website header; never rewrites page bodies, forms or footer links. */
final class PrestigeHeader
{
    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', static function (): void {
            $base = plugins_url('assets/', ROOT . '/omnigo-blog.php');
            wp_enqueue_style('omnigo-prestige-header', $base . 'prestige-header.css', [], (string) filemtime(ROOT . '/assets/prestige-header.css'));
            wp_enqueue_script('omnigo-prestige-header', $base . 'prestige-header.js', [], (string) filemtime(ROOT . '/assets/prestige-header.js'), true);
        }, 110);
    }

    public static function update(string $html): string
    {
        if (is_admin() || !str_contains($html, '<header')) return $html;
        // Both existing website templates have a single, non-nested branded header.
        return preg_replace_callback('~<header\b[^>]*\bclass=(["\'])[^"\']*\b(?:og-wrap|ogb-header)\b[^"\']*\1[^>]*>.*?</header>~su',
            static fn(array $match): string => self::render(str_contains($match[0], 'ogb-header')),
            $html) ?? $html;
    }

    public static function render(bool $sticky = false): string
    {
        $path = '/' . trim((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        if ($path !== '/') $path .= '/';
        $primary = ['/' => 'Ana Sayfa', '/uzmanlik-alanlarimiz/' => 'Uzmanlık Alanlarımız', '/referanslarimiz/' => 'Referanslarımız', '/is-ortaklarimiz/' => 'İş Ortaklarımız', '/omnigo-yaklasimi/' => 'Omnigo Yaklaşımı', '/is-birligi-modelimiz/' => 'İş Birliği Modelimiz'];
        $corporate = ['/hakkimizda/' => 'Hakkımızda', '/blog/' => 'Blog'];
        $all = $primary + $corporate + ['/iletisim/' => 'İletişim'];
        $arrow = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.35" aria-hidden="true"><path d="M5 19 19 5M5 5h14v14"/></svg>';
        $person = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.25" aria-hidden="true"><circle cx="12" cy="7.5" r="3.2"/><path d="M5.8 20v-2.7a6.2 6.2 0 0 1 12.4 0V20"/></svg>';
        $chevron = '<svg viewBox="0 0 12 12" width="10" height="10" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true"><path d="m2 4 4 4 4-4"/></svg>';
        $link = static function (string $url, string $label) use ($path): string {
            $current = ($url === $path || ($url === '/blog/' && str_starts_with($path, '/blog/'))) ? ' aria-current="page"' : '';
            return '<a href="' . esc_url(home_url($url)) . '"' . $current . '>' . esc_html($label) . '</a>';
        };
        $html = '<header class="og-prestige-header' . ($sticky ? ' og-prestige-sticky' : '') . '" data-omnigo-prestige="1"><div class="og-prestige-inner">';
        $html .= '<span class="og-prestige-descriptor">E-TİCARET OPERASYON VE<br>DANIŞMANLIK</span>';
        $html .= '<a class="og-prestige-logo" href="' . esc_url(home_url('/')) . '" aria-label="Omnigo Global ana sayfa"><img src="' . esc_url(home_url('/wp-content/uploads/2026/09/omnigo-global-logo-tr.png')) . '" width="170" height="99" alt="Omnigo Global – E-Ticaret Danışmanlığı" decoding="async" fetchpriority="high"></a>';
        $html .= '<div class="og-prestige-actions"><a class="og-prestige-portal" href="' . esc_url(home_url('/musteri-portali/')) . '">' . $person . '<span>İş Ortağım</span></a><a class="og-prestige-contact" href="' . esc_url(home_url('/gorusme-talebi/')) . '"><span>Markanız İçin Konuşalım</span>' . $arrow . '</a></div>';
        $html .= '<nav class="og-prestige-nav" aria-label="Ana menü">';
        foreach ($primary as $url => $label) $html .= $link($url, $label);
        $corporateActive = isset($corporate[$path]) || str_starts_with($path, '/blog/');
        $html .= '<details class="og-prestige-corporate' . ($corporateActive ? ' og-prestige-current' : '') . '"><summary>Kurumsal' . $chevron . '</summary><div class="og-prestige-dropdown"><div class="og-prestige-dropdown-intro"><span>KURUMSAL</span><p>Güvenle başlayan<br>bir iş ortaklığı.</p></div><div class="og-prestige-dropdown-links">';
        foreach ($corporate as $url => $label) $html .= $link($url, $label);
        $html .= '</div></div></details>' . $link('/iletisim/', 'İletişim') . '</nav>';
        $html .= '<details class="og-prestige-mobile"><summary>Menü<span class="og-prestige-menu-bars" aria-hidden="true"></span></summary><nav aria-label="Mobil menü">';
        foreach ($all as $url => $label) $html .= $link($url, $label);
        return $html . '</nav></details></div></header>';
    }
}
