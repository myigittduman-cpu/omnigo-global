# Operasyon tabloları ve bağlantı kesintisinden kurtarma — 0.9.66

17 Ekim 2026 tarihinde yönetici ve müşteri sevkiyat ekranları, yönetici teslimat evrakları ve ortak belge erişim kuralları kontrol edildi. Siparişler üzerinde elle durum/tutar değişikliği, belge yükleme veya silme yapılmadı.

## Yedek ve değişiklikler

- Şifreli canlı yedek doğrulandı: `portal-20261017-160402-10861d86.ogbackup`, 5.013.319 bayt; SHA-256 `2ca790214d32ca469e9e9b9f0a8f5f1f839ca94413b874eaa7deae84e6acdd5b`.
- Beş mevcut kaynak dosyasının yerel ve özel sunucu geri dönüş kopyası alındı. Yedekler `data/source-backups/operational-integrity-before-20261017/` ve `verification/operational-integrity-20261017/source-before.json` altında.
- n11, idefix ve Pazarama hızlı sipariş taramasının 13 gün öncesini kesen alt sınırı kaldırıldı. `RecentOrderWindow`, son tamamlanmış noktadan başlayarak kaynak sınırına uygun 13 günlük, 10 dakika örtüşen aralıklar üretir. Yarım kalan sayfa ve pencere korunur; sonraki aralık bugüne atlamaz. Tanımlı başlangıç tarihi korunur.
- Dakikalık sipariş adaptörlerinde geçici bağlantı/sunucu hatası sonrası sonraki deneme 60 saniye sonrasına planlanır. Dakikalık cron nedeniyle normal yeniden deneme yaklaşık 1–2 dakika içinde başlayabilir. Kaynağın `Retry-After` talebi ve 429 hız sınırı beklemesi kısaltılmaz. Sürüm çakışması (409) yeniden denenebilir kabul edilir.
- Yetki hataları (401/403) ve kalıcı veri doğrulama hataları kör tekrar döngüsüne alınmaz; mevcut yönetici bağlantı/mutabakat uyarıları korunur.
- Evrak ekranında eksik Hepsiburada etiketi düzeltildi. Başarılı, boş sipariş kontrolünde son okuma zamanı eski son sipariş zamanı gibi gösterilmiyor.
- Veritabanı şeması ve kullanıcı erişim kapsamı değişmedi. Teslimat evrakı üretme menüsü mevcut tasarımda yöneticiye özeldir; müşteri kendi şirketinin ortak sevkiyat tablosuna ve satır belgelerine erişir.

## Canlı doğrulama

Yayımdan önce ve sonra beş pazaryeri için mevcut API mutabakat referansları ile operasyon kayıtları karşılaştırıldı. Aktarım kapsamına giren kayıtlarda eksik, mükerrer, tutar veya durum farkı bulunmadı.

| Ekim 2026, tüm şehirler | Yönetici | Müşteri |
|---|---:|---:|
| Hepsiburada | 42 | 42 |
| n11 | 21 | 21 |
| idefix | 22 | 22 |
| Pazarama | 12 | 12 |
| Koçtaş | 5 | 5 |

- Mevcut ayların tamamında 870 sevkiyat satırının sayfa sırası, kayıt sayısı ve dışa aktarım sonucu eşleşti. Yayımdan önceki/sonraki kayıt sayıları ve fatura tutarı toplamları değişmedi.
- 71 aktif teslimat kaydı, 57 kargodaki sipariş ve bu kontrol anında 0 geciken sipariş doğrulandı. Bunlar canlı sipariş durumuna göre değişebilir.
- Mükerrer teslimat/sevkiyat ve boşa çıkan evrak bağlantısı: 0.
- İki arşiv teslimat evrağı okunabildi. Bu şirkette yüklenmiş satır PDF/görsel belgesi bulunmadığından canlı dosya içeriği karşılaştırması yapılamadı; yükleme/okuma, iki rol, şifreleme ve çakışma davranışları yerel testlerde doğrulandı.
- Yönetici mobil genişlikte, müşteri masaüstü genişlikte tarayıcı üzerinden kontrol edildi; her beş pazaryerinin Ekim listesi aynı sayıları verdi. Tarayıcı konsollarında hata yoktu. Bu turda fiziksel Android/iOS cihaz testi yapılmadı.

## Testler ve sınırlar

25 PHP test grubu, 9 JavaScript testi ve değişen dosyaların sözdizimi kontrolleri geçti. Yeni test; 60 günlük kesinti, ikinci sayfada 503, aynı sayfadan devam, tekrar gelen siparişin üç tabloyu çoğaltmaması ve zorunlu bekleme süresini kapsıyor. Mevcut testler elle düzenleme, silinen satırın korunması, müşteri izolasyonu, PDF, renk/durum, iptal, arama, sayfalama, bildirim ve mutabakat davranışlarını kapsıyor. Yerel PHP testlerinde eksik `fileinfo` eklentisi mevcut DLL ile test sürecinde yüklendi; sunucu ayarı değiştirilmedi.

Mutabakatın otomatik sevkiyat kapsamı mevcut altı il ve 11 Ekim 2026 06:03:02 Türkiye saati başlangıcıdır. Önceden elle aktarılan tarihsel kayıtlar, kullanıcı tarafından kilitlenen alanlar ve bilinçli silinen satırlar korunur. Geçmiş manuel satırların her alanının pazaryeriyle yeniden doğrulandığı iddia edilmez. n11 ve idefix satıcı panellerindeki ertelenmiş görsel satış karşılaştırması bu çalışmaya dahil edilmedi.

Kaynak API veriyi hiç sunmazsa, geç sunarsa, erişim yetkisi kaldırılırsa veya zorunlu hız sınırı uygularsa tamamlama anı garanti edilemez. Uzun kesintide biriken çok sayfalı kayıtların bitmesi birden fazla çevrim sürebilir. Sağlanan güvence; başarılı kayıt sonrası ilerleyen imleç, tekrar güvenli yazım, atomik tablo güncellemesi, kalıcı yeniden deneme ve mevcut yönetici mutabakat uyarılarıdır.

## Temizlik ve kanıtlar

Geçici yedek/audit PHP dosyaları kendini kaldırdı; bunlara ait üç geçici cron görevi kaldırıldı. Önceden mevcut cron görevleri korundu. Kayıtlar `verification/operational-integrity-20261017/` altında: `backup.json`, `before.json`, `after.json`, `release-checks.json`, `ui-checks.json`, `deployed.json`, `final-validation.json` ve `admin-delivery.png`.
