<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Public profile links only: no embedded feeds, trackers or third-party scripts. */
final class SocialLinks
{
    public static function render(): string
    {
        $links = [
            ['instagram', 'Instagram', 'https://www.instagram.com/omnigoglobal/', '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17.5" cy="6.5" r="1.2" fill="currentColor"/>'],
            ['x', 'X', 'https://x.com/omnigoglobal', '<path fill="currentColor" d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.64 7.584H.47l8.6-9.835L0 1.154h7.594l5.243 6.932zm-1.29 19.49h2.039L6.487 3.24H4.3z"/>'],
            ['linkedin', 'LinkedIn', 'https://www.linkedin.com/in/omnigo-global-11b7aa444/', '<path fill="currentColor" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 1 1 0-4.124 2.062 2.062 0 0 1 0 4.124zM7.119 20.452H3.555V9h3.564z"/>'],
        ];
        $html = '<div class="og-social-links" role="group" aria-label="Omnigo Global sosyal medya hesapları"><span class="og-social-caption">Bizi takip edin</span><div class="og-social-buttons">';
        foreach ($links as [$key, $name, $url, $svg]) {
            $html .= '<a class="og-social-link og-social-' . $key . '" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr('Omnigo Global · ' . $name . ' (yeni sekmede açılır)') . '" title="' . esc_attr($name) . '"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">' . $svg . '</svg></a>';
        }
        return $html . '</div></div>';
    }

    public static function update(string $html): string
    {
        return preg_replace_callback('~<footer\b[^>]*>.*?</footer>~su', static function (array $match): string {
            $footer = $match[0];
            if (!preg_match('~class="(?:og-footer-v2|ogb-footer)"~', $footer)) return $footer;
            $footer = preg_replace('~<a\b[^>]*href="https://www\.instagram\.com/omnigoglobal/"[^>]*>Instagram\s*↗</a>~u', '', $footer) ?? $footer;
            if (str_contains($footer, 'class="og-social-links"')) return $footer;
            return preg_replace_callback('~(<div class="(?:og-f-brand|ogb-footer-brand)">.*?)</div>~su', static fn(array $brand): string => $brand[1] . self::render() . '</div>', $footer, 1) ?? $footer;
        }, $html) ?? $html;
    }
}
