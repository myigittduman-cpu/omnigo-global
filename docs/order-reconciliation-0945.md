# Sipariş aktarımı karşılaştırması — 0.9.45

13 Ekim 2026, 10:08 Türkiye saati itibarıyla Pazarama ve Koçtaş kaynak API kayıtları, Siparişler listesinin okuma servisi, şifreli teslimat kayıtları ve Şehir Dışı Sevkiyat satırları karşılaştırıldı. Kaynak aralığı 1 Ekim 2026 00:00–13 Ekim 2026 10:08; bitiş hariçtir. Kişisel sipariş bilgileri ve API anahtarları kontrol raporuna yazılmadı.

| Kontrol | Pazarama | Koçtaş |
| --- | ---: | ---: |
| Kaynak sipariş / Siparişler kaydı | 11 / 11 | 7 / 7 |
| Teslimat geçmiş kaydı | 11 | 7 |
| Aktif teslimat listesi | 9 | 2 |
| Teslimat listesine uygun olmayan geçmiş kayıt | 2 | 5 |
| Kesinti aralığında yeni sipariş | 0 | 0 |
| Karşılaştırma sonrası alan farkı | 0 | 0 |

Kesinti aralığı Koçtaş için 12 Ekim 13:38:21–13 Ekim 09:19:02, Pazarama için güvenli tarafta kalmak üzere son tam taramadan başlayan 12 Ekim 19:48:36–13 Ekim 09:19:02 aralığıdır. Kesinti sırasında oluşup atlanmış yeni sipariş bulunmadı. Her iki sipariş görevi son kontrolde `pending`, sıfır deneme ve boş hata alanıyla normal sırasını bekliyordu.

Sevkiyat otomasyonu 11 Ekim 06:03:02 itibarıyla etkin. Bu tarihten önceki elle girilmiş satırlar otomatik satır olarak sayılmadı. Altı şehir filtresi, elle düzenlenen alanlar ve silinen kayıtların korunması dikkate alındı. Otomatik kapsamdaki eşleşen satırın arayüz listesinde de bulunduğu doğrulandı; eski aktif kayıtlar için mevcut muhafazakâr eşleştirme yordamı eklenmesi gereken bir Pazarama/Koçtaş satırı bulmadı. Tarihsel elle girişlerin bütün alanlarının kaynakla birebir aynı olduğu iddia edilmez.

## Düzeltilen durum

Koçtaş'ın eski bir tam iptal satırı `quantity=0`, `price=0` döndürürken özgün adet ve tutarı `cancelations` içinde taşıyordu. Sevkiyat eşleyicisi bunu reddettiği için tam geçmiş taraması durabiliyordu. Yeni `KoctasCancelledLine` yalnızca açıkça iptal/reddedilmiş sıfır adetli satırlarda doğrulanmış iptal geçmişini kullanır. Aktif, kısmi iptal ve iade satırlarının davranışı değişmez; eksik geçmişten adet veya fiyat uydurulmaz. Finans, iptal, sipariş detayı ve sevkiyat aynı normalleştirmeyi kullanır. İptal tutarı kadar brüt tutar geri kurulur; net tutar sıfır kalır.

Beş eski Koçtaş kaydının eksik detay alanları standart kuyruk üzerinden yenilendi. Daha önce hem finans hem teslimat detayında iptal olduğu kayıtlı siparişin geçmiş iptali, yeni olay olarak sayılmaması için doğrulanmış geçmişe işlendi. İşlem öncesi ilgili kayıtlar sunucuda şifreli yedeklendi; üretim şeması değiştirilmedi.

## Doğrulama ve temizlik

- 58 regresyon grubu geçti: 56 PHP, JavaScript ve PHP HTTP grupları. Sıfır adetli iptal, tarihçesiz veri reddi, üç bölümün tutarlılığı ve tekrar aktarımın sürümü değiştirmemesi için regresyonlar eklendi.
- Kaynaklar önceki sürümle, yüklenen dosyalar SHA-256 ile doğrulandı; 0.9.45 yayımlandı.
- Son canlı karşılaştırma: `verification/reconciliation-2026-10-13/audit.json`, sonuç `verified`.
- Geçici kontrol/onarma dosyaları ve cron görevleri kaldırıldı; mevcut 7 cron görevinin içerik ve zamanlamaları korundu.
- API okuması [Mirakl OR11](https://developer.mirakl.com/content/product/mmp/rest/seller/openapi3/orders/or11) ve mevcut Pazarama sipariş okuma istemcisiyle yapıldı. Pazaryerlerine sipariş değiştirme isteği gönderilmedi.
