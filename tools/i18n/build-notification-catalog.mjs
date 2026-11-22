import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import {catalog} from '../../public/locales/catalog.js';
const inputs=['MobileNotificationPresentation.php','OperationalNotifications.php','ActivityNotification.php','UserChangeNotifications.php','SupportResolutionNotifications.php'];
const keys=new Set([
 'Omnigo Global Yönetici Portalı','Omnigo Global Müşteri Portalı','Pazaryeri',
 "{0}'dan {1} tutarında yeni bir siparişiniz var.","{0}'dan yeni bir siparişiniz var.",
 'Müşteri · Destek talebi oluşturdu','Müşteri · Destek mesajı gönderdi','Müşteri · Destek talebini kapattı','Müşteri · Destek talebini yeniden açtı','Müşteri · Destek talebini güncelledi'
]);
for(const file of inputs){const source=await fs.readFile('php/src/'+file,'utf8');for(const key of Object.keys(catalog))if(source.includes(key))keys.add(key);}
const messages={};for(const key of keys){assert.ok(catalog[key],'Eksik bildirim çevirisi: '+key);messages[key]=catalog[key];}
await fs.writeFile('php/src/notification-translations.json',JSON.stringify(messages,null,1)+'\n');console.log('Bildirim metni: '+keys.size);
