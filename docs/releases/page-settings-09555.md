# Sayfa oluşturucu ve genel ayarlar — 0.9.555

22 Kasım 2026: GitHub'da 1e23202 ve 5caf2f7 ile doğrulanan sayfa oluşturucu, menü, demo ve genel ayar kaynakları iki portalın ortak sunucusuna yayımlandı. Canlıdaki 0.9.553 yedek şeması düzeltmesi korundu; bu yayında veritabanı migrasyonu veya yetki değişikliği yapılmadı.

## Yayın doğrulaması

- Güncel doğrulanmış şifreli veri yedeği kontrol edildi. Kaynakların işlem öncesi kopyaları yerelde ve web kökü dışındaki storage/releases altında tutuldu.
- Yalnız kaynak farkları aktarıldı; yeni bağımlılıklar önce, uygulamalar ve sürüm işareti sonra yüklendi. Her dosya sunucudan tekrar okunup SHA-256 ile doğrulandı.
- İki alan adında toplam 46 HTTPS kontrolü geçti: kaynak içerikleri, sürüm, servis durumu ve oturumsuz yönetim/okuma isteklerinin reddedilmesi.
- Demo yalnız müşteri alan adında erişilebilir. Yönetici alan adındaki beklenen 403 ilk doğrulamada yanlışlıkla hata sayıldı ve 0.9.554 otomatik geri alındı. Kontrol düzeltildikten sonra 0.9.555 başarıyla yayımlandı.

## Ekran kontrolleri

- Gerçek yönetici oturumunda genel ayarlar, uygulama yönetimi ve sayfa oluşturucu açıldı; boş yayın durumu okundu. Kaydedilmeyen yeni sayfanın önizlemesi ve düzenleyiciye dönüşü doğrulandı. Masaüstü ve mobil dikey/yatay oluşturucu sınırları kontrol edildi.
- Gerçek müşteri oturumunda hesap/tema ayarları açıldı; yönetim ve oluşturucu girişi görünmedi. Müşteriye ait çalışma alanı yüklenmeye devam etti.
- Canlı demoda Mağaza Rehberi ve Operasyon Notları seçilen menülerden açıldı; demo konsolunda hata gözlenmedi.
- Kontrol sayfası yalnız tarayıcı belleğinde oluşturulup kaldırıldı. Canlı taslak veya yayın kaydedilmedi; test için müşteri verisi, pazaryeri işlemi veya ortak tema değiştirilmedi. Yayımlanmış özel sayfa sayısı kontrol sırasında sıfırdı.

Önceki yerel doğrulama: 804 JavaScript testi, iki özel sayfa/portal HTTP testi ve seçili PHP regresyonları geçti. Canlı yazma/yayımlama/geri alma testi yapılmadı; bu akışlar sentetik verilerle doğrulandı.
