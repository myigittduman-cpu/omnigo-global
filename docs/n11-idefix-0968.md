# n11 ve idefix satıcı paneli karşılaştırması — 0.9.68

17 Ekim 2026 tarihinde Vizyona satıcı hesaplarıyla canlı kontrol yapıldı. Kullanıcının isteği üzerine bağlantı kesintisi/toparlanma push bildirimi çalışması bekletildi; o çalışmanın yerel kaynakları bu yayına dahil edilmedi.

## Satıcı ekranlarıyla eşleşen tutarlar

| Pazaryeri | Bugün | Haftalık satış | Aylık satış |
|---|---:|---:|---:|
| n11 | 36.680,00 TL | 274.884,00 TL | 1.164.255,00 TL |
| idefix | 100.831,00 TL | 292.558,00 TL | 1.036.627,00 TL |

- n11 ana sayfa performans alanındaki üç tutar canlı okundu. Satıcı panelinin hazır tarih seçiminde “Son 1 Hafta” 10–17 Ekim, “Son 1 Ay” 17 Eylül–17 Ekim gösterdi. Bu başlangıç günleri dahil edildiğinde kaynak siparişler performans tutarlarını kuruşu kuruşuna üretiyor. Ana sayfadaki ayrı sipariş adedi grafiğinin 11–17 Ekim kullanabildiği de görüldü; grafik tek başına performans toplamının kanıtı olarak kullanılmadı.
- idefix ana sayfa bugün/7 gün/30 gün tutarları mevcut indirim öncesi panel tutarlarıyla eşleşti. 18 Eylül–17 Ekim döneminin indirim öncesi satış tutarı 1.036.627 TL, fatura bazlı neti 907.377,68 TL. İndirim ve iadeler ayrı tutuluyor.

## Yedek ve yayın

- Şifreli yedek: `portal-20261017-163302-dd934501.ogbackup`, 5.013.275 bayt. Geri okuma/hash doğrulandı. SHA-256: `14aca5c34f0d502640e61b9548bec6e6a495db86dd33d2cd7037d17af701313b`.
- Üç mevcut kaynak dosyasının yerel ve sunucu özel alanında geri dönüş kopyası saklandı. `data/source-backups/n11-idefix-before-20261017/`.
- Yalnız `N11SummaryPeriods.php`, `MarketplaceSalesSummary.php`, `marketplace-summary.js` ve `version.json` yayımlandı. n11 hafta/ay hesabı başlangıç gününü ve bugünü kapsıyor. Kısa aylarda önceki ayın son geçerli günü kullanılıyor. Grafik kapsamı ilgili n11 aylık özetinin kapsamını izliyor; seçilen takvim ayı grafik davranışı korunuyor.
- n11 kartında “Son 1 haftalık satış” ve “Son 1 aylık satış” yazıyor, kesin tarih aralığı gösteriliyor. Yüzde karşılaştırması aynı uzunlukta önceki dönemden hesaplanır; n11'in yayımladığı bir yüzde olduğu iddia edilmez.
- Sipariş kaydı, iptal/iade kaydı, teslimat evrağı, sevkiyat, kuyruk veya erişim yetkisine yazılmadı. Diğer pazaryerlerinin dönem hesabı korunuyor.

## Kontroller

- 8 PHP test grubu ve 5 JavaScript test dosyası başarılı; değişen PHP dosyalarının sözdizimi kontrol edildi. Türkiye gün sınırı, ay/yıl geçişi, uzun aylarda önceki dönem sorgu kapsamı, ayrı iptal/iade/net ve diğer pazaryerleri kapsandı.
- Yayın sonrası yönetici/müşteri API özetleri eşit. İki kaynağın günlük mali kayıtları ve takvim ayı toplamları yayın öncesiyle aynı.
- n11 77 ve idefix 93 kaynak siparişlik mevcut mutabakat kapsamında eksik, mükerrer, tutar, dönem veya durum farkı yok. Bu kontrol tanımlı otomatik aktarım kapsamına aittir; bütün tarihsel manuel alanlar için mutlak garanti anlamına gelmez.
- Müşteri portalında n11 yeni tutarlar ve idefix mevcut tutarlar ekranda doğrulandı; `verification/n11-idefix-20261017/customer-sales.png`.
- Yönetici son görsel kontrolü, kullanıcının yeniden girişi ardından 18 Ekim 2026 tarihinde tamamlandı. n11 ve idefix kartlarının yönetici/müşteri metinleri, tutarları ve dönemleri eşit. Grafikler görünür; geçersiz çizim değeri bulunmadı. Gün değişimiyle n11 haftası 11–18 Ekim, aylık dönemi 18 Eylül–18 Ekim; idefix dönemleri 12–18 Ekim ve 19 Eylül–18 Ekim oldu. Bu oturumda yalnız arayüz okundu; uygulama veya sipariş kaydı değiştirilmedi. Kanıtlar: `admin-sales-20261018.png` ve `ui-final-20261018.json`.
- Geçici denetim/yedek dosyaları ve bunlara ait cron görevleri kaldırıldı; sekiz kalıcı cron korundu. Bildirim çalışmasının beş mevcut PHP/işçi kaynağı canlıdaki önceki hashleriyle aynı.

Kanıtlar: `verification/n11-idefix-20261017/current.json`, `after.json`, `backup.json`, `release-checks.json`, `deployed.json`, `history-boundary-check.json`, `final-validation.json` ve temizleme kayıtları.
