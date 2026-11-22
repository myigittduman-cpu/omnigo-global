import {fileURLToPath} from 'node:url';
import {join,dirname} from 'node:path';
import {openDatabase} from './database.js';
import {createApplication} from './app.js';
import {seedDemo} from './demo.js';
const root=dirname(dirname(fileURLToPath(import.meta.url)));
const demo=process.argv.includes('--demo');const port=Number(process.env.PORT||(demo?8788:8787));
const db=openDatabase(join(root,'data',demo?'demo.sqlite':'portal.sqlite'));
if(demo){
 const credential=await seedDemo(db);
 if(credential?.generated)console.log(`Örnek hesaplar (yonetici@ornek.test, musteri@ornek.test) için tek seferlik şifre: ${credential.password}\nBu şifre yalnızca şimdi gösterilir; yenisi için data/demo.sqlite dosyasını silip yeniden başlatın.`);
 else if(credential)console.log('Örnek hesaplar OMNIGO_DEMO_PASSWORD değeriyle oluşturuldu.');
}
const server=createApplication({db,publicDir:join(root,'public'),demo});
server.listen(port,'127.0.0.1',()=>console.log(`Omnigo ${demo?'örnek inceleme':'yerel kurulum'}: http://127.0.0.1:${port}`));
for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>server.close(()=>{db.close();process.exit(0);}));
