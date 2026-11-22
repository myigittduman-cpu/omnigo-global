# Pazaryeri satış karşılaştırması — 0.9.65

17 Ekim 2026, Vizyon Beyaz Eşya / Vizyona. Yönetici ve müşteri portalları ortak hesaplama kullanır.

## Yapılanlar

- Değişiklikten önce şifreli veritabanı yedeği alındı; geri okuma / hash doğrulaması başarılı. `verification/marketplace-alignment-20261017/data-backup.json`.
- Değişen kaynakların yerel ve sunucu özel dizininde geri dönüş kopyaları korundu. Yerel dizin: `data/source-backups/marketplace-alignment-before-20261017/`.
- idefix panel satışında indirim öncesi tutar, Pazarama panel satışında alıcının ödediği tutar gösteriliyor. Fatura bazlı brüt, iptal, iade ve net hesapları değiştirilmedi.
- Pazarama API platform indirimini etiketli açıklama alanında veriyor. Yalnız doğrulanan biçim okunuyor; satıcı kuponu yeniden düşülmüyor, platform indirimi adetle çarpılmıyor. Eksik veya çelişkili metadata sıfıra dönüştürülmüyor.
- Pazarama tarih sorgusunun gün bazında kapsayıcı olduğu canlı salt okunur sınır testiyle doğrulandı. Tarama aralıkları çakışmıyor; son anda gelen siparişler taramanın sabit kapsamını ileri taşımıyor.
- Genel bakış, aylık tablo, ürün dökümü, ayrıntılı rapor ve rapor dışa aktarımında panel / fatura ayrımı korundu. Siparişte indirim bilgisi görünür; ek düğme gerekmiyor.
- Eylül geçmişi mevcut API okuyucuları ve kuyruklarıyla, şirket/işçi kilitleri altında aktarıldı. API hız sınırları ve yeniden deneme mekanizması korunuyor.

## Veri doğrulaması

| Kaynak | Eklenen Eylül siparişi | Eylül–Ekim toplam kayıt | Mutabakat farkı |
|---|---:|---:|---:|
| n11 | 59 | 77 | 0 |
| idefix | 73 | 93 | 0 |
| Pazarama | 21 | 32 | 0 |
| Koçtaş | 16 | 23 | 0 |

Toplam 169 kayıt. Önceden bulunan tüm Ekim siparişlerinin tarih, adet, brüt, iptal ve iade değerleri aynı. İki rolün Eylül raporları aynı; günlük satırlar rapor toplamlarını veriyor. Teslimat / sevkiyat mükerrer kontrolü sıfır. Aktarım sırasında eski sipariş bildirimi oluşmadı. Ekim toplam net satış 2.817.446,64 TL olarak korundu.

## Satıcı paneli kanıtı ve kalan teyit

- **Pazarama:** canlı satıcı paneli 11–17 Ekim haftası 40.217,75 TL. İlgili siparişte 42.545 TL başlangıç, 200 TL satıcı kuponu, 2.127,25 TL Pazarama indirimi var. Fatura 42.345 TL; alıcı ödemesi 40.217,75 TL. Uygulama eşleşiyor.
- **Koçtaş:** canlı panelde 18 Eylül–17 Ekim dönemi GMV 237.170 TL ve satış geliri 194.803 TL. API kuruşlarıyla 237.170,01 TL / 194.803,01 TL; panelin tam TL yuvarlamasıyla eşleşiyor. İade 42.367 TL. Panelin hesaplama ayarları değiştirilmedi.
- **idefix:** kullanıcının paylaştığı panel görselindeki 100.831 / 292.558 / 1.036.627 TL değerleri, aynı gün / 11–17 Ekim / 18 Eylül–17 Ekim kaynak siparişlerinden indirim öncesi tutarla birebir elde edildi. Güncel satıcı oturumuna giriş SMS onayı bekliyor.
- **n11:** API geçmişi, iptaller, iadeler ve iki portalın mali raporu doğrulandı. Kullanıcının görseliyle haftalık/aylık farkın başlangıç günüyle ilişkili olduğuna dair kanıt var: 10–17 Ekim ve 17 Eylül–17 Ekim kullanıldığında, görselden sonraki 7.000 TL yeni sipariş eklendiğinde aynı sonuç alınıyor. Ancak satıcı panelinde tarih sınırı henüz doğrulanamadı. Bu nedenle n11'in özel tarih politikası değiştirilmedi; görünüm mevcut 11–17 Ekim ve 18 Eylül–17 Ekim aralıklarını açıkça yazıyor. n11 giriş ekranı SMS doğrulaması bekliyor. Sonraki işlem yeni indirim düzeltmesi değil, bu tarih sınırının native panelde kesinleştirilmesi.

## Kontroller

- 12 PHP test grubu; 30 JavaScript testi; 9 değişen PHP dosyasında sözdizimi kontrolü başarılı.
- Pazarama kapsayıcı tarih sınırı ve ikinci taramada mükerrer üretmeme testi başarılı.
- Hepsiburada raporlama, n11, idefix, Koçtaş, iptal/iade ve bildirim regresyonları geçti.
- Yönetici mobil görünümü (522 px) ve müşteri masaüstü (1280 px): kartlarda yatay taşma yok, grafikler görünür. Müşteri Eylül raporu ve yönetici Koçtaş özeti ekranda doğrulandı.
- Yetkilendirme, hesap güvenliği, pencere ayarları, bildirim sesi ve gizlenen pazaryerleri değişmedi.

Kanıtlar: `verification/marketplace-alignment-20261017/verification.json`, `source-comparison.json`, `after/report.json`, `release-checks.json`, `admin-mobile.png`, `customer-september-report.png`. Geçici görev/dosya temizliğinin sonucu `cleanup.json` dosyasındadır. Sunucudaki mevcut sekiz cron görevi korunur.
