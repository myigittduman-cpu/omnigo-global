# Herkese açık müşteri demosu

Adres: `https://portal.omnigoglobal.com/demo/`

Demo gerçek müşteri arayüzünü (`app.js` ve mevcut modüller) kullanır. HTML import map yalnızca demo sayfasında `api.js` yerine ağ erişimi olmayan örnek veri sağlayıcısını, bildirim/canlı senkronizasyon servisleri yerine etkisiz uygulamaları seçer. Mevcut uygulama kaynakları ve üretim veritabanı değiştirilmez.

- Tek örnek firma: Örnek Ev & Yaşam. Sabit müşteri rolü; yönetici sayfası yok.
- Veriler kodla üretilir. Gerçek sipariş, müşteri, telefon, vergi numarası, e-posta veya erişim anahtarı içermez.
- Satışlar, stoklar, siparişler, iadeler, teslimat listeleri, analizler, çalışmalar, hakedişler ve destek görüşmeleri incelenebilir. Filtreler ve ayrıntı ekranları yalnız örnek kayıtları kullanır; raporlar örnek veriden üretilir.
- Düzenleme, belge yükleme, mesaj gönderme, gerçek oturumu kapatma ve bilinmeyen API yolları reddedilir. Hiçbir demo verisi sunucuya veya tarayıcı depolamasına kaydedilmez.
- Demo için tanımlanan CSP yalnızca yerel görsel/rapor varlıklarına fetch izni verir; üretim API'si için connect izni yoktur. Cihaz kaydı, arka plan bildirimi ve canlı yenileme başlamaz.
- Yalnızca müşteri alan adında çalışır. Arama motorları için noindex; yeni demo service worker kurulmaz.

`node tools/build-public-demo.mjs` güncel `public/app.js` dosyasından demo kabuğunu, tüm ortak stil bağlantılarını, HTML import map ve buna eşleşen CSP hash'ini üretir. Üretim giriş denetimi değişmez; yalnız üretilen demo kabuğu örnek oturumla açılır. Map değiştiğinde HTML ve CSP birlikte dağıtılmalıdır. `node --test tests/public-demo.test.js` veri/erişim sınırlarını ve kabuğun güncel kaynakla aynı kaldığını kontrol eder. Üretim arayüzü yeni bir API yolu eklerse demo sağlayıcısına örnek karşılığı açıkça eklenmelidir; bilinmeyen yollar gerçek sunucuya yönlendirilmez.

2 Kasım 2026: 11 menü, üç ekran ölçüsü (masaüstü, mobil dikey/yatay), sipariş ve stok ayrıntıları doğrulandı. Web sitesindeki üç görsel gerçek demo ekranından alınmıştır; üretim müşteri verisi içermez. Yayın öncesi yedek ve kontroller `verification/portal-website-20261102/` içindedir.
