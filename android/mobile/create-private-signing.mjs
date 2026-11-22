import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {root,toolchain,run,soleDirectory} from '../store/runtime.mjs';
const folder=path.join(root,'data/android-signing'),slot='admin-private';
const keystore=path.join(folder,slot+'-release.p12'),passwordFile=path.join(folder,slot+'-password.txt');
await fs.mkdir(folder,{recursive:true});
for(const file of [keystore,passwordFile]){try{await fs.access(file);throw new Error('Mevcut özel imza dosyası üzerine yazılmaz.');}catch(e){if(e.code!=='ENOENT')throw e;}}
const password=crypto.randomBytes(48).toString('base64url');
await fs.writeFile(passwordFile,password+'\n',{flag:'wx',mode:0o600});
const jdk=await soleDirectory(path.join(toolchain,'jdk-21'));
await run(path.join(jdk,'bin/keytool.exe'),['-genkeypair','-keystore',keystore,'-storetype','PKCS12','-storepass:env','OMNIGO_PRIVATE_UPLOAD_PASS','-keypass:env','OMNIGO_PRIVATE_UPLOAD_PASS','-alias','omnigo-android-'+slot,'-keyalg','RSA','-keysize','3072','-validity','10000','-dname','CN=Omnigo Global, O=Omnigo Global, C=TR'],{env:{OMNIGO_PRIVATE_UPLOAD_PASS:password},quiet:true});
const cert=path.join(folder,slot+'-upload.der');
await run(path.join(jdk,'bin/keytool.exe'),['-exportcert','-keystore',keystore,'-alias','omnigo-android-'+slot,'-storepass:env','OMNIGO_PRIVATE_UPLOAD_PASS','-file',cert],{env:{OMNIGO_PRIVATE_UPLOAD_PASS:password},quiet:true});
const sha=crypto.createHash('sha256').update(await fs.readFile(cert)).digest('hex');
await fs.writeFile(path.join(folder,slot+'-certificate.sha256'),sha+'\n',{flag:'wx'});
console.log(JSON.stringify({slot,certificateSha256:sha,oldSigningKeysChanged:false}));
