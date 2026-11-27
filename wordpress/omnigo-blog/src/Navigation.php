<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class Navigation
{
    public static function boot(): void
    {
        add_filter('elementor/widget/render_content', [self::class, 'update'], 30);
        add_filter('the_content', [self::class, 'update'], 30);
    }

    public static function update(string $content): string
    {
        if (is_admin()) return $content;
        if (class_exists(PrestigeHeader::class)) $content = PrestigeHeader::update($content);
        $paths = [2837 => '/', 2848 => '/uzmanlik-alanlarimiz/', 2850 => '/omnigo-yaklasimi/', 2852 => '/is-birligi-modelimiz/', 2854 => '/hakkimizda/', 2856 => '/iletisim/'];
        $content = preg_replace_callback('~href=(["\'])(https://omnigoglobal\.com)?/\?page_id=(\d+)(#[^"\']*)?\1~', static function (array $match) use ($paths): string {
            return isset($paths[(int) $match[3]]) ? 'href=' . $match[1] . esc_url(home_url($paths[(int) $match[3]]) . ($match[4] ?? '')) . $match[1] : $match[0];
        }, $content) ?? $content;
        $content = preg_replace('~href=(["\'])(?:https://omnigoglobal\.com)?/contact/?\1~', 'href="' . esc_url(home_url('/gorusme-talebi/')) . '"', $content) ?? $content;
        if (!str_contains($content, '<nav')) return $content;
        return preg_replace_callback('~<nav\b[^>]*>.*?</nav>~su', static function (array $match): string {
            $nav = $match[0];
            if (str_contains($nav, 'aria-label="Alt menü uzmanlıklar"')) {
                foreach (['e-ticaret-sitesi' => 'E-Ticaret Sitesi', 'mobil-uygulama' => 'Mobil Satış Uygulaması', 'kurumsal-web' => 'Kurumsal Web Sitesi'] as $anchor => $label) {
                    if (!str_contains($nav, '#' . $anchor)) $nav = str_replace('</nav>', '<a href="' . esc_url(home_url('/uzmanlik-alanlarimiz/#' . $anchor)) . '">' . $label . '</a></nav>', $nav);
                }
                return $nav;
            }
            if (!preg_match('~(?:class="og-(?:links|mobile-links)"|aria-label="Alt menü kurumsal")~u', $nav)) return $nav;
            $footer = str_contains($nav, 'aria-label="Alt menü kurumsal"');
            $nav = preg_replace('~<span class="og-soon-tag">[^<]*</span>~u', '', $nav);
            if (!str_contains($nav, '/referanslarimiz/')) {
                $nav = str_replace('</nav>', '<a href="' . esc_url(home_url('/referanslarimiz/')) . '">Referanslarımız</a></nav>', $nav);
            }
            if (!str_contains($nav, '/is-ortaklarimiz/')) {
                $nav = str_replace('</nav>', '<a href="' . esc_url(home_url('/is-ortaklarimiz/')) . '">İş Ortaklarımız</a></nav>', $nav);
            }
            if ($footer) {
                foreach (['/musteri-portali/' => 'Müşteri Portalı', '/gizlilik-ve-kisisel-veriler/' => 'Gizlilik ve Kişisel Veriler'] as $url => $label) {
                    if (!str_contains($nav, $url)) $nav = str_replace('</nav>', '<a href="' . esc_url(home_url($url)) . '">' . $label . '</a></nav>', $nav);
                }
                if (!str_contains($nav, 'instagram.com/omnigoglobal')) $nav = str_replace('</nav>', '<a href="https://www.instagram.com/omnigoglobal/" target="_blank" rel="noopener noreferrer">Instagram ↗</a></nav>', $nav);
            }
            if (!str_contains($nav, 'data-omnigo-blog') && !preg_match('~href=["\'][^"\']*/blog/?["\']~', $nav)) {
                $link = '<a data-omnigo-blog href="' . esc_url(home_url('/blog/')) . '">Blog</a>';
                $nav = str_replace('</nav>', $link . '</nav>', $nav);
            }
            // Keep link classes and accessibility attributes while ordering the corporate menu.
            preg_match_all('~<a\b[^>]*>.*?</a>~su', $nav, $anchors);
            $order = array_flip(['Ana Sayfa', 'Uzmanlık Alanlarımız', 'Referanslarımız', 'İş Ortaklarımız', 'Omnigo Yaklaşımı', 'İş Birliği Modelimiz', 'Müşteri Portalı', 'Hakkımızda', 'İletişim', 'Blog']);
            $links = $anchors[0];
            $rank = static fn(string $link): int => $order[trim(html_entity_decode(strip_tags($link), ENT_QUOTES, 'UTF-8'))] ?? 99;
            usort($links, static fn(string $a, string $b): int => $rank($a) <=> $rank($b));
            $nav = preg_replace_callback('~<a\b[^>]*>.*?</a>~su', static function () use (&$links): string { return array_shift($links); }, $nav) ?? $nav;
            return $nav;
        }, $content) ?? $content;
    }
}
