# Otomatik sipariş güncellemeleri — 0.9.71

18 Ekim 2026: Yönetici ve müşteri portalının mevcut yetkileri korunarak, beş aktif pazaryerinin sipariş verilerinin bütün ilgili ekranlara yansıması kontrol edildi. Hepsiburada, n11, idefix, Pazarama ve Koçtaş kapsamdadır; pasif bağlantılar açılmadı.

## Değişiklikler

- Teslimat Evrakları'nda elle sipariş seçmek artık otomatik yenilemeyi kalıcı durdurmuyor. Mevcut geçerli seçim korunuyor; iptal edilen, listeden çıkan veya zorunlu bilgileri eksilen sipariş seçimden çıkarılıyor. Yeni sipariş listede görünüyor ancak kullanıcının elle değiştirdiği evrak seçimine kendiliğinden eklenmiyor. Otomatik tümünü seçme düzeni korunuyor.
- Sipariş ayrıntısı kapandıktan sonra sipariş düğmesinde kalan odak artık liste yenilemesini engellemiyor. Tarih/pazaryeri filtre taslakları ve açık ayrıntı ekranı korunuyor.
- Veritabanı, API kimlik bilgileri, müşteri yetkileri, kayıtlı siparişler, evraklar ve sevkiyat renkleri değiştirilmedi.

## Doğrulanan akış

- Beş ayrı pazaryeri sipariş işçisi dakikalık cron üzerinden çalışır; iki uygulamanın da açık olması gerekmez.
- Yeni siparişler ve durumları aynı aktarım işlemi içinde sipariş/teslimat/sevkiyat kayıtlarına yazılır. Mevcut kayıt aynı kaynak kimliğiyle güncellenir, ikinci sipariş oluşturulmaz.
- n11, idefix ve Pazarama satış/iptal/iade taramaları sınırlı sayıda sayfayı işler; yalnız tamamlanmış tarama grafik/rapor toplamlarına yayımlanır. Hepsiburada iade taraması ayrı kuyrukla ilerler. Koçtaş değişiklik zamanına göre okur.
- İki panel aynı sunucu verisini kullanır. Açık sayfalarda 30 saniyelik veri yenilemesi, sevkiyatlarda küçük sürüm sorgusu ve bağlantı geri geldiğinde kontrol vardır. Yazı yazma, filtre taslağı, dosya hazırlama ve açık formlar sırasında kullanıcı işlemi korunur; işlem bitince yenileme devam eder.
- Hedef normal koşullarda yaklaşık 1–2 dakikadır. Kaynağın veriyi API'ye geç iletmesi, kesinti, kota beklemesi veya uzun geçmiş aktarımı bu süreyi uzatabilir; kesin süre garantisi verilmez. Kaynak Retry-After talimatı korunur. Kesinti sonrası son başarılı aralıktan devam edilir.
- Eski PDF/Excel dosyaları kendiliğinden yeniden yazılmaz. Yeni indirilen evrak güncel ve sunucuda sürümü doğrulanmış seçimden üretilir.

## Kontroller ve geri dönüş

Şifreli veri yedeği: `portal-20261017-225902-38ccb32f.ogbackup`, 5.022.827 bayt. SHA-256: `385cce0ec45c3516356fde8b9cc5bb2601c050e5661660ee0b7708475ef8667d`. Yerel ve sunucu kaynak kopyaları ayrıca alındı.

17 JavaScript testi ve 11 PHP test grubu geçti. Kapsam: beş pazaryeri aktarımı, yeni sipariş, tam/kısmi iptal, iade, iki rolün satış eşitliği, üç operasyon tablosu, kesintiden devam, tekrar koruması ve elle yapılan seçimlerin korunması. Test verileri yerel ve ayrıdır; gerçek sipariş oluşturulmadı/iptal edilmedi.

02:00 Türkiye saati canlı kontrolünde beş sipariş bağlantısı sağlıklı; son başarılı okumalar 26–58 saniye önceydi. Beş pazaryerinin kayıtlı otomatik mutabakatında eksik/mükerrer/tutar/durum/dönem farkı sıfırdı. Kaynak dosyaları yükleme sonrası sunucudan okunup hash ile doğrulandı. Kanıtlar: `verification/live-updates-0971-20261018/`.

02:02 son kontrolünde beş aktarım zamanı ilerledi; mutabakat farkları yine sıfır. 420 teslimat siparişi, 927 sevkiyat satırı ve 2 evrak sayısı korunuyor. Satış toplamları, kayıtlı evraklar ve hakedişler aynı kaldı. Bu sırada gerçek idefix aktarımı bir siparişin paket/kargo durumunu güncelledi: 02:01:13 `shipment.auto_update` kaydı var. Bu beklenen otomatik değişiklik nedeniyle sipariş/sevkiyat içerik hashleri değişti; eski durum geri yüklenmedi.

Canlı yönetici ekranında 28 siparişten biri seçilmemişken otomatik yenileme izlendi. Seçim 27 olarak kaldı, Hepsiburada okuma saati 02:01:36'dan 02:02:36'ya ilerledi. Test bittikten sonra varsayılan seçim geri getirildi. Müşteri oturumu da yeni sürümde açıldı. Geçici dört kontrol dosyası/cron kaldırıldı; sekiz kalıcı cron korundu.

## Hakediş sınırı

Hakediş, sipariş/satış toplamından ayrı ödeme verisidir. Önceki denetimde Hepsiburada ve n11'in eski elle girilmiş kayıt kullandığı, idefix için hakediş kaydı olmadığı, Pazarama'da tarihsiz 10.900,37 TL'nin takvim dışında kaldığı bulundu. Pazarama finansı 6 saat, Koçtaş finansı günlük aralıkla çalışır. Bu sürüm yeni finans entegrasyonu kurmaz veya tahmini ödemeyi banka teyidi saymaz. Ayrıntılar `sales-cards-payout-audit-0970.md` içindedir.
