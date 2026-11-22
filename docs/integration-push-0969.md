# Yönetici bağlantı bildirimleri — 0.9.69

18 Ekim 2026, Türkiye saatiyle 01:20 civarında yayımlandı. Önceden bekletilen 0.9.67 taslağı, canlıdaki 0.9.68 satış düzeltmeleri korunarak 0.9.69 olarak tamamlandı.

## Davranış

- Sipariş işçisi başarısız denemeyi kaydettiğinde kesinti olayı kalıcı bildirim kuyruğuna eklenir. Aynı açık kesinti tekrar bildirilmez.
- Düzelme için yalnız başarılı API yanıtı yeterli değildir. Bekleyen sayfalar ve siparişler tamamlanmalı, kesinti sonrasına ait güncel kaynak kanıtı bulunmalı, Siparişler / Teslimat Evrakları / Şehir Dışı Sevkiyat mutabakatında fark kalmamalıdır.
- Bu kanıt oluşunca tek düzelme olayı üretilir. Kontroller sunucudaki mevcut cron görevleriyle, uygulamalar kapalıyken de çalışır.
- Android yönetici bildirimleri kesinti ve düzelmeyi ayrı başlıklarla gösterir; dokunulduğunda bağlantı kontrolüne gider. Müşteri cihazı bu olayların alıcısı değildir. Kilit ekranına müşteri, adres, sipariş tutarı veya erişim bilgisi gönderilmez.
- Bildirim gönderim hatası sipariş aktarımının sonucunu veya tekrar deneme planını değiştirmez. Mevcut bildirim kuyruğunun yeniden deneme ve cihaz yetkilendirme kontrolleri korunur.
- Hepsiburada satış işinin sağlık kontrolü kendi mali aktarım kanıtını kullanır.

## Yedek ve dağıtım

- Güncel şifreli yedek: `portal-20261017-221902-d5e0f6dc.ogbackup`, 5.022.720 bayt. Oluşturma zamanı 18 Ekim 2026 01:19:03 Türkiye; dosya adı UTC kullanır.
- Bütünlük: `268bd52c96bf663becdd699eaaac7938767a55339dd043f1a3453783e0c8ebdc`.
- Altı mevcut kaynak dosyasının yerel geri dönüş kopyası `data/source-backups/integration-push-before-20261018/`; sunucu kopyası özel `storage/releases/integration-push-before-source20261018.json` konumunda.
- Dağıtım yedi dosyayla sınırlı: yeni `IntegrationRecoveryEvidence`, mevcut `IntegrationAlertMonitor`, `IntegrationHealthSnapshot`, `MobilePushMessage`, `SyncQueue`, `orders-worker` ve sürüm dosyası.
- Veritabanı migration uygulanmadı. Sekiz kalıcı cron korundu; geçici yedek/kontrol dosyaları ve görevleri kaldırıldı.

## Kontrol sonucu

- 21 PHP test grubu, 10 JavaScript testi ve altı PHP sözdizimi kontrolü geçti.
- Kesinti, tekrarsızlık, yarım sayfa, eksik sipariş/evrak/sevkiyat, tutar farkı, doğrulanmış toparlanma, müşteri cihazının dışlanması ve bildirim hatasının sipariş tekrarından ayrılması test edildi.
- Canlı beş pazaryerinin mutabakatında sıfır fark bulundu; yeni toparlanma kanıtı kontrolü mevcut tamamlanmış aktarımlarda geçti. Canlı pazaryeri bağlantıları test amacıyla kesilmedi.
- Önce/sonra karşılaştırmasında 420 teslimat kaydı, 927 sevkiyat satırı ve iki evrak kaydının sayısı ve tam içerik SHA-256 özeti değişmedi. Bu sayılar arşivlenmiş/silinmiş işaretli satırları da içerir.
- 0.9.68 n11 tarih aralıkları ve satış özeti dosyaları canlı içerik hashleriyle korundu.
- Kanıtlar: `verification/integration-push-20261018/` altında `backup.json`, `release-checks.json`, `before.json`, `after.json`, `final.json`, kaynak geri dönüş ve temizlik raporları.

## Telefonda doğrulama tamamlandı

Kullanıcı Yönetici uygulamasında bildirimleri yeniden etkinleştirdi. 18 Ekim 2026 01:29 Türkiye saatinde sunucuda gerçek yönetici Android cihazının (id 4, `com.omnigoglobal.admin`) etkin ve kullanılabilir olduğu doğrulandı. Cihazın yetkisi veya oturumu veritabanından değiştirilmedi.

Kullanıcı uygulamadan telefonun ana ekranına dönüp ekranı kilitledikten sonra yalnız bu cihaza iki DENEME bildirimi gönderildi: “Bağlantı sorunu” ve “Bağlantı düzeldi”. İkisi de FCM tarafından HTTP 200 ile kabul edildi. Kullanıcı ardından **“İkisi de geldi, Bağlantı kontrolü açıldı”** diyerek kilit ekranındaki teslimatı ve bildirime dokunma hedefini doğruladı.

Denemeler bellekte oluşturulan örnek olayları canlı mesaj biçimleyicisi ve göndericiden geçirdi. Gerçek pazaryeri bağlantısı kesilmedi; sipariş, kesinti veya bildirim olay kayıtlarına test verisi eklenmedi. Olay üretimi, kalıcı kuyruk ve tekrar denemeler ayrıca otomatik testlerle doğrulandı. Geçici telefon test dosyası ve cron kaldırıldı; sekiz kalıcı görev değişmedi.

Telefon kanıtları: `phone-send.json`, `phone-cleanup.json`, `phone-confirmation.json`. Android “Zorla durdur” veya kapalı bildirim izni normal arka plan kullanımından farklıdır. iOS APNs etkinleştirme bu yayının kapsamı değildir.
