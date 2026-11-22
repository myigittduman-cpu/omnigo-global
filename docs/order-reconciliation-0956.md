# Otomatik sipariş mutabakatı — 16 Ekim 2026

Bu özellik yalnız yöneticiye açıktır. Müşteri arayüzüne veya müşteri cihazlarına mutabakat bildirimi eklenmez. Mevcut sipariş, iptal ve belge bildirimleri korunur.

## Karşılaştırma kapsamı

- n11, idefix, Pazarama ve Koçtaş'ın tamamlanan API okumalarından, yerel finans kayıtları yayımlanmadan önce ayrı bir referans hazırlanır. Ek bir pazaryeri sorgulama döngüsü oluşturulmaz. Koçtaş ilk kurulumda normal kuyruğu üzerinden bir tam okuma yapar; ardından artımlı referansı günceller.
- Sipariş kimliği, brüt tutar, iptal, iade, sipariş sayısı ve aylık satış özeti kuruş cinsinden karşılaştırılır. idefix özeti siparişlerden türediği için ayrı bir aylık satış kaydı beklenmez.
- Aktif teslimat paketleri ve otomatik sevkiyat kapsamındaki satırlar kontrol edilir. Bölünmüş paketler farklı sipariş sayılmaz. Son sipariş indeksi ile tamamlanmış finans kaydı birlikte mükerrer sayılmaz.
- Sevkiyatın altı şehir sınırı, otomatik aktarım başlangıcı, iptaller, kullanıcı tarafından kaldırılan satırlar ve elle kilitlenen tutar/adet alanları korunur. Eski elle girilmiş satırların tam eşleştiği iddia edilmez.
- Yarım, hatalı, eski veya devam eden kaynak taramasıyla sağlıklı/düzeldi sonucu verilmez. Kaynağın henüz döndürmediği bir sipariş veya pazaryerinin kendi yanlış cevabı bu kontrolle kanıtlanamaz; bağlantı/aktarım uyarıları ayrıca devam eder.

## Çalışma ve bildirim

`ReconciliationReference` referansı, `ReconciliationCompare` finans karşılaştırmasını, `ReconciliationDownstream` teslimat/sevkiyat kontrolünü, `ReconciliationMonitor` zamanlama/tekrar önlemeyi, `ReconciliationPresentation` yönetici sunumunu üstlenir.

Kontrol tamamlanan sipariş işleyicisinin ardından ve mevcut ana işleyicide sınırlı süreyle çalışır. Yeni kalıcı cron veya şema değişikliği yoktur. Şirket ve iş kilidi altında tutarlı bir görünüm okunur; kontrol kaynak API çağırmaz, siparişleri düzeltmez veya silmez. Hatası sipariş aktarımının sonucunu değiştirmez. Kontrol hataları sonraki çevrimde yeniden denenir; güncel kontrol oluşmazsa arayüz bekleme durumu gösterir.

İlk fark, değişen fark ve doğrulanmış düzelme mevcut şifreli operasyon olayı üzerinden yönetici bildirimlerine ve `admin@omnigoglobal.com` e-posta kuyruğuna kaydedilir. Aynı farkın yerel kayıt ID'leri değişse bile tekrar uyarı üretilmez. Mobil gönderimde yönetici hedefi ayrıca denetlenir; müşteri cihazının bu olayı alması engellenir. Gönderim ve yeniden denemeler mevcut e-posta/push kuyruklarını kullanır; yeni bir kimlik bilgisi veya yetki oluşturulmaz. Gerçek alıcıya yapay hata bildirimi gönderilmemiştir.

Yönetici → Diğer → Bağlantı kontrolü kartlarında son kontrol, kaynak zamanı ve sonuç görünür. Farkın ayrıntısı yönetici zil bildiriminde açılır; ilk 50 ayrıntı ve toplam fark sayısı sunulur. İlgili referans ve varsa yerel kayıt numarası gösterilir.

## Yedek ve doğrulama

- Kaynak geri dönüş yedeği: özel `storage/releases/0956-before.json`.
- Doğrulanmış şifreli veri yedeği: `portal-20261016-203601-580b9bcd.ogbackup`, 3.833.658 bayt. SHA-256: `4317b092963e1a0e57d33893eb90ae195904e6d4a5c7f9a90139fa7a866ad2da`.
- 67 regresyon grubu geçti. Dört kaynak adaptörünün referansları, paket bölünmesi, Türkiye saati ay sınırı, eksik/mükerrer/tutar farkları, tek bildirim/düzelme, firma yetkisi, müşteri cihaz filtresi ve mevcut dışa aktarma akışları doğrulandı.
- 10.000 yerel örnek sipariş karşılaştırması yaklaşık 84 ms; finans referansı yaklaşık 1,33 MB. Bu bir üretim kapasite garantisi değildir.
- Canlı ilk salt okunur kontrol: 52 sipariş; fark yok. n11 16, idefix 18, Pazarama 11, Koçtaş 7. Karşılaştırma başına 2–6 ms. Her iki panelin net toplamı 1.345.877,60 TL; müşteri dashboard çıktısında mutabakat alanı yok.
- Canlı kanıt ve temizleme kaydı: `verification/reconciliation-0956/live-check.json`, `postcheck.json`; dağıtım dosyaları SHA-256 ile doğrulanır.

## Geri dönüş

Önce özel `storage/order-reconciliation.enabled` dosyası kaldırılarak kontroller durdurulur. Kaynak dosyaları yedekten geri alınabilir. Referans ve kontrol verileri mevcut `sync_*` kayıtlarının ayrı alanlarında kalır; eski kod bunları yok sayar. Sipariş ve sevkiyat kayıtları mutabakat tarafından değiştirilmediğinden sipariş geri yükleme işlemi gerekmez. Mevcut yedi kalıcı cron korunur; geçici yedek ve kontrol yardımcıları işlem sonunda kaldırılır.
