# Pazaryeri stok yönetimi

Bu ek model yalnız doğrulanmış Terzioğlu Shop müşterisine açılır. Temel stok modeli `horoz` olarak korunur; `marketplaceManagement: true` yalnız pazaryerine stok gönderme özelliğini ekler. Mevcut canlı stok, manuel fark, mağaza satışı, alış fiyatı, sıralama ve hareket defteri değişmez. Yeni müşteriler bu özelliği miras almaz.

Müşteri Yetkileri → Bölümler ve tablolar → Stok modeli alanında ek seçenek bulunur. Önceki dört model yerinde kalır. Ek seçenek kapatılınca yeni yazmalar durur; ürünler ve eski hareketler silinmez. Yayında şu an bağlı ve desteklenen yazma hedefi Hepsiburada’dır. Diğer pazaryerleri ayrı resmî adaptör ve doğrulama tamamlanmadan hedef olarak gösterilmez.

Stok Yönetimi → ürünün İşlemler alanı → Pazaryeri stoğunu güncelle. Bu işlem yalnız Omnigo yöneticisine açıktır; müşterinin doğrudan API isteği de reddedilir. Form kesin ürün/SKU, pazaryeri, kaynak stok ve Omnigo stoğunu gösterir; adet ve açık gönderim onayı gerekir. Negatif değer gönderilmez. Sıfır değer pazaryerinde satışa kapanmaya neden olabilir.

İşlem ayrı `marketplace_stock_requests` kuyruğuna alınır. Her onay idempotenttir. Gönderimden hemen önce aynı müşterinin güncel bağlantısı, yönetici yetkisi, ürün kimliği ve kaynak stok tekrar doğrulanır. Kaynak değişmişse yazma yapılmaz. API’ye yalnız stok alanları iletilir. Fiyat, kargo veya ürün açma/kapama alanı gönderilmez.

API isteğinin kabul edilmesi başarı değildir: yükleme durumu ve gerçek stok GET ile doğrulanır. Belirsiz POST sonucu körlemesine tekrar gönderilmez; yalnız okunarak uzlaştırılır. Açık 429 reddi ve geçici okuma hataları sınırlı, gecikmeli tekrar denenir. Kuyruk pencereden bağımsız sunucuda çalışır; tekrar açıldığında bekleyen işlem izlenir.

Doğrulama sonrası mevcut salt okunur katalog aktarımı öne alınır. Yerel stok havuzu elle yeniden yazılmaz; canlı stok yeni kaynak adedini normal aktarım yolundan alır ve mevcut manuel fark korunur. İşlem yönetici ve müşteri kimliğiyle kayıt altına alınır.

Yayın öncesi tam müşteri profili ve değişecek kaynak dosyaları özel yedekte tutulur. Yalnız model bayrağı eklenir; diğer müşteri ayarları korunur. Geri dönüşte ek model kapatılır. Belirsiz veya kabul edilmiş bir gönderim varsa pazaryeri sonucu kontrol edilmeden yeniden gönderim yapılmaz; dış pazaryeri yazmaları dosya yedeğiyle geri alınmış sayılmaz.

Ortak arayüz ilkeleri korunur: sabit ilk kare ölçüsü, kısa opacity geçişi, kaydırması korunmuş arka sayfa, mobil tam yatay tablo ve sayfa değişince tablo başına dönüş. Form rakam veya durum metni değiştiğinde büyümez.
