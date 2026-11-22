# Satış kartları ve hakediş kontrolü — 18 Ekim 2026

Sürüm: 0.9.70. Yedek: `portal-20261017-223602-6be1d50f.ogbackup` (5.022.737 bayt). Şifreli yedek doğrulandı; değişen kaynakların yerel ve sunucu geri dönüş kopyaları alındı.

## Uygulanan arayüz düzeltmeleri

- Yönetici genel bakış ve müşteri pazaryeri kartlarında bölüm yükseklikleri ortak ölçülür. Kartlar ve grafik başlangıçları hizalanır; adet/tutar geçişi ve genişlik değişikliği ölçümü yeniler. Önceki gözlemci her çizimde kapatılır.
- Tek sütunda doğal yükseklik korunur. Hiçbir metin kesilmez; finans verileri değiştirilmez.
- Kaynak notunda Türkiye saati ve iptal/iadenin sipariş gününe işlendiği açıklanır.
- Rapor ve dışa aktarımda ortalama tutar, hesaplamasına uygun olarak “Sipariş başına net tutar” adını taşır.

## Satış tutarı doğrulaması

1–18 Ekim 2026, karşılaştırma 1–18 Eylül (aynı gün içi kesim saati):

| Gösterge | Tutar / sayı | Değişim |
| --- | ---: | ---: |
| Panel satış tutarı | 2.887.422,83 TL | -%60,2 |
| Fatura bazlı brüt | 3.118.405,19 TL | — |
| Net satış | 2.817.446,64 TL | -%59,2 |
| Kayıtlı sipariş | 102 | -%58 |
| Sipariş başına net | 27.622,03 TL | -%2,7 |
| İptal | 268.400,06 TL | -%63,5 |
| Tamamlanan iade | 32.558,49 TL | -%81,8 |

Net = fatura bazlı brüt − iptal − tamamlanan iade. Panel satış toplamı, idefix indirim öncesi ve Pazarama alıcı ödemesi esaslarını birleştirdiğinden fatura bazlı brüt ile aynı değildir. Kayıtlı sipariş sayısı iptal edilen siparişleri de içerir. İki rolün satış/rapor çıktıları aynıdır.

### Hepsiburada tarih karşılaştırması

| Dönem | Yerel satış toplamı | Canlı panel kanıtı |
| --- | ---: | --- |
| 17 Ekim | 37.777,66 TL | ROTA 17 Ekim ürün raporu ile aynı |
| 10–16 Ekim | 752.160,72 TL | Kontrol anındaki ana ekran 7 gün toplamı ile aynı |
| 17 Eylül–16 Ekim | 1.684.323,98 TL | Kontrol anındaki ana ekran 30 gün toplamı ile aynı |
| 17 Eylül–17 Ekim | 1.722.101,64 TL | ROTA üzerinde açık tarih aralığı ile aynı |
| 11–17 Ekim | 719.513,58 TL | Portalın 18 Ekim itibarıyla tamamlanan 7 günü |
| 18 Eylül–17 Ekim | 1.636.298,44 TL | Portalın 18 Ekim itibarıyla tamamlanan 30 günü |

Ana ekran ve portal farklı gün aralıklarını gösteriyordu. Aynı aralıkta tutarlar eşleşti. Kaynak ana ekranının gün değiştirme mekanizması ayrıca doğrulanmadığından UTC veya önbellek kaynaklı olduğu kesinleştirilmedi. Sipariş tarihleri ve tutarları değiştirilmedi. 179 Hepsiburada kaydı için son otomatik mutabakat sıfır fark gösterdi.

## Hakediş bulguları (düzeltme değil, kontrol)

- Hepsiburada: otomatik finans bağdaştırıcısı yok. Portalda 4 Ekim tarihli 142.542,00 TL elle girilmiş, banka teyitsiz kayıt var. Canlı ödeme ekranı 20 Ekim 429.654,21 TL; 27 Ekim 218.477,27 TL; 3 Kasım 218.364,72 TL; 10 Kasım 110.288,15 TL tahmin gösteriyor. Toplam 976.784,35 TL. 13 Ekim geçmiş ödemesi 1.594.747,30 TL; Eylül toplam geçmiş ödemesi 3.932.270,84 TL.
- n11: otomatik finans bağdaştırıcısı yok. Yalnız 4 Ekim tarihli 151.111,00 TL elle girilmiş kayıt var. Bu oturumda n11 canlı finans ekranı karşılaştırılmadı.
- idefix: finans bağdaştırıcısı ve hakediş kaydı yok. Satış bağlantısı hakediş bağlantısı anlamına gelmiyor.
- Pazarama: finans aktarımı 6 saatlik; son başarılı aktarım 17 Ekim 21:26. Koçtaş: günlük; son başarılı aktarım 17 Ekim 05:51. Kayıtlı finans hareketlerinin tarih/durum grupları, ödeme projeksiyonlarıyla eksiksiz eşleşti. Bu iki kaynağın güncel satıcı ekranı karşılaştırılmadı.
- Pazarama'da tarihi bildirilmeyen 10.900,37 TL kaydı veri tabanında mevcut; tarih filtresi nedeniyle hakediş takvimi ve dönem toplamına alınmıyor. Kullanıcıya ayrı tarihsiz kayıt alanı gerekir. Tarih uydurulmamalı.
- 18 Ekim 01:53'te Pazarama resmi finans servisinin 1–18 Ekim işlem aralığı salt okunur sorgulandı: 13 hareket. Tarihsiz 10.900,37 TL'nin kaynak açıklaması “Erken Ödemeden Kalan Tutar - Ödeme tamamlanması için bekliyor”. 26/30 Ekim ve 2/5 Kasım bekleyen tutarları mevcut kayıtlarla eşleşti. Bu sorgu finans kayıtlarını güncellemedi.
- “Hesaba geçen 0 TL” banka teyidi girilmediğini gösterir; gerçek banka hesabında hiç ödeme bulunmadığı sonucu çıkarılamaz. Pazaryerinin gönderim bildirimi ile banka teyidi farklı tutulur.
- Yeni finans entegrasyonu kurulmadı; elle girilmiş ödemeler silinmedi; hiçbir ödeme banka teyitli yapılmadı.

## Kontroller

19 JavaScript testi ve 2 PHP test grubu geçti. 360, 768, 1280 ve 1920 piksel önizlemeleri; tutar/adet ve karışık seçimler kontrol edildi. Çok sütunda tüm kartların yükseklik ve grafik başlangıç farkları 1 pikselden küçük; yatay taşma yok. Müşteri canlı ekranında yeni kaynak notu, grafik ve taşma kontrolü tamamlandı; tarayıcı hatası yok.

Önce/sonra 420 teslimat siparişi, 927 sevkiyat satırı, 2 evrak kaydı ile tüm pazaryeri satış kayıtlarının içerik özetleri aynıdır. Hakediş verileri de aynı kaldı. Kaynak sürümü ve değişen altı dosya sunucudan okunup doğrulandı. Kanıtlar: `verification/sales-cards-0970-20261018/`.

Yedek, ilk kontrol, rapor/finans kontrolü, son kontrol ve Pazarama kaynak kontrolü için kullanılan beş geçici yardımcı dosya ve cron görevi kaldırıldı. Sekiz kalıcı cron görevi korunuyor.

Yönetici oturumu kullanıcı tarafından açıldıktan sonra canlı görünüm de doğrulandı. Beş kartın tamamı yüklendi; dar ekranda yatay taşma ve tarayıcı hatası yok. Yeni kaynak açıklamaları ve tutarlar canlı DOM üzerinde doğrulandı. Kanıt: `admin-live-after.png` ve `admin-live-layout.json`.
