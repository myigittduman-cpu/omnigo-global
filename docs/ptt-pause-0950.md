# PTTAVM geçici kapatma — 0.9.50

Kullanıcı yeni erişim bilgileri gelene kadar PTTAVM'nin tekrar kapatılmasını istedi. Önceki görünürlük listesine PTTAVM geri eklenerek yönetici/müşteri özetleri, siparişler, teslimat evrakları, sevkiyat, dışa aktarımlar ve bildirim görünümlerinde gizlendi. Kaynak kayıtlar silinmedi.

Canlıdaki yapılandırma sunucuda çözülüp aynı API bilgileriyle yeniden şifrelendi; `enabled=false` ve yeni revision kaydedildi. Yalnız PTTAVM kanal kaydı `inactive`, görevi `blocked` yapıldı ve görev lease alanları temizlendi. Böylece yeni ağ çağrısı yapılmaz, eski çalışan görev sonucu revision kontrolünden geçemez. Diğer pazaryerlerinin görevleri değiştirilmedi.

Şifreli entegrasyon kaydı, kanal ve görev satırının işlem öncesi hali web kökü dışındaki `storage/releases/0950-ptt-company-1.json` dosyasına 0600 erişimle kaydedildi. Anahtarlar raporlara çıkarılmadı. Sipariş tablolarına yazılmadı. Sonuç: `verification/ptt-pause-0950/pause-result.json`; geçici dosya ve cron temizliği: `ptt-cleanup.json`. Görünürlük kaynak yedeği: `storage/releases/0950-before.json`.

Yeni bilgiler gelince görünürlük yeniden açılmalı; yönetici bağlantı ekranından yeni bilgiler kaydedilmeli ve PTTAVM kanal durumu yeniden active yapılmalıdır. Sipariş aktarımı tamamlanmış sayılmamalıdır: mevcut PTTAVM adaptörü yalnız erişim ön kontrolü yapar.
