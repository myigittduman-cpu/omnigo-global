# Teslimat Evrakları (0.9.35)

Yalnızca yönetici çalışma alanındadır. Şirket seçildikten sonra **Şehir Dışı Teslimatlar** menüsünden il, pazaryeri ve sevkiyat tarihi seçilir. İl seçilince uygun aktif siparişler otomatik seçilir. Seçim değiştirilebilir. Bir sayfada en fazla 250 sipariş bulunur. PDF indirmek siparişi değiştirmez; tekrar indirilebilir. Arşiv sekmesi ve hazırlama durumu filtresi yoktur.

## Veri akışı

- Koçtaş ve n11'in mevcut kuyruk işleri, aynı sipariş yanıtından teslimat verisini de çıkarır. İlave pazaryeri yazma işlemi yapılmaz. Mevcut yeniden deneme ve hız sınırlaması korunur.
- `DeliveryMapper` siparişi veren kişi, teslimat telefonu/adresi ve sevke uygun ürünleri ayırır. Fatura adresi veya teslimat alıcısı isim için yedek kaynak olarak kullanılmaz.
- Koçtaş: `customer.firstname/lastname`, `customer.shipping_address`; n11: `customerfullName`, `shippingAddress`.
- Koçtaş `SHIPPING/SHIPPED`, n11 `Created/Picking/Shipped` durumları işlenir. İptal/teslim tamamlanması sonraki okumada aktif listeden çıkarılır.
- Kapsam mevcut sipariş senkronizasyonunun başlangıç tarihinden itibarendir; ekran bu tarihi Türkiye saat diliminde gösterir. Daha eski siparişler otomatik olarak kapsanmış sayılmaz.
- Ankara, Kırıkkale, Kırşehir, Nevşehir, Kayseri ve Eskişehir eşleştirilir. Diğer veya belirsiz iller kontrol alanındadır ve bu sürümde PDF seçimine açık değildir.

## Yetki ve saklama

`DeliveryRoutes` tüm `/api/admin/delivery/*` uçlarında önce yönetici rolünü, sonra şirketi doğrular. Yazma işlemleri mevcut CSRF denetiminden geçer. Müşteri panelinin menü listesinde teslimat yoktur; müşteri oturumunun doğrudan isteği 403 döner.

Kişisel teslimat bilgileri satış kayıtlarına eklenmez. `delivery_orders` ve `delivery_documents` içindeki içerik AES-256-GCM ile, şirket ve kayıt bağlamına bağlı olarak şifrelenir. Anahtar mevcut web kökü dışındaki `storage/backup.key` üzerinden ayrı bir HKDF anahtarıyla türetilir. PDF tarayıcıda üretilir; herkese açık bir PDF URL'si oluşturulmaz.

`DeliverySelection` şehir, şirket ve sipariş sürümünü şirket kilidi altında doğrular. `/api/admin/delivery/pdf-data` güncel PDF verisini döndürür; arşiv ya da hazırlama durumu yazmaz. Önceki sürümün saklanmış evrakları silinmemiştir, ancak yeni kullanıcı akışında kullanılmaz. İndirme adı `{İl} Teslimat Evrakları.pdf` olur. Windows 0.2.4 kaydetme penceresini her seferinde işletim sisteminin Masaüstü klasöründe açar (OneDrive yönlendirmesi dahil). Destekleyen web tarayıcılarında aynı klasör `showSaveFilePicker` ile önerilir; diğerlerinde tarayıcının indirme ayarı geçerlidir.

## PDF ve doğrulama

`delivery-pdf.js` mevcut jsPDF, AutoTable ve gömülü Noto Sans yazı tipini kullanır. Her sipariş yeni sayfadan başlar. Uzun ürün/adres listeleri devam sayfalarına taşar. Başta ilgili pazaryeri ve seçili firmanın logosu yan yanadır. Hepsiburada, n11, Pazarama, idefix, PTT AVM ve Koçtaş logoları desteklenir.

PDF ve Excel aynı `delivery-fields.js` alanlarını kullanır: gönderici adı/telefonu, alıcı adı/adresi, şehir-semt-posta kodu, alıcı telefonu, sipariş tarihi ve sipariş numarası. Ürün kodu, ürün adı ve adet ayrı sütunlardır. Baskıda ve WhatsApp ile paylaşılan dosyada her paketin pazaryerinden alınan barkodu ve okunabilir değeri gösterilir. Kaynak değeri bulunmayan paketin evrağında “Pazaryerinden paket barkodu gelmedi” yazar. Kargo firması ve kargo ödeme tipi gösterilmez. Tutarlar evrağa yazılmaz. Alıcı için siparişi veren kişinin adı yedeklenmez.

ExcelJS çıktısında pazaryeri başına sayfa ve sipariş başına düzenli evrak tablosu bulunur. Telefon ve sipariş numarası metin, adet sayısaldır. İlgili logolar çalışma kitabına gömülür. Vizyona gönderici adı ve telefonu kullanıcının verdiği örnekten alınır; başka şirketler kendi profilini kullanır.

Yönetici sayfasındaki WhatsApp düğmesi seçili siparişlerin sürümlerini doğrulayan aynı `/api/admin/delivery/pdf-data` servisini kullanır. PDF ve Excel paylaşımı ile normal indirmeler `createDeliveryFile` üzerinden aynı şablonu üretir. Destekleyen cihazlarda dosya paylaşım menüsü açılır; diğerlerinde dosya indirilip WhatsApp'a eklenir. Otomatik mesaj gönderilmez. Müşteri portalına bu menü veya düğme eklenmez. Paylaşım penceresinin hazırlanmış dosyaları anlık kopyadır, canlı bağlantı değildir.

Şehir Dışı Sevkiyat WhatsApp penceresi de normal indirmeyle aynı 13 sütunlu PDF/Excel üreticisini kullanır. Şehir, pazaryeri, ay, arama ve filtreler korunur; ayrı yönetici girişli canlı bağlantı mevcut davranışını sürdürür.

Kontroller: `php/tests/delivery.php`, güncellenmiş Koçtaş/n11 testleri ve `php/tests/integration.mjs`. `verification/delivery-077/` yayın planı, önceki dosyalar, canlı sonuçlar ve örnek ekranları içerir. Gerçek siparişler test amacıyla evrak oluşturulmuş durumuna geçirilmez.

Mevcut kuruluma iki tabloyu eklemek için yedek alındıktan sonra `DeliverySchema::install($db)` çalıştırılır. `BackupArchive` hem eski 19 hem yeni 21 tablolu yedekleri destekler. Geri almada satış kodu önceki sürüme döndürülebilir; yeni tablolar ve geriye uyumlu yedek sınıfı korunmalıdır.
