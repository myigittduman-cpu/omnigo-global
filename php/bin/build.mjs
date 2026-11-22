import {cp,mkdir,readFile,writeFile} from 'node:fs/promises';
import {resolve,join} from 'node:path';
const root=resolve(import.meta.dirname,'../..');
const output=join(root,'deploy/portal');
await mkdir(join(output,'public'),{recursive:true});
await cp(join(root,'public'),join(output,'public'),{recursive:true});
for(const name of ['src','vendor','bin/worker.php','bin/migrate.php','bin/backup.php','bin/restore.php','bootstrap.php','config.example.php','schema.mysql.sql','.htaccess']) {
 await mkdir(join(output,name,'..'),{recursive:true});
 await cp(join(root,'php',name),join(output,name),{recursive:true});
}
await cp(join(root,'php/public'),join(output,'public'),{recursive:true});
const appFile=join(output,'public/app.js');
let app=await readFile(appFile,'utf8');
app=app.replace("${setup?'<label>Ad soyad", "${setup?'<label>Kurulum kodu<input name=\"setupCode\" autocomplete=\"off\" required></label><label>Ad soyad");
await writeFile(appFile,app);
console.log('PHP yayın paketi hazır: '+output);
