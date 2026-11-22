# 0.9.49 — PTTAVM bağlantı ekranını yeniden açma

16 Ekim 2026: Kullanıcının API bilgilerinin hazır olduğunu bildirmesi üzerine PTTAVM geçici gizleme listesinden çıkarıldı. Hepsiburada, Trendyol ve Teknosa gizli kalır.

- Yönetici ve müşteri kataloğu, teslimat, sevkiyat, özetler ve bildirimler aynı görünürlük kuralını kullanır. Mevcut PTTAVM kayıtları silinmez veya yeniden oluşturulmaz.
- API bilgileri yalnız yönetici panelindeki **Pazaryerlerim → PTT AVM → Bağlantı işlemleri** ekranından girilir. API Key ve Access Token mevcut şifreli kasaya kaydedilir.
- Bu sürüm bağlantı ve görünürlük hazırlığıdır. Mevcut PTTAVM adaptörü yalnız erişimi kontrol eder; yeni siparişleri üç bölüme eksiksiz aktaran üretim entegrasyonu henüz tamamlanmış sayılmaz. Gerçek yanıt biçimi doğrulandıktan sonra eşleme ve aktarım ayrıca tamamlanmalıdır.
- Üretime yalnız iki görünürlük dosyası ve sürüm işareti gönderilir. Veritabanı, cron, bağlantı bilgileri ve Hepsiburada test çalışmaları bu yayın kapsamına dahil değildir.

Kontroller: görünürlük JavaScript testleri, iki rolün PHP görünürlük/dışa aktarma/bildirim testleri, bağlantı kasası/kuyruk testleri ve yerel HTTP yetkilendirme testleri başarılı.
