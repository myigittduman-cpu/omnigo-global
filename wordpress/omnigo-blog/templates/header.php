<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;
$links = ['/' => 'Ana Sayfa', '/uzmanlik-alanlarimiz/' => 'Uzmanlık Alanlarımız', '/referanslarimiz/' => 'Referanslarımız', '/omnigo-yaklasimi/' => 'Omnigo Yaklaşımı', '/is-birligi-modelimiz/' => 'İş Birliği Modelimiz', '/musteri-portali/' => 'Müşteri Portalı', '/hakkimizda/' => 'Hakkımızda', '/iletisim/' => 'İletişim', '/blog/' => 'Blog'];
$currentPath = SitePages::references() ? SitePages::REFERENCES : (PortalPage::active() ? '/musteri-portali/' : (Frontend::active() ? '/blog/' : ''));
?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class('ogb-page'); ?>><?php wp_body_open(); ?>
<a class="ogb-skip" href="#ogb-main">İçeriğe atla</a>
<div class="ogb-shell" id="baslangic"><header class="ogb-header"><div class="ogb-header-inner">
<a class="ogb-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Omnigo Global ana sayfa"><img src="<?php echo esc_url(home_url('/wp-content/uploads/2026/09/omnigo-global-logo-tr.png')); ?>" alt="Omnigo Global – E-Ticaret Danışmanlığı" width="148" height="83"></a>
<nav class="ogb-desktop-nav" aria-label="Ana menü"><?php foreach ($links as $url => $name): ?><a href="<?php echo esc_url(home_url($url)); ?>" <?php if ($url === $currentPath) echo 'aria-current="page"'; ?>><?php echo esc_html($name); ?></a><?php endforeach; ?></nav>
<a class="ogb-button ogb-outline ogb-contact" href="<?php echo esc_url(home_url('/gorusme-talebi/')); ?>">Markanız İçin Konuşalım <span class="ogb-arrow" aria-hidden="true"></span></a>
<details class="ogb-mobile-menu"><summary><span>Menü</span><span class="ogb-menu-chevron" aria-hidden="true"></span></summary><nav aria-label="Mobil menü"><?php foreach ($links as $url => $name): ?><a href="<?php echo esc_url(home_url($url)); ?>" <?php if ($url === $currentPath) echo 'aria-current="page"'; ?>><?php echo esc_html($name); ?></a><?php endforeach; ?></nav></details>
</div></header>
