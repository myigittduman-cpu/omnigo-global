# İptal / iade görünürlüğü

7 Kasım 2026: İptal ve iade alanları yalnız `admin` rolündeki Omnigo hesaplarına açıktır. Müşteri varsayılanları ve eski kullanıcıya özel açık izinler bu rol sınırını aşamaz. Uygulama menüsü, finansal kart/sütunlar, raporlar ve doğrudan API erişimi aynı sunucu politikasını kullanır. Yetki ekranı müşteriye bu alanları açabilen bir kontrol sunmaz.

Şehir Dışı Sevkiyat tablosunun iptal/iade durumları önceki kullanıcı talebindeki istisnadır; mevcut görünürlüğü ve işlem izinleri korunur. Kaynak siparişler silinmez. İptal/iade senkronizasyonu, kuyruklar, stok modelleri ve müşteri profilleri değiştirilmez. İptaller hariç satış hesabı ve sipariş/fatura tutarları aynı kalır.

Rol sınırı `CustomerPermissionCatalog.adminOnly` ve `CustomerPermissions::access` tarafından uygulanır. Tarayıcıdaki `customerCan` yalnız aynı sınırı yansıtır; güvenlik sınırı sunucudadır. Finansal yanıtlar `CustomerSensitiveVisibility` üzerinden kırpılır.
