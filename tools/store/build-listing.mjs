import fs from 'node:fs/promises';
import path from 'node:path';
const root=path.resolve(import.meta.dirname,'../..');
const release=process.env.OMNIGO_STORE_RELEASE||'20261016';
if(!/^\d{8}$/.test(release))throw new Error('Geçersiz yayın tarihi.');
const output=path.resolve(root,'../outputs/Omnigo-Stores/google-play-'+release);
const web=path.join(root,'work',process.env.OMNIGO_REVIEW_DIR||'store-review','public/store-assets');
const listings=JSON.parse(await fs.readFile(path.join(root,'store/listings/tr-TR.json'),'utf8'));
const shots={
 client:[['01-payments','Hakedişleri takip edin','Beklenen ödemeler ve aylık takvim.'],['02-work','Çalışmalar görünür olsun','Devam eden işler ve sonraki adımlar.'],['03-shipments','Sevkiyatınız elinizin altında','Şehir ve pazaryerine göre düzenli takip.'],['04-support','Ekibinizle iletişimde kalın','Talepler ve yanıtlar aynı çalışma alanında.']],
 admin:[['01-companies','Her markaya ayrı alan','Müşteri çalışma alanları arasında geçiş yapın.'],['02-order','Sipariş ayrıntıları bir arada','Ürün, alıcı ve teslimat bilgilerini inceleyin.'],['03-delayed','Gecikenleri gözden kaçırmayın','Beş günü geçen, kargolanmamış siparişleri görün.'],['04-shipped','Kargodaki siparişleri izleyin','Kargolanan ve teslimat bekleyen kayıtlar.']]
};
const css=`*{box-sizing:border-box}body{margin:0;font-family:'Segoe UI',Arial,sans-serif;color:#f4f5f7;background:#101927}h1,p{margin:0}.canvas{position:relative;overflow:hidden;background:radial-gradient(ellipse at 100% 0,#263a51,transparent 65%),#101927}.gold{color:#ccb779}.eyebrow{font-size:27px;letter-spacing:3px;font-weight:600}.foot{position:absolute;color:#b7c2d1;font-size:23px;bottom:30px;left:64px}.mark{position:absolute;right:60px;bottom:35px;letter-spacing:4px;font-size:22px;color:#c9b77d}.ring{position:absolute;width:900px;height:900px;border:1px solid #c6b67a44;border-radius:50%;right:-560px;top:-170px;pointer-events:none}`;
const page=(style,body)=>`<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Omnigo Google Play görseli</title><style>${css}${style}</style><body>${body}</body></html>`;
await fs.mkdir(web,{recursive:true});
let manifest={createdAt:new Date().toISOString(),source:'Gerçek portalın yerel kopyası, yalnız sentetik veriler. Mobil web görünümü; fiziksel Android ekran kaydı değildir. Yerel kopyada Android kabuğunun da kaldırdığı web kurulum düğmesi gizlendi. İşlevler ve uygulama tasarımı değiştirilmedi.',apps:{}};
for(const role of ['client','admin']){
 const dir=path.join(output,role);await fs.mkdir(dir,{recursive:true});await fs.mkdir(path.join(web,role,'raw'),{recursive:true});
 await fs.copyFile(path.join(root,'public/assets/app-512.png'),path.join(dir,'icon-512.png'));
 const item=listings[role];if([...item.name].length>30||[...item.shortDescription].length>80||[...item.description].length>4000)throw new Error('Mağaza metni sınırı aşıldı.');
 for(const [name,value] of Object.entries({'title':item.name,'short-description':item.shortDescription,'full-description':item.description,'release-notes':'Omnigo Global Android uygulamasının ilk Google Play sürümü. Hesabınıza uygun çalışma alanı, belge işlemleri ve izin verdiğiniz bildirimler.'}))await fs.writeFile(path.join(dir,name+'.txt'),value+'\n');
 manifest.apps[role]={applicationId:item.bundleId,title:item.name,titleLength:[...item.name].length,shortDescriptionLength:[...item.shortDescription].length,fullDescriptionLength:[...item.description].length,screenshots:[]};
 for(const [file,title,subtitle] of shots[role].filter(([file])=>release==='20261016'||(role==='client'?['02-work','03-shipments']:['01-companies','04-shipped']).includes(file))){
  await fs.copyFile(path.join(dir,'raw',file+'.png'),path.join(web,role,'raw',file+'.png'));
  const landscape=file==='03-shipments';
  const style=landscape?`.canvas{width:1920px;height:1080px;padding:48px 70px}h1{font-size:59px;margin:12px 0}p{font-size:29px;color:#c2cbd8}.screen{position:absolute;left:160px;top:230px;width:1600px;border:2px solid #506078;border-radius:18px;overflow:hidden}.screen img{display:block;width:100%}.foot{font-size:21px}`:`.canvas{width:1080px;height:1920px;padding:64px}h1{font-size:68px;line-height:1.12;max-width:950px;margin:24px 0}p{font-size:30px;line-height:1.4;color:#c2cbd8}.screen{position:absolute;top:350px;left:170px;width:740px;height:1480px;border:2px solid #506078;border-radius:26px;overflow:hidden;background:#172130}.screen img{display:block;width:100%;height:100%;object-fit:contain}.foot{font-size:21px;bottom:28px}.mark{font-size:19px;bottom:30px;right:60px}`;
  const html=page(style,`<main class="canvas"><div class="ring"></div><div class="eyebrow gold">${role==='client'?'MÜŞTERİ PORTALI':'YÖNETİCİ PORTALI'}</div><h1>${title}</h1><p>${subtitle}</p><div class="screen"><img src="raw/${file}.png" alt="${title}"></div><div class="foot">Ekranlardaki bilgiler örnektir.</div><div class="mark">OMNIGO GLOBAL</div></main>`);
  await fs.writeFile(path.join(web,role,file+'.html'),html);
  manifest.apps[role].screenshots.push({file:file+'.png',width:landscape?1920:1080,height:landscape?1080:1920,alt:`${role==='client'?'Müşteri':'Yönetici'} uygulaması: ${title}. Örnek veriler.`});
 }
 const feature=page(`.canvas{width:1024px;height:500px;padding:55px 62px}h1{font-size:53px;line-height:1.12;margin:22px 0;max-width:760px}.eyebrow{font-size:20px}.tag{margin-top:26px;display:inline-block;border-top:2px solid #bca56b;padding-top:20px;font-size:23px;color:#c2cbd8}.wordmark{font-weight:700;font-size:24px;letter-spacing:5px}.edition{position:absolute;right:60px;bottom:48px;font-size:18px;color:#c9b77d}.ring{right:-530px;top:-160px;width:850px;height:850px}`,
 `<main class="canvas"><div class="ring"></div><div class="wordmark gold">OMNIGO GLOBAL</div><h1>${role==='client'?'İşinizin takibi,<br>tek çalışma alanında.':'Siparişten sevkiyata,<br>operasyon elinizde.'}</h1><div class="tag">${role==='client'?'Hakedişler · Sevkiyatlar · Çalışma takibi':'Siparişler · Teslimat evrakları · Müşteriler'}</div><div class="edition">${role==='client'?'MÜŞTERİ':'YÖNETİCİ'}</div></main>`);
 await fs.writeFile(path.join(web,role,'feature.html'),feature);
 manifest.apps[role].feature={file:'feature-1024x500.png',width:1024,height:500,alt:`Omnigo Global ${role==='client'?'Müşteri: hakediş, sevkiyat ve çalışma takibi.':'Yönetici: siparişten sevkiyata operasyon yönetimi.'}`};
}
await fs.writeFile(path.join(output,'manifest.json'),JSON.stringify(manifest,null,2));
console.log(JSON.stringify({output,previewBase:'http://127.0.0.1:8793/store-assets',status:'HTML hazır; tarayıcıda çizilip PNG çıktıları doğrulanmalı.'}));
