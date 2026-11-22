# İade Talepleri · 0.9.97

19 Ekim 2026: yalnız yönetici panelinde `Diğer → İade Talepleri`. Müşteri menüsünde yok; GET servisi müşteri rolünü reddeder. Liste 20 satır, pazaryeri/durum/arama filtreli ve mevcut canlı yenileme döngüsüne bağlıdır. Onaylama, ret, geri ödeme veya satıcı paneline yazma işlemi eklenmedi.

## Kapsam

- Hepsiburada: mevcut bağımsız hakediş/satış okuma kuyruğunun sekiz talep durumu, yalnız tam taramada görüntüleme kaydına yayımlanır. `Accepted` ile `Refunded` ayrı kalır. Eski sürümde başlamış eksik tarama tam liste sayılmaz.
- n11: mevcut ALL durumlu ClaimReturnList taramasının tamamı korunur. Talep, onay ve geri ödeme farklı durum gruplarıdır. Yalnız gün bildirilen tarihlerde saat gösterilmez.
- idefix: claim-list içindeki bütün satırlar ayrı görüntüleme projeksiyonuna alınır; mali hesaplara yalnız eskiden olduğu gibi approved satırları gider. Yeniden kontrol son doğrulanmış iade listesini korur.
- Pazarama ve Koçtaş: mevcut sipariş kayıtlarına işlenmiş onaylı iadeler gösterilir. **Bekleyen iade talepleri bağlantısı tamamlanmış değildir.** Ekran bunu Kısmi kapsam olarak açıkça gösterir. Eksik kaynak sıfır talep diye yorumlanmaz.

Tarama başlangıcı mevcut bağlantılarda 1 Eylül 2026. Kaynak tarih, miktar veya tutar vermediğinde tahmin yapılmaz. Ürün adları aynı firma/pazaryeri/sipariş/satır anahtarlarıyla mevcut kayıtlarla eşleştirilir. Kişi, adres ve serbest müşteri notları yeni iade projeksiyonuna taşınmaz.

## Koruma ve doğrulama

Yedek: `verification/return-requests-0995-20261018/backup.json`; şifreli arşiv doğrulandı. Kaynak yedekleri aynı dizindeki source/menu/detail-before dosyaları ve sunucunun özel storage/releases dizinindedir. Yeni tablo, yetki veya kalıcı cron eklenmedi. Tek kullanımlık yedek görevi kaldırıldı. Sipariş, sevkiyat, teslimat veya finans kayıtları test için değiştirilmedi.

Testler: return-requests.php, return-integration.php (üç sağlayıcı), idefix-returns.php, fast-sales-integration.php; returns-view, workspace-navigation, client-operator ve workbook-exports testleri geçti. Durum geçişi, tekrarsız liste, eksik/bozuk taramada son kayıtların korunması, 429 retry, rol/firma sınırları, 20li sayfalama ve HTML kaçışları doğrulandı.

Canlı kontrolde 20 kayıt; üç talep bağlantısının son taramaları başarılı. Müşteri menüsü ve `/api/admin/returns` erişim engeli doğrulandı. Masaüstü tablo ve 390 px mobil kartlarda arama/filtre/sayfalama denendi.

Müşteri girişindeki işlem-izleme açıklaması 0.9.94'te kaldırıldı; ad-soyad ve oturuma bağlı işlem kaydı korunuyor. Excel marka bilgileri firma kaydından gelir; önceki PDF biçimleri değiştirilmedi.
