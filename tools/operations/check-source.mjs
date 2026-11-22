import {execFileSync} from 'node:child_process';
import path from 'node:path';
const root=path.resolve(import.meta.dirname,'../..');
const git=args=>execFileSync('git',['-c','safe.directory='+root.replaceAll('\\','/'),...args],{cwd:root,maxBuffer:32*1024*1024});
const names=git(['ls-files','-z']).toString('utf8').split('\0').filter(Boolean),findings=[];
const privatePath=/^(data|work|verification|output|tmp|deploy|Yeni klasör)\/|(^|\/)(node_modules|target|\.env(?:\..*)?)\/|^php\/(storage|vendor)\/|^php\/(config|mail\.config)\.php$|\.(?:key|pem|dpapi|pfx|p12|jks|keystore|sqlite\w*|ogbackup|bundle)$/i;
const secrets=[/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,/\bgh[pousr]_[A-Za-z0-9]{30,}\b/,/\bAKIA[A-Z0-9]{16}\b/];
for(const name of names){
 if(privatePath.test(name)||(/(^|\/)\.env(?:\..*)?$/.test(name)&&!name.endsWith('.env.example'))){findings.push({file:name,reason:'Özel veya üretilmiş dosya'});continue;}
 if(/\.(png|jpe?g|ico|woff2?|ttf|otf)$/i.test(name))continue;
 const content=git(['show',':'+name]).toString('utf8');
 if(secrets.some(pattern=>pattern.test(content)))findings.push({file:name,reason:'Olası erişim anahtarı'});
}
if(findings.length){console.error(JSON.stringify(findings,null,2));process.exitCode=1;}
else console.log(`${names.length} kaynak dosyası: özel veri yolu ve bilinen anahtar kontrolleri geçti.`);
