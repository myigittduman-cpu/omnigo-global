<?php
/**
 * Plugin Name: Omnigo Web Sitesi
 * Description: Kurumsal hizmetler, referanslar, blog yönetimi ve müşteri portalı tanıtımı.
 * Version: 1.2.3
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author: Omnigo Global
 */
namespace Omnigo\Blog;
defined('ABSPATH') || exit;
const VERSION = '1.2.3';
const ROOT = __DIR__;
foreach (['ContentType', 'PostMetadata', 'Frontend', 'PortalPage', 'Seo', 'StructuredData', 'ContentLinks', 'PrestigeHeader', 'Navigation', 'SitePages', 'Images', 'PageLayout', 'PublicAssets', 'SocialLinks', 'ServicePortfolio', 'WebsiteReview', 'EditorialContent', 'SiteContent', 'Sitemap'] as $module) {
    require_once ROOT . '/src/' . $module . '.php';
}
ContentType::boot();
PostMetadata::boot();
Frontend::boot();
PortalPage::boot();
Seo::boot();
Navigation::boot();
PrestigeHeader::boot();
SitePages::boot();
PublicAssets::boot();
SiteContent::boot();
Sitemap::boot();
EditorialContent::boot();
ContentLinks::boot();
register_activation_hook(__FILE__, [ContentType::class, 'activate']);
register_deactivation_hook(__FILE__, [ContentType::class, 'deactivate']);
