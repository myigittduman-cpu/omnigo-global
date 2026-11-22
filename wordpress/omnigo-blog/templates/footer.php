<?php namespace Omnigo\Blog; defined('ABSPATH') || exit; ?>
<footer class="ogb-footer">
    <div class="ogb-footer-top">
        <div><h2>Markanızın dijital dünyadaki sonraki adımını birlikte atalım.</h2><p>Markanızın ihtiyaçlarını konuşalım, doğru başlangıcı birlikte belirleyelim.</p></div>
        <a class="ogb-button" href="<?php echo esc_url(home_url('/gorusme-talebi/')); ?>">Markanız İçin Konuşalım <span class="ogb-arrow" aria-hidden="true"></span></a>
    </div>
    <div class="ogb-footer-columns">
        <div class="ogb-footer-brand">
            <a href="<?php echo esc_url(home_url('/')); ?>" aria-label="Omnigo Global ana sayfa"><img src="<?php echo esc_url(home_url('/wp-content/uploads/2026/09/omnigo-global-logo-tr.png')); ?>" alt="Omnigo Global — E-Ticaret Danışmanlığı" width="1672" height="941" loading="lazy"></a>
            <p class="ogb-footer-tagline">E-Ticaret ve Dijital Çözümler</p><p>Pazaryeri yönetimi, e-ticaret siteleri, mobil uygulamalar ve kurumsal web çözümleriyle markanızın yanındayız.</p>
        </div>
        <div><h3>Kurumsal</h3><nav aria-label="Alt menü kurumsal">
            <?php foreach (['/' => 'Ana Sayfa', '/referanslarimiz/' => 'Referanslarımız', '/omnigo-yaklasimi/' => 'Omnigo Yaklaşımı', '/is-birligi-modelimiz/' => 'İş Birliği Modelimiz', '/musteri-portali/' => 'Müşteri Portalı', '/hakkimizda/' => 'Hakkımızda', '/iletisim/' => 'İletişim', '/blog/' => 'Blog'] as $url => $name): ?>
            <a href="<?php echo esc_url(home_url($url)); ?>"><?php echo esc_html($name); ?></a>
            <?php endforeach; ?>
        </nav></div>
        <div><h3>Uzmanlıklarımız</h3><nav aria-label="Alt menü uzmanlıklar">
            <?php foreach (['yonetim' => 'Pazaryeri Yönetimi', 'kurulum' => 'Mağaza Kurulumu', 'icerik' => 'Ürün ve İçerik Yönetimi', 'analiz' => 'Performans Analizi', 'e-ticaret-sitesi' => 'E-Ticaret Sitesi', 'mobil-uygulama' => 'Mobil Satış Uygulaması', 'kurumsal-web' => 'Kurumsal Web Sitesi'] as $anchor => $name): ?>
            <a href="<?php echo esc_url(home_url('/uzmanlik-alanlarimiz/#' . $anchor)); ?>"><?php echo esc_html($name); ?></a>
            <?php endforeach; ?>
        </nav></div>
        <div class="ogb-footer-contact"><h3>İletişim</h3><a href="tel:+905475456696">+90 (547) 545 66 96</a><a href="mailto:info@omnigoglobal.com">info@omnigoglobal.com</a><address>Kızılırmak Mah. Dumlupınar Bulvarı<br>Next Level No:3/A, Kat:4, D.No:11<br>06520 Çankaya / Ankara</address><a class="ogb-footer-map" href="https://www.google.com/maps/dir/?api=1&amp;destination=Next%20Level%20K%C4%B1z%C4%B1l%C4%B1rmak%20Dumlup%C4%B1nar%20Bulvar%C4%B1%20%C3%87ankaya%20Ankara" target="_blank" rel="noopener noreferrer">Yol tarifi al <span class="ogb-arrow" aria-hidden="true"></span></a></div>
    </div>
    <div class="ogb-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Omnigo Global. Tüm hakları saklıdır.</span><a class="ogb-footer-top-link" href="#baslangic">Başa dön <span class="ogb-arrow" aria-hidden="true"></span></a></div>
</footer></div>
<?php wp_footer(); ?></body></html>
