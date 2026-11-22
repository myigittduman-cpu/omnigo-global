<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class Images
{
    private static function manifest(): array
    {
        static $manifest;
        return $manifest ??= (json_decode((string) file_get_contents(ROOT . '/assets/image-manifest.json'), true) ?: []);
    }

    public static function url(string $name, int $width = 960): string
    {
        $image = self::manifest()[$name] ?? null;
        if (!$image) return '';
        $file = $image['variants'][$width] ?? end($image['variants']);
        return plugins_url('assets/images/' . $file, ROOT . '/omnigo-blog.php');
    }

    public static function update(string $html): string
    {
        $processor = new \WP_HTML_Tag_Processor($html);
        $visual = 0;
        while ($processor->next_tag('IMG')) {
            $src = (string) $processor->get_attribute('src');
            $path = (string) wp_parse_url($src, PHP_URL_PATH);
            if (!str_contains($path, '/wp-content/')) continue;
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('~^omnigo-musteri-portali-demo(?:-\d+x\d+)?$~', $name)) {
                $processor->set_attribute('alt', 'Omnigo müşteri portalının örnek satış ve çalışma alanı');
                continue;
            }
            if (!isset(self::manifest()[$name])) $name = preg_replace('~(?:-\d+x\d+|-\d+)$~', '', $name);
            $image = self::manifest()[$name] ?? null;
            if (!$image) continue;
            $logo = $name === 'omnigo-global-logo-tr';
            $processor->set_attribute('src', self::url($name, $logo ? 440 : 960));
            $sources = [];
            foreach ($image['variants'] as $width => $file) $sources[] = self::url($name, (int) $width) . ' ' . $width . 'w';
            $processor->set_attribute('srcset', implode(', ', $sources));
            $processor->set_attribute('sizes', $logo ? '(max-width: 600px) 130px, 170px' : '(max-width: 600px) 92vw, (max-width: 1000px) 80vw, 660px');
            $processor->set_attribute('width', (string) $image['width']);
            $processor->set_attribute('height', (string) $image['height']);
            $processor->set_attribute('alt', $image['alt']);
            $processor->set_attribute('decoding', 'async');
            if (!$logo) {
                $eager = ++$visual === 1;
                $processor->set_attribute('loading', $eager ? 'eager' : 'lazy');
                $processor->set_attribute('fetchpriority', $eager ? 'high' : 'auto');
            }
        }
        return $processor->get_updated_html();
    }
}
