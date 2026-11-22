# Omnigo web sitesi

`omnigo-blog` mevcut WordPress/Astra/Elementor sitesine özel modüldür. SaaS yönetici paneline blog ekranı eklemez.

- Blog: `/blog/`; kategori, arama ve 9 yazılık sayfalama.
- Yazı: `/blog/yazi-adresi/`; kapak, tarih, içerik ve ilgili yazılar.
- Yönetim: WordPress → Blog → Yeni blog yazısı. WordPress'in taslak, önizleme, yayımlama, yayından kaldırma ve sürüm geçmişi kullanılır.
- SEO alanları düzenleyicide **Meta kutuları → Arama motorları ve sosyal paylaşım** altındadır. Boş alanlar başlık, özet, kalıcı bağlantı ve kapaktan tamamlanır.
- Yalnızca yayımlanan içerikler mevcut SureRank sitemap sağlayıcısına dahil edilir. Taslak ve önizlemeler noindex ve yetki kontrolü altındadır.
- İçerik `wp_kses_post`, alanlar tür ve uzunluk denetimi, kayıt işlemleri WordPress yetkisi ve nonce ile korunur.
- WordPress'in mevcut posts/postmeta/terms tablolarını kullanır. Yeni tablo, migration veya veritabanı yetkisi gerekmez.
- Müşteri portalı tanıtımı dokuz güncel özellik grubunu, hesaba göre izinleri ve üç sentetik veri ekran görüntüsünü gösterir. Demo bağlantısı `/demo/` adresindeki güncel ortak müşteri görünümüne gider.
- Masaüstü ve mobil menü sırası: Ana Sayfa, Uzmanlık Alanlarımız, Omnigo Yaklaşımı, İş Birliği Modelimiz, Müşteri Portalı, Hakkımızda, İletişim, Blog.
- `assets/site-design.css`, mevcut web sitesinin yazı tipi, renkleri, kompakt üst menüsü ve alt bölümünü blog ve portal tanıtım şablonlarına uygular. Bu sayfalar aynı üst/alt şablonları kullanır; masaüstü menüsü 1200 px altında açılır menüye geçer.
- SureRank site haritası kendi önbelleğini kullanır. Gerekirse WordPress → SureRank → Advanced → Sitemaps → Regenerate ile yenilenir. Yayınlama ve taslağa çevirme sonrasında örnek yazının haritaya eklenmesi/çıkarılması doğrulanmıştır.

Eklentiyi etkisizleştirmek şablon ve menü filtrelerini kaldırır. Mevcut sayfa içeriği ve blog kayıtları silinmez. Sayfa içerikleri yerine kapsamı sınırlı şablon/menü filtreleri kullanılır.

Kontrol: `php wordpress/tests/navigation.php`; PHP dosyalarında `php -l`.

## Web sitesi bakımı — 1.1.0

- `SitePages`: kurumsal sayfalara özel SEO açıklamaları, web gizlilik sayfası, eski `/contact/` adresinin görüşme formuna yönlendirilmesi ve ortak 404 şablonu.
- `SiteContent`: yalnızca bilinen halka açık web sayfalarının çıktısını işler. Açılış animasyonunun HTML/CSS/JS bloklarını ve görünmeyen eski tema üst/alt alanlarını kaldırır; Hakkımızda şirket bilgilerini ve formun başına aydınlatma bağlantısını ekler. Yönetim, API, besleme ve portal uygulamaları bu kapsamda değildir.
- `Images`: dosya manifestinde yer alan 13 görsel için boyutlandırılmış WebP seçenekleri, `srcset`/`sizes` ve anlamlı alternatif metinler. Orijinal medya dosyaları korunur. Yeni görseller manifest dışında normal WordPress davranışını kullanır.
- `Sitemap`: SureRank’in yetki/yayın/noindex süzmesini korur, iç şablon türlerini haritadan çıkarır. İlk sayfa haritasına `/blog/` ve `/gizlilik-ve-kisisel-veriler/` eklenir. Önbellek SureRank → Advanced → Sitemaps → Regenerate ile yenilenebilir.
- Gizlilik ve şirket metinleri `templates/privacy.php` ve `templates/company-details.php` içindedir. Yalnızca doğrulanmış işletme bilgileri yayımlanır.
- Canlı WordPress’te varsayılan örnek yazı çöp kutusuna taşındı; SureRank’te Floating Elements, Şablonlarım, Elementor Header & Footer Builder ve Forms türleri noindex olarak kaydedildi. Gerçek sayfalar ve blog yazıları index olarak korundu.

Yerel kontroller: `php wordpress/tests/navigation.php` ve `php wordpress/tests/website-content.php`.
14.10.2026 canlı kontrol kaydı: `verification/website-fixes-2026-10-14/`. Bu klasör yayın öncesi web modülü yedeğini, sayfa/SEO/site haritası kontrol sonuçlarını ve ekran görüntülerini içerir; Git’e dahil edilmez.

## Hizmetler ve referanslar — 1.1.3

- Ana sayfa ve Uzmanlık Alanlarımız sayfasında e-ticaret sitesi, mobil satış uygulaması ve kurumsal web sitesi hizmetleri tanıtılır. Mevcut pazaryeri hizmetleri korunur.
- `/referanslarimiz/` kurumsal şablonları kullanan bağımsız bir genel sayfadır; ana/mobil/alt menülere ve site haritasına eklenir.
- Vizyona için yalnız kullanıcı tarafından belirtilen proje kapsamı ve herkese açık `https://www.vizyonagrup.com/` bağlantısı kullanılır. Özel şirket/finans/müşteri bilgileri, panel görüntüleri veya mağaza fiyatları okunmaz ve yayımlanmaz. Mobil uygulama mağaza bağlantısı ve tamamlanma iddiası eklenmez.
- `ServicePortfolio::prepare()` şablonları WordPress çıktı tamponu başlamadan hazırlar; çıktı işleyicisi yalnız hazır HTML'yi ekler. İçerik ve stil eklemeleri kapsamlıdır; portal uygulamaları bu modüle dahil değildir.
- Kontroller: `wordpress/tests/service-portfolio.php`, `navigation.php`, `sitemap.php`, `website-content.php`, `site-tuning.php`, `social-links.php`.
- Kaynak yedeği ve canlı kontrol kanıtları: `verification/website-references-20261022/`. Uzak özel kaynak yedeği: `storage/releases/website-references-20261022-before.json`.

## Vizyona referans görselleri — 1.1.4

- Gerçek marka logosu ve fiyat/özel bilgi içermeyen e-ticaret ana sayfa ekran görüntüsü kullanılır. Mobil açılış ve kategori menüsü görselleri Vizyona Online proje çıktılarından alınır ve tasarım önizlemesi olarak açıklanır; mağazada yayımlanma iddiası yoktur.
- E-ticaret, mobil uygulama, kurumsal web ve pazaryeri çalışmalarının kapsamı ayrıntılandırılır. Görseller `assets/references/` altında yerel sunulur; portal verisi kullanılmaz.
- Yayın öncesi kaynak yedeği ve doğrulama: `verification/website-reference-gallery-20261022/`. Uzak özel yedek: `storage/releases/website-reference-gallery-20261022-before.json`.

## Kurumsal site görseli — 1.1.5

- E-ticaret sitesi, mobil uygulama ve kurumsal site üç ayrı görselli bölüm olarak sunulur. Kurumsal görsel, mevcut LocalWP projesinin Markalarımız sayfası kaynaklarından alınmış önizlemedir; canlı adres iddiası yoktur.
- Görselde yalnız tanıtım metinleri ve marka kartları bulunur. İletişim alanları, şirket bilgileri ve fiyatlar görüntüye dahil edilmez. Yerel kaynak dosyaları değiştirilmez.
- Yayın yedeği: `verification/website-corporate-gallery-20261022/`.

## Referans görsel önizlemesi — 1.1.6

- Yalnız referans sayfasında yüklenen `reference-lightbox.js`, beş görsele klavye erişimli büyük önizleme ekler. JavaScript yoksa mevcut görseller korunur.
- Yerel dialog, ekran ölçülerine göre görseli sığdırır; Kapat, Escape ve dış alana dokunma ile kapanır. Arka sayfa kaydırması kilitlenir, kapanınca konum ve klavye odağı geri yüklenir.
- Edge ve WebKit'te dik/yatay mobil ve masaüstü ölçülerinde açma/kapatma, görsel yüklenmesi, taşma ve odak kontrolleri yapılır. Yayın öncesi kaynak yedeği: `verification/website-reference-lightbox-20261022/`.

## Web sitesi denetimi sonrası tamamlama — 1.2.0

- WebsiteReview: dört hizmete erken erişim; Hakkımızda/İş Birliği kapsamı ve web/mobil teslim-sorumluluk adımları. Ek içerikler çıktı tamponundan önce hazırlanır.
- Form: isteğe bağlı hizmet seçimi mevcut ihtiyaç açıklamasına aktarılır; alanların zorunluluğu, hata odağı ve aria-invalid işaretleri tamamlanır. Form gönderim yöntemi ve alıcı yapılandırması değiştirilmez.
- Kontrastlar marka paleti içinde düzeltilir; mevcut kurumsal şablonlarda kullanılmayan Google font stilleri çıkarılır.
- Referanslara doğrulanmış proje durumları, amaç ve fiyat/özel bilgi içermeyen ek kategori önizlemesi eklenir. Gerçek ekip fotoğrafı ya da müşteri yorumu uydurulmaz.
- EditorialContent: üç özgün hizmet rehberini WordPress bloguna bir kez, kilitli ve idempotent olarak ekler. Var olan yayımlanmış yazıları değiştirmez; başarısız kısmi taslak yayınını tekrar tamamlayabilir.
- `public/demo/`: üretim oturum akışından ayrı giriş anlık görüntüsü, yalnız sentetik veri sağlayıcısı. Gerçek API, bildirim ve senkronizasyon servisleri import-map/CSP ile yalıtılmıştır. Üretim `public/app.js` dosyası değiştirilmez. Üretim arayüz modülleri yenilendiğinde demo gezinti testi tekrar çalıştırılmalıdır.
- Yedek ve yayın kontrolleri: `verification/website-completion-20261022/`. Sunucuda özel yedek: `storage/releases/website-completion-20261022-before.json`.

### Son yayın doğrulaması — 1.2.1

- Elementor'un geç bastığı kullanılmayan font etiketleri de kurumsal çıktı sınırında temizlenir. Blog başlığı ve SEO açıklaması web/mobil hizmetlerle tutarlıdır.
- Yayın sonrası 17 gerçek sayfa ve 404 ekranı, değişen altı sayfada Edge/WebKit ile 48 görünüm kontrolü, dört blog/rehber erişilebilirlik kontrolü ve iki tarayıcıda yedi demo bölümü doğrulandı.
- `completion-summary.json` ve `Tamamlama-Raporu.html` doğrulama sonuçlarını içerir. Gerçek e-posta gönderilmedi; form POST isteği engellenerek hizmet bilgisinin serileştirilmesi doğrulandı. Fiziksel cihaz testi yapılmadı.
