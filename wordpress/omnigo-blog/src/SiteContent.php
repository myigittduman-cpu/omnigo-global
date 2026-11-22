<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class SiteContent
{
    private static string $companySection = '';
    public static function boot(): void
    {
        add_action('template_redirect', static function (): void {
            if (is_admin() || is_feed() || is_preview()) return;
            if (SitePages::details() || SitePages::privacy() || Frontend::active() || PortalPage::active() || is_404()) {
                if (is_page('hakkimizda')) {
                    ob_start();
                    require ROOT . '/templates/company-details.php';
                    self::$companySection = (string) ob_get_clean();
                }
                ServicePortfolio::prepare();
                if (class_exists(WebsiteReview::class)) WebsiteReview::prepare();
                ob_start([self::class, 'update']);
            }
        }, 20);
        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style('omnigo-site-fixes', plugins_url('assets/site-fixes.css', ROOT . '/omnigo-blog.php'), [], VERSION . '.' . filemtime(ROOT . '/assets/site-fixes.css'));
            wp_enqueue_style('omnigo-portfolio', plugins_url('assets/portfolio.css', ROOT . '/omnigo-blog.php'), ['omnigo-site-fixes'], VERSION);
            if (is_page('gorusme-talebi')) wp_enqueue_script('omnigo-inquiry-accessibility', plugins_url('assets/inquiry-accessibility.js', ROOT . '/omnigo-blog.php'), [], VERSION, true);
        });
    }

    public static function removeIntro(string $html): string
    {
        foreach (['style' => 'og-brand-intro-style', 'section' => 'og-brand-intro', 'script' => 'og-brand-intro-script'] as $tag => $id) {
            $html = preg_replace('~<' . $tag . '\b[^>]*\bid=["\']' . $id . '["\'][^>]*>.*?</' . $tag . '>~su', '', $html) ?? $html;
        }
        return $html;
    }

    public static function update(string $html): string
    {
        if (!str_contains($html, '</body>')) return $html;
        $html = self::removeIntro($html);
        if (method_exists(PublicAssets::class, 'trimFontMarkup')) $html = PublicAssets::trimFontMarkup($html);
        $html = ServicePortfolio::update($html);
        if (class_exists(WebsiteReview::class)) $html = WebsiteReview::update($html);
        $html = Navigation::update($html);
        // The Astra title is visually suppressed by the corporate design; remove its duplicate heading too.
        if (str_contains($html, 'class="og-site"')) {
            $html = preg_replace('~<header class="entry-header[^"\']*"[^>]*>.*?</header>~su', '', $html) ?? $html;
            foreach (['header' => 'masthead', 'footer' => 'colophon'] as $tag => $id) {
                $html = preg_replace('~<' . $tag . '\b[^>]*\bid="' . $id . '"[^>]*>.*?</' . $tag . '>~su', '', $html) ?? $html;
            }
        }
        if (is_page('hakkimizda') && !str_contains($html, 'id="og-company-details"')) {
            $html = str_replace('</main><footer class="og-footer-v2"', self::$companySection . '</main><footer class="og-footer-v2"', $html);
        }
        if (is_page('gorusme-talebi') && !str_contains($html, 'id="og-form-privacy"')) {
            $html = preg_replace('~<p class="og-form-notice">.*?</p>~su', '', $html) ?? $html;
            $notice = '<p id="og-form-privacy" class="og-form-notice">Paylaştığınız bilgiler, Omnigo Global E-Ticaret Operasyon ve Danışmanlık Limited Şirketi tarafından talebinizi değerlendirmek ve size dönüş yapmak için işlenir. <a href="' . esc_url(home_url(SitePages::PRIVACY)) . '" target="_blank" rel="noopener">Gizlilik ve kişisel veriler aydınlatma metnini okuyun</a>. Şifre, kimlik belgesi veya müşteri verisi paylaşmayın.</p>';
            $html = preg_replace('~(<form\b[^>]*\bid="srfm-form-2940"[^>]*>)~s', '$1' . $notice, $html, 1) ?? $html;
        }
        return Images::update(PageLayout::update(SocialLinks::update($html), SitePages::path()));
    }
}
