# Omnigo yönetici ve müşteri portalı

Canlı uygulama PHP 8.2+ ve MySQL/InnoDB kullanır. İki portal ortak `public/` arayüzünü ve firma bazında yetkilendirilmiş API'yi kullanır. Windows kabuğu `desktop/`, Android kabuğu `android/` altındadır. iOS Safari üzerinden ana ekrana eklenebilir.

`src/` altındaki Node/SQLite uygulaması eski yerel prototiptir; canlı sunucuyu başlatmaz. Eski açıklamalar [arşivde](docs/legacy-prototype.md) saklanır.

## Kod düzeni

- `php/src/`: servisler, adaptörler, yetkilendirme, kuyruk ve veri erişimi.
- `php/bin/`: CLI işçileri, şema, yedekleme ve kurtarma.
- `public/`: ortak arayüz ve belge/bildirim işlemleri.
- `php/tests/`, `tests/`: bağımsız verilerle güvenlik ve işlev testleri.
- `tools/load/`: yalnızca loopback ve geçici SQLite üzerinde yük testi.
- `tools/backup/`: Windows hesabıyla korunan kurtarma anahtarı ve sunucu dışı kopya.
- `tools/operations/`: test çalıştırıcısı, Git ve yetkili cPanel araçları.

Üretim yapılandırması, anahtarlar, belgeler, müşteri verileri ve derleme çıktıları Git dışındadır. Başka bilgisayarda bunlar kaynak kodundan türetilemez; yetkili yedek ve ayrı yapılandırma gerekir.

## Testler

Node 24+ ve PHP 8.2+ gerekir. PDO SQLite/MySQL, OpenSSL, mbstring, fileinfo ve GD kullanılır. Push bağımlılıkları `php/composer.lock` ile sabitlenmiştir; `composer install --no-dev --working-dir=php` ile kurulur.

```powershell
$env:PHP_BINARY='C:/guvenli/php/php.exe'
$env:OPENSSL_CONF='C:/guvenli/php/extras/ssl/openssl.cnf'
node tools/operations/regression.mjs
node tools/load/run.mjs
```

Yük testi 4 PHP işçisi, 5.000 sentetik sevkiyat ve 1.000 sentetik sipariş kullanır. Gerçek pazaryerine bağlanmaz. Sonuçlar Git dışında `verification/resilience/` konumundadır. Yerel SQLite sonuçları canlı MySQL kapasite garantisi değildir.

## Sürüm kontrolü

Yerel `main` deposu kuruludur. `portal-v0.9.40` başlangıç noktasıdır. `.githooks/pre-commit` özel veri yollarını ve bilinen erişim anahtarı biçimlerini denetler; elle incelemenin yerine geçmez. `example.test` hesapları sentetiktir.

Windows klasör sahipliği farkını yalnızca bu komut süresince ele alan proje komutu:

```powershell
node tools/operations/git.mjs status
node tools/operations/git.mjs log --oneline -5
node tools/operations/git.mjs diff
```

Kaynak dosyalarını adlarıyla ekleyin; çalışma klasörünü zorla eklemeyin. Uzak Git deposu tanımlanmadı. Genel Git güven ayarı değiştirilmez. Yerel commit kimliği `Omnigo Yerel Surum Kontrolu` olarak ayarlanmıştır. Geri dönüşte önce farkları inceleyin; çalışan dosyaların üzerine körlemesine eski sürüm yazmayın.

Yedekleme ve kurtarma için [kılavuza](php/YEDEKLEME.md) bakın.
