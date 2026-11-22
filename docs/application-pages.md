# Sayfa oluşturucu

Uygulama Yönetimi → Menüler ve sayfalar → Sayfa ve Modül Oluşturucu üzerinden kullanılır. Mevcut uygulama yönetimi yetkisi korunur; diğer yönetici ve müşteri hesapları taslak yönetemez.

## Saklama ve kapsam

`ApplicationPageStore`, `application_preferences` tablosunda taslak, yayın ve önceki yayın için üç sürümlü kayıt tutar. Yayınlama ve geri alma tek işlem içinde sabit sırayla kilitlenir; eski sürümler 409 ile reddedilir. Yayımlanan taslağın sürümü tüketilir; aynı yayın isteği tekrar uygulanamaz. Boş yayın da geri alınabilir.

Müşteri okuması yalnız yayımlanan kaydı yükler. Rol (`admin`, `client`, `both`) ve mağaza (`all`, `selected`) sınırları sunucuda uygulanır. İstemci menüsü aynı kapsamı tekrar denetler. Taslak metni müşteri yanıtına eklenmez. Yeni sayfalar `customPage.<menü-bölümü>.<kısa-ad>` anahtarlarıyla seçilen bölüme eklenir; mevcut modül yetkileri genişletilmez.

En fazla 24 sayfa, sayfa başına 8.000 karakter içerik ve 1.000 seçili mağaza desteklenir. Yönetim taslak isteği 1 MiB ile sınırlıdır. Metinler HTML olarak çalıştırılmaz. Bağlantılar yalnız HTTPS veya tek `/` ile başlayan uygulama yollarıdır; protokol bağımsız, ters eğik çizgili, kontrol karakterli ve kullanıcı/parola içeren adresler reddedilir.

## Arayüz ve demo

Kaydetme öncesi form değişiklikleri yerel taslakta korunur. Yeni sayfalar benzersiz kısa ad alır. Kaydetme/yayın sırasında ikinci yazma başlatılmaz; pencere kapatma ve kaydedilmemiş değişiklik koruması ortak bileşeni kullanır. Son sayfa silinince boş taslak kaydedilebilir ve yayımlanabilir. Başarısız kayıt sonrasında eski taslak yayımlanmaz.

Sayfa servisi geçici olarak kullanılamazsa diğer çalışma alanları açılır; oturum ve erişim hataları gizlenmez. Yayınlama/geri alma sonrasında mevcut çalışma alanı yenilenir. Diğer açık cihazlar normal yenilemelerinde güncel yayınla beslenir.

Demo iki sentetik sayfa gösterir. Demo API başka mağaza okumalarını, yönetim uçlarını ve yazma işlemlerini reddeder; gerçek API'ye geçiş yapmaz.

## Şema ve test

Yeni kurulum veya mevcut MySQL şemasını hazırlama işlemi yetkili dağıtım sırasında mevcut `php/bin/migrate.php` akışından yapılır. `AccountSettingsSchema`, içerik sütununu yalnız gerektiğinde LONGTEXT'e genişletir. Bu GitHub kaydı canlı migrasyon veya dağıtım çalıştırmaz. SQLite testleri uzun UTF-8 içeriği kapsar; üretim MySQL migrasyonu ayrı test ortamında doğrulanmalıdır.

```powershell
node --test tests/*.test.js
& $env:PHP_BINARY php/tests/application-pages.php
node --test php/tests/application-pages-http.mjs
```

HTTP testi ayrı geçici SQLite ve localhost PHP sunucusu kullanır. Gerçek müşteri verisi, pazaryeri bağlantısı, üretim yapılandırması veya e-posta gönderimi kullanılmaz.
