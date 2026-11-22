import {hashPassword} from './security.js';
import {createCompany,saveRecord} from './repository.js';
export async function seedDemo(db){
 if(db.prepare('SELECT id FROM users LIMIT 1').get())return;
 const company=createCompany(db,'Vizyona — Örnek veriler');const other=createCompany(db,'Diğer Firma — Örnek');
 const admin=Number(db.prepare('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)').run('yonetici@ornek.test','Omnigo Ekibi',await hashPassword('Omnigo-Demo-2026!'),'admin').lastInsertRowid);
 db.prepare('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)').run('musteri@ornek.test','Vizyona Kullanıcısı',await hashPassword('Omnigo-Demo-2026!'),'client',company);
 const month=new Date().toLocaleDateString('sv-SE',{timeZone:'Europe/Istanbul'}).slice(0,7);const user={id:admin};
 for(const [channel,gross,orders] of [['Hepsiburada',24800000,128],['n11',8400000,46],['Pazarama',6200000,32],['idefix',3100000,18],['PTT AVM',2700000,14],['Koçtaş',12400000,52]])saveRecord(db,user,company,'sales',{channel,month,grossCents:gross,cancelCents:Math.round(gross*.02),returnCents:Math.round(gross*.01),orders,source:'Sentetik örnek veri — gerçek satış değildir.'});
 saveRecord(db,user,company,'payments',{channel:'Hepsiburada',expectedDate:month+'-15',expectedCents:4875000,status:'pending',source:'Sentetik örnek veri'});
 saveRecord(db,user,company,'payments',{channel:'n11',expectedDate:month+'-22',expectedCents:1285000,status:'pending',source:'Sentetik örnek veri'});
 saveRecord(db,user,company,'tasks',{title:'Ürün açıklamaları kontrol edildi',description:'Örnek çalışma: ürün bilgilerinin tutarlılığı incelendi.',channel:'Hepsiburada',date:month+'-02',status:'done',visibility:'client',owner:'Omnigo operasyon ekibi'});
 saveRecord(db,user,company,'tasks',{title:'Aylık değerlendirme hazırlanıyor',description:'Kanal dağılımı ve sonraki dönem önerileri hazırlanıyor.',channel:'Tüm kanallar',date:month+'-28',status:'running',visibility:'client',owner:'Omnigo raporlama ekibi'});
 saveRecord(db,user,company,'tasks',{title:'Dahili kontrol notu',description:'Bu kayıt müşteri hesabından görünmez.',channel:'Tüm kanallar',date:month+'-20',status:'planned',visibility:'internal',owner:'Omnigo ekibi'});
 saveRecord(db,user,company,'reports',{title:'Aylık kanal değerlendirmesi — Örnek',month,status:'published',content:'Bu rapor yalnızca tasarımı incelemek için oluşturulmuş örnek veriler içerir.\n\nÇalışma özeti\nÜrün içerikleri incelendi. Kanal bazındaki satış dağılımı değerlendirildi.\n\nSonraki adımlar\nEksik içeriklerin tamamlanması ve iade nedenlerinin incelenmesi planlandı.'});
}
