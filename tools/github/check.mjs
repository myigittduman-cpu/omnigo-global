import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const config=JSON.parse(fs.readFileSync(path.join(root,'tools/github/project.json'),'utf8'));
export const git=(args,options={})=>execFileSync('git',['-c',`safe.directory=${root.replaceAll('\\','/')}`,...args],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:64*1024*1024,...options});
export function assertRemote(){if(git(['remote','get-url','origin']).trim()!==config.remote)throw Error('origin beklenen GitHub deposuyla eşleşmiyor.');}
const privatePath=/^(?:data|deploy|verification|outputs?|tmp|work|Yeni klasör)\/|(?:^|\/)(?:node_modules|credentials|\.gradle|\.dart_tool|\.expo|target|build)(?:\/|$)|(?:^|\/)\.env(?:\.|$)|^php\/(?:storage|vendor)\/|^php\/(?:config|mail\.config)\.php$|(?:^|\/)(?:wp-config\.php|google-services\.json|GoogleService-Info\.plist|credentials\.json|local\.properties|keystore\.properties|key\.properties)$|\.(?:pem|key|p12|pfx|jks|keystore|dpapi|sqlite\w*|db|ogbackup\w*|zip|7z|exe|apk|aab|ipa|msix\w*)$/i;
const secretPatterns=[
 /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
 /\bgh[pousr]_[A-Za-z0-9]{30,}\b/,
 /\bgithub_pat_[A-Za-z0-9_]{30,}\b/,
 /\bAKIA[A-Z0-9]{16}\b/,
 /\bsb_secret_[A-Za-z0-9_-]{20,}\b/,
 /\bxox[baprs]-[A-Za-z0-9-]{20,}\b/,
 /(?:postgres(?:ql)?|mysql|sftp|ftp):\/\/[^\s/:]+:[^\s/@]+@/i,
];
const binary=/\.(?:png|jpe?g|webp|gif|ico|icns|bmp|woff2?|ttf|otf|pdf|jar)$/i;
export function validate(name,content){
 const policyName=name.replace(/(^|\/)\.env\.example$/,'$1environment-example');
 if(privatePath.test(policyName))return 'Özel veri, sır veya üretilmiş çıktı yolu';
 if(name.startsWith('.github/workflows/'))return 'Kullanıcı tercihi: aktif workflow yerine şablon kullanın';
 if(config.allowedPrefixes&&!config.allowedPrefixes.some(p=>name===p||name.startsWith(p.endsWith('/')?p:p+'/')))return 'Bu projenin izin verilen kaynak kapsamı dışında';
 if(!binary.test(name)&&secretPatterns.some(p=>p.test(content.toString('utf8'))))return 'Olası gizli erişim anahtarı; değeri paylaşmadan inceleyin';
 return null;
}
export function check(mode='staged',commit='HEAD'){
 assertRemote();
 const args=mode==='tracked'?['ls-tree','-r','--name-only','-z',commit]:['diff','--cached','--name-only','--diff-filter=ACMR','-z'];
 const names=git(args).split('\0').filter(Boolean),findings=[];
 const textNames=names.filter(n=>!binary.test(n));
 const objects=textNames.length?git(['cat-file','--batch'],{encoding:null,input:Buffer.from(textNames.map(n=>(mode==='tracked'?commit:'')+':'+n+'\n').join('')),maxBuffer:128*1024*1024}):Buffer.alloc(0);
 let offset=0;const contents=new Map();
 for(const name of textNames){
  const end=objects.indexOf(10,offset);if(end<0)throw Error('Git içeriği okunamadı.');
  const header=objects.subarray(offset,end).toString('utf8').split(' ');
  if(header[1]!=='blob'||!/^\d+$/.test(header[2]))throw Error('Git dosyası okunamadı: '+name);
  const length=Number(header[2]);contents.set(name,objects.subarray(end+1,end+1+length));offset=end+length+2;
 }
 for(const name of names){const reason=validate(name,contents.get(name)??Buffer.alloc(0));if(reason)findings.push({file:name,reason});}
 if(findings.length){console.error(JSON.stringify(findings,null,2));throw Error('Kaynak kontrolü başarısız. Gizli değerler çıktıya yazılmadı.');}
 console.log(`${names.length} dosya: yol ve bilinen gizli anahtar kontrolleri geçti.`);
 return names;
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{
  const at=process.argv.indexOf('--commit'),ancestorAt=process.argv.indexOf('--ancestor');
  const commit=at>=0?process.argv[at+1]:'HEAD';
  if(at>=0&&!/^[a-f0-9]{40}$/.test(commit??''))throw Error('Commit kimliği geçersiz.');
  if(ancestorAt>=0){const old=process.argv[ancestorAt+1];if(!/^[a-f0-9]{40}$/.test(old??''))throw Error('Uzak commit kimliği geçersiz.');if(!/^0+$/.test(old))git(['merge-base','--is-ancestor',old,commit]);}
  check(at>=0||process.argv.includes('--tracked')?'tracked':'staged',commit);
 }catch(e){console.error(e.message);process.exitCode=1;}
}
