# GitHub kaynak aktarımı

22 Kasım 2026 tarihinde “www.OmnigoGlobal.com — Ana Sohbet” tarafından kullanılan güncel `omnigo-client-portal` çalışma ağacından alınmıştır. Commit dışındaki güncel kaynaklar da dahil edilmiştir.
Bu depo yalnız portalı değil ilgili WordPress özelleştirmelerini, Windows/Android/iOS kaynaklarını ve mevcut testleri içerir.
Canlı backend PHP/MySQL'dir; `src/` altındaki Node uygulaması eski prototiptir.
Üretim yapılandırmaları, müşteri verileri, yedekler, imza anahtarları, API sırları ve derleme çıktıları hariçtir. `php/config.example.php` örnektir.
Eski yerel Git geçmişi taşınmamıştır; ilk commit güncel kaynak görüntüsüdür. Özgün çalışma ağacı ve geçmişi korunmuştur.
GitHub iş akışı dosyaları kullanıcının tercihiyle `.github/workflow-templates/` altında şablon olarak korunmuştur. Aktif iş akışı yoktur; ek `workflow` yetkisi verilmemiştir. Bu aktarım canlı dağıtım yapmaz.
