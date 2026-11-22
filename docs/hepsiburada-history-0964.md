# Hepsiburada geçmiş satış ve grafik düzeni — 0.9.64

17 Ekim 2026. Eylül arşiv raporları (1–15 ve 16–30 Eylül), tekil sipariş API yanıtları ve sekiz iade/talep durumu karşılaştırıldı. Şifreli veri yedeği ve değişen canlı dosyaların geri dönüş kopyası alındı.

- 133 sipariş / 145 satır; CSV ile API arasında tutar veya adet farkı yok.
- Eksik 60 sipariş mevcut sipariş kuyruğuna alındı. Unique anahtarlar ve mevcut teslimat işleyicisi kullanıldı. 45 teslim edilmiş, 10 iptal edilmiş, 5 teslim edilememiş siparişti.
- Hepsi doğrulandıktan sonra yalnız Hepsiburada finans kapsamı 1 Eylül'e genişletildi. Ekim tutarları korundu.
- Eylül: brüt 4.187.242,71 TL; iptal 305.125,56 TL; iade öncesi satış 3.882.117,15 TL; gerçekleşen iade 210.768,51 TL; net 3.671.348,64 TL. 11 iptalli ve 9 iade edilmiş sipariş.
- İki portalın özetleri, ürün raporları ve 30 günlük Eylül grafiği aynı. Otomatik mutabakat 179 Hepsiburada siparişinde sıfır fark döndü.
- Geçmiş evrak kayıtları 145 satırla mevcut; aktif evrak/şehir dışı sevkiyat kuyruğuna eski kayıt eklenmedi. Mükerrer sipariş, evrak ve sevkiyat kaynağı yok. Manuel sevkiyatlar ve diğer pazaryeri bağlantıları korunuyor.

## Gösterim

Aktif beş pazaryeri kartında ana satış tutarı brüt eksi iptal; iadeler ve net ayrıca gösterilir. Ana aylık net kartının hesabı değişmedi. Satış, net, iptal ve iade ayrı grafik serileridir. İade kapsamı bilinmiyorsa sıfır veya doğrulanmış net gösterilmez. Kısmi iptal/iade adetlerinin kesin ayrılamadığı durumda satış adedi tahmin edilmez.

Hepsiburada'nın 7/30 günlük pencereleri bugünü dışlar: 10–16 Ekim 752.160,72 TL, 17 Eylül–16 Ekim 1.684.323,98 TL; kaynak panelle eşleşti. Seçili geçmiş ayda grafik o ayı kapsar. Diğer sağlayıcıların mevcut tarih pencereleri korunur ve açıkça yazılır; bu sürüm onların satıcı panelleriyle birebir eşleştiğini iddia etmez. idefix indirimi sonrası fatura/sipariş tutarı kullanılmaya devam eder.

Analiz raporunda iptal ve iadeler seçili kanallar için ayrı bir alt grafikte görünür. Kaynak kanallardan biri için veri eksikse toplam çizgisinde o gün boş kalır. Tablo ve satış ayrıntılarında tutarlar ayrı alanlardadır. Grafik renkleri mevcut CSP'ye uygun CSS sınıflarıyla uygulanır.

## Doğrulama

11 PHP test grubu ve 29 JavaScript testi geçti. Grafik renk düzeltmesinden sonra ilgili 8 JavaScript testi yeniden geçti. Testler gün/ay sınırlarını, iadenin tekrar uygulanmasını, finans yayınının tekrarında kayıt çoğalmamasını, Ekim toplamlarının korunmasını ve rol/firma yalıtımını içerir.

Canlı müşteri masaüstü ve yönetici 522 piksel mobil görünümü kontrol edildi; yatay taşma yok. Rapor filtresi, Eylül seçimi ve ayrı çizgi renkleri doğrulandı. 13 canlı kaynak dosyası hash ile kontrol edildi. Geçici PHP yardımcıları, plan/ara dosyaları ve cron görevleri kaldırıldı; 8 kalıcı cron aynı kaldı.

Kanıtlar: `verification/hepsiburada-history-20261017/` altındaki `data-backup.json`, `comparison.json`, `import.json`, `tests.json`, `final-audit.json`, `final-checks.json` ve ekran görüntüleri. Kaynak arşivler ve normalleştirilmiş finans verileri özel `data/hepsiburada-history-20261017/` alanındadır; yayımlanmaz.
