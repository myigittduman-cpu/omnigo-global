# Satış sunumu — 6 Kasım 2026

Ana satış kartı, pazaryeri satış özeti, günlük grafik, ürün/TOP10 ve satış raporları iptalleri dışarıda gösterir. İptaller ayrı sütun/ayrıntıda gösterilir; bu tutarlar yalnız Omnigo yöneticilerine gönderilir ve gösterilir. Müşterinin Şehir Dışı Sevkiyat yaşam döngüsü istisnası korunur.

- `turnoverCents`: Kaynaktan gelen brüt ciro. PTT'de kupon öncesi kaynak tutarıdır.
- `cancelledTurnoverCents`: Aynı fiyat esasındaki iptal tutarı. PTT'de iptal edilen satır/adet üzerinden hesaplanır; kuponlu fatura tutarı brüt cirodan doğrudan çıkarılmaz.
- `salesTurnoverCents`: Brüt ciro eksi iptal tutarı; ana satış göstergesidir. Kısmi iptalde yalnız iptal edilen kısım çıkarılır.
- `grossCents`, `cancelCents`, `returnCents`, `netCents`: Mevcut fatura/finansal hesap alanlarıdır; bu sunum için değiştirilmez.

İadeler, komisyon ve giderler ana satış göstergesinden ayrıca düşülmez; iade kapsamı ve tutarı kendi alanında açıklanır. Bu gösterge banka tahsilatı veya muhasebe net kârı değildir. Kaynak tutar doğrulanamıyorsa sıfır veya tahmin gösterilmez.

Sipariş/sevkiyat tabloları ve bunların PDF/Excel belgeleri fatura tutarını kullanmaya devam eder. İptal edilmiş sipariş kayıtları silinmez. Hesap, müşteri ve rol ayrımı korunur. Entegra'nın brüt ciro toplamıyla iptaller hariç satış rakamı eşitlenmeye çalışılmaz.
