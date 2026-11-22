import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawn} from 'node:child_process';
import {getChannel} from './channels.mjs';
const channel=getChannel(process.argv[2]);
const root=path.resolve(import.meta.dirname,'..'),android=path.join(root,'android');
const toolchain=path.join(root,'work/android-toolchain');
async function soleDirectory(folder){const entries=await fs.readdir(folder,{withFileTypes:true});const directories=entries.filter(e=>e.isDirectory());if(directories.length!==1)throw new Error('Araç klasörü belirsiz: '+folder);return path.join(folder,directories[0].name);}
const jdk=await soleDirectory(path.join(toolchain,'jdk-21'));
const platform=await soleDirectory(path.join(toolchain,'platforms-android-35'));
const tools=await soleDirectory(path.join(toolchain,'build-tools-35.0.0'));
const java=path.join(jdk,'bin/java.exe'),javac=path.join(jdk,'bin/javac.exe'),jar=path.join(jdk,'bin/jar.exe');
const source=path.join(android,'app/src/main'),out=path.join(android,'build',channel.name);
const {version,versionCode}=channel,filename=`${channel.filenamePrefix}-${version}.apk`;
const output=path.resolve(root,'../outputs/Omnigo-Android');
async function run(command,args,env={}){await new Promise((resolve,reject)=>{const child=spawn(command,args,{cwd:android,windowsHide:true,env:{...process.env,...env},stdio:['ignore','inherit','inherit']});child.on('error',reject);child.on('exit',code=>code===0?resolve():reject(new Error(path.basename(command)+' başarısız: '+code)));});}
async function files(folder,extension){const result=[];for(const entry of await fs.readdir(folder,{withFileTypes:true})){const file=path.join(folder,entry.name);if(entry.isDirectory())result.push(...await files(file,extension));else if(file.endsWith(extension))result.push(file);}return result;}
for(const folder of ['classes','generated','dex','test-classes','assets'])await fs.mkdir(path.join(out,folder),{recursive:true});
await fs.mkdir(output,{recursive:true});
const configuration=path.join(out,'generated/PortalConfiguration.java');
const constants={HOST:channel.host,ORIGIN:channel.origin,TITLE:channel.title,VERSION:version,UPDATE_PATH:`/updates/android/${channel.name}/latest.json`,FILENAME_PREFIX:channel.filenamePrefix};
await fs.writeFile(configuration,'package com.omnigoglobal.client;\nfinal class PortalConfiguration {\n'+Object.entries(constants).map(([key,value])=>`    static final String ${key} = ${JSON.stringify(value)};`).join('\n')+`\n    static final int VERSION_CODE = ${versionCode};\n    static final boolean ALLOW_DIRECT_UPDATES = true;\n}\n`);
let manifest=await fs.readFile(path.join(source,'AndroidManifest.xml'),'utf8');
for(const [token,value] of Object.entries({APPLICATION_ID:channel.applicationId,VERSION_CODE:versionCode,VERSION:version,APPLICATION_TITLE:channel.launcherName}))manifest=manifest.replaceAll(`__${token}__`,String(value));
await fs.writeFile(path.join(out,'AndroidManifest.xml'),manifest);
await fs.writeFile(path.join(out,'assets/portal-bridge.js'),(await fs.readFile(path.join(source,'assets/portal-bridge.js'),'utf8')).replaceAll('__OMNIGO_PORTAL_ORIGIN__',channel.origin));
// Unit tests exercise pure Java rules without requiring a connected phone.
const core=['PortalOrigin.java','PushDestination.java','ReportPayload.java'].map(name=>path.join(source,'java/com/omnigoglobal/client',name));
await run(javac,['-encoding','UTF-8','--release','8','-d',path.join(out,'test-classes'),configuration,...core,path.join(android,'tests/CoreTest.java'),path.join(android,'tests/PushDestinationTest.java')]);
await run(java,['-cp',path.join(out,'test-classes'),'com.omnigoglobal.client.CoreTest']);
await run(java,['-cp',path.join(out,'test-classes'),'com.omnigoglobal.client.PushDestinationTest']);
await run(path.join(tools,'aapt2.exe'),['compile','--dir',path.join(source,'res'),'-o',path.join(out,'resources.zip')]);
await run(path.join(tools,'aapt2.exe'),['link','-o',path.join(out,'resources.apk'),'-I',path.join(platform,'android.jar'),'--manifest',path.join(out,'AndroidManifest.xml'),'-A',path.join(out,'assets'),'--java',path.join(out,'generated'),'--custom-package','com.omnigoglobal.client','--min-sdk-version','26','--target-sdk-version','35',path.join(out,'resources.zip')]);
await run(javac,['-encoding','UTF-8','--release','8','-classpath',path.join(platform,'android.jar'),'-d',path.join(out,'classes'),...await files(path.join(source,'java'),'.java'),...await files(path.join(out,'generated'),'.java')]);
await run(jar,['--create','--file',path.join(out,'classes.jar'),'-C',path.join(out,'classes'),'.']);
await run(java,['-cp',path.join(tools,'lib/d8.jar'),'com.android.tools.r8.D8','--release','--min-api','26','--lib',path.join(platform,'android.jar'),'--output',path.join(out,'dex'),path.join(out,'classes.jar')]);
await fs.copyFile(path.join(out,'resources.apk'),path.join(out,'unsigned.apk'));
await run(jar,['--update','--file',path.join(out,'unsigned.apk'),'-C',path.join(out,'dex'),'classes.dex']);
await run(path.join(tools,'zipalign.exe'),['-f','-p','4',path.join(out,'unsigned.apk'),path.join(out,'aligned.apk')]);
const privateFolder=path.join(root,'data/android-signing');await fs.mkdir(privateFolder,{recursive:true});
const passwordFile=path.join(privateFolder,channel.name+'-password.txt'),keystore=path.join(privateFolder,channel.name+'-release.p12');
let password;
try{password=(await fs.readFile(passwordFile,'utf8')).trim();}catch(error){if(error.code!=='ENOENT')throw error;password=crypto.randomBytes(36).toString('base64url');await fs.writeFile(passwordFile,password,{flag:'wx',mode:0o600});}
const keyEnvironment={OMNIGO_ANDROID_KEYSTORE_PASS:password};
try{await fs.access(keystore);}catch{
 await run(path.join(jdk,'bin/keytool.exe'),['-genkeypair','-storetype','PKCS12','-keystore',keystore,'-alias','omnigo-android-'+channel.name,'-keyalg','RSA','-keysize','3072','-validity','10000','-dname','CN=Omnigo Global, O=Omnigo Global, C=TR','-storepass:env','OMNIGO_ANDROID_KEYSTORE_PASS','-keypass:env','OMNIGO_ANDROID_KEYSTORE_PASS'],keyEnvironment);
}
const apk=path.join(output,filename);
await run(java,['-jar',path.join(tools,'lib/apksigner.jar'),'sign','--ks',keystore,'--ks-key-alias','omnigo-android-'+channel.name,'--ks-pass','env:OMNIGO_ANDROID_KEYSTORE_PASS','--key-pass','env:OMNIGO_ANDROID_KEYSTORE_PASS','--out',apk,path.join(out,'aligned.apk')],keyEnvironment);
await run(java,['-jar',path.join(tools,'lib/apksigner.jar'),'verify','--verbose','--print-certs',apk]);
await run(path.join(tools,'zipalign.exe'),['-c','-p','4',apk]);
await run(path.join(tools,'aapt.exe'),['dump','badging',apk]);
const bytes=await fs.readFile(apk),sha256=crypto.createHash('sha256').update(bytes).digest('hex');
await fs.writeFile(path.join(output,filename+'.sha256'),sha256+'  '+filename+'\n');
await fs.mkdir(path.join(output,channel.name),{recursive:true});
await fs.writeFile(path.join(output,channel.name,'latest.json'),JSON.stringify({version,versionCode,url:`${channel.origin}/downloads/android/${filename}`,sha256,size:bytes.length,minAndroid:'8.0',builtAt:new Date().toISOString()},null,2));
console.log('İmzalı APK hazır: '+apk);
