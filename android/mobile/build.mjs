import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {root,toolchain,run,soleDirectory} from '../store/runtime.mjs';
import {prepareRole} from './prepare.mjs';
import {validateBadging,inspectDex} from './artifact-validation.mjs';
import {releaseFilename} from '../../tools/operations/ReleaseDownloadNames.mjs';

const directUpdates=process.argv.includes('--direct-apk');
const privateDistribution=process.argv.includes('--private-admin');
const role=process.argv[2],candidate=process.argv.includes('--candidate'),resolveDependencies=process.argv.includes('--resolve-dependencies');
if(!['client','admin'].includes(role)||process.argv.slice(3).some(arg=>!['--candidate','--resolve-dependencies','--direct-apk','--private-admin'].includes(arg))||privateDistribution&&(role!=='admin'||directUpdates))throw new Error('Kullanım: node android/mobile/build.mjs client|admin [--candidate] [--resolve-dependencies] [--private-admin]');
const {channel,output:generated,firebaseConfigured}=await prepareRole(role,{requireFirebase:!candidate,directUpdates,privateDistribution});
const jdk=await soleDirectory(path.join(toolchain,'jdk-21'));
const buildTools=await soleDirectory(path.join(toolchain,'build-tools-36.0.0'));
const java=path.join(jdk,'bin/java.exe'),jar=path.join(jdk,'bin/jar.exe');
const env={JAVA_HOME:jdk,ANDROID_HOME:path.join(root,'work/android-sdk'),GRADLE_USER_HOME:path.join(root,'work/gradle-home')};
const name=role==='admin'?'Admin':'Client';
// Dependency downloads require an explicit flag; subsequent builds use the local cache.
await run(java,['-cp',path.join(toolchain,'gradle-8.13/lib/gradle-launcher-8.13.jar'),'org.gradle.launcher.GradleMain',
 '-p',path.join(root,'android/mobile'),...(!resolveDependencies?['--offline']:[]),'--no-daemon','--console=plain','-PportalVersion='+channel.version,'-PportalVersionCode='+channel.versionCode,'-PprivateAdmin='+privateDistribution,':app:bundle'+name+'Release'],{env});
const builds=path.join(root,'android/build');await fs.mkdir(builds,{recursive:true});
const temp=await fs.mkdtemp(path.join(builds,'push-'+role+'-'));
const target=path.resolve(root,'../outputs/Omnigo-Stores',privateDistribution?'android-private':directUpdates?'android-direct':candidate?'android-push-candidate':'android-push',role);await fs.mkdir(target,{recursive:true});
const filename=channel.filenamePrefix+'-'+channel.version+(directUpdates?'-direct':candidate?'-bildirim-test':'-play');
const unsigned=path.join(root,'android/mobile/app/build/outputs/bundle',role+'Release','app-'+role+'-release.aab');
const signing=path.join(root,'data/android-signing'),signingSlot=privateDistribution?'admin-private':role,keystore=path.join(signing,signingSlot+'-release.p12'),passwordFile=path.join(signing,signingSlot+'-password.txt');
await fs.access(keystore);const password=(await fs.readFile(passwordFile,'utf8')).trim();if(!password)throw new Error('Kayıtlı imzalama parolası yok.');
const keyEnv={OMNIGO_ANDROID_KEYSTORE_PASS:password},alias='omnigo-android-'+signingSlot;
const aab=path.join(temp,filename+'.aab');await fs.copyFile(unsigned,aab);
await run(path.join(jdk,'bin/jarsigner.exe'),['-keystore',keystore,'-storepass:env','OMNIGO_ANDROID_KEYSTORE_PASS','-digestalg','SHA-256','-sigalg','SHA256withRSA',aab,alias],{env:keyEnv,quiet:true});
const verified=await run(path.join(jdk,'bin/jarsigner.exe'),['-verify',aab],{quiet:true});if(!verified.includes('jar verified.'))throw new Error('Paket imzası doğrulanamadı.');
const bundletool=path.join(toolchain,'bundletool-1.18.3.jar');await run(java,['-jar',bundletool,'validate','--bundle='+aab],{quiet:true});
await run(java,['-jar',bundletool,'build-apks','--bundle='+aab,'--output='+path.join(temp,'test.apks'),'--mode=universal','--ks='+keystore,'--ks-key-alias='+alias,'--ks-pass=file:'+passwordFile,'--key-pass=file:'+passwordFile],{quiet:true});
await run(jar,['xf',path.join(temp,'test.apks'),'universal.apk'],{cwd:temp});const apk=path.join(temp,'universal.apk');
const permissions=validateBadging(await run(path.join(buildTools,'aapt.exe'),['dump','badging',apk],{quiet:true}),channel);
const apkSignature=await run(java,['-jar',path.join(buildTools,'lib/apksigner.jar'),'verify','--print-certs',apk],{quiet:true});
const cert=path.join(temp,role+'-upload-certificate.der');await run(path.join(jdk,'bin/keytool.exe'),['-exportcert','-keystore',keystore,'-alias',alias,'-storepass:env','OMNIGO_ANDROID_KEYSTORE_PASS','-file',cert],{env:keyEnv,quiet:true});
const sha=buffer=>crypto.createHash('sha256').update(buffer).digest('hex'),certificateSha256=sha(await fs.readFile(cert));
if(!apkSignature.toLowerCase().includes(certificateSha256))throw new Error('Test APK imzası kayıtlı anahtarla eşleşmiyor.');
const expectedCertificate=privateDistribution?(await fs.readFile(path.join(signing,'admin-private-certificate.sha256'),'utf8')).trim():JSON.parse(await fs.readFile(path.resolve(root,'../outputs/Omnigo-Stores/android',role,'validation.json'),'utf8')).certificateSha256;
if(expectedCertificate!==certificateSha256)throw new Error('Kayıtlı mağaza paketinin imza kimliği değişmiş.');
const entries=(await run(jar,['tf',apk],{quiet:true})).split(/\r?\n/).filter(name=>/^classes\d*\.dex$/.test(name));
await run(jar,['xf',apk,...entries],{cwd:temp});inspectDex(await Promise.all(entries.map(name=>fs.readFile(path.join(temp,name)))),{directUpdates});
await run(path.join(jdk,'bin/javac.exe'),['-encoding','UTF-8','--release','8','-d',path.join(temp,'tests'),path.join(generated,'java/PortalConfiguration.java'),...['PortalOrigin','PushDestination','ReportPayload','LaunchWindow','NotificationContent'].map(name=>path.join(root,'android/app/src/main/java/com/omnigoglobal/client',name+'.java')),path.join(root,'android/tests/CoreTest.java'),path.join(root,'android/tests/LaunchWindowTest.java'),path.join(root,'android/tests/NotificationContentTest.java'),path.join(root,'android/tests/PushDestinationTest.java')]);
await run(java,['-cp',path.join(temp,'tests'),'com.omnigoglobal.client.CoreTest']);
await run(java,['-cp',path.join(temp,'tests'),'com.omnigoglobal.client.PushDestinationTest']);
await run(java,['-cp',path.join(temp,'tests'),'com.omnigoglobal.client.LaunchWindowTest']);
await run(java,['-cp',path.join(temp,'tests'),'com.omnigoglobal.client.NotificationContentTest']);
await run(path.join(jdk,'bin/javac.exe'),['-encoding','UTF-8','--release','8','-d',path.join(temp,'tests'),path.join(root,'android/app/src/main/java/com/omnigoglobal/client/LoginCipher.java'),path.join(root,'android/tests/LoginCipherTest.java')]);
await run(java,['-cp',path.join(temp,'tests'),'com.omnigoglobal.client.LoginCipherTest']);
const aabBytes=await fs.readFile(aab),apkBytes=await fs.readFile(apk);
const validation={applicationId:channel.applicationId,version:channel.version,versionCode:channel.versionCode,builtAt:new Date().toISOString(),targetApi:36,minApi:26,
 bundleValidated:true,signatureVerified:true,signingIdentityPreserved:true,nativePushCodeVerified:true,directUpdates,firebaseConfigured,candidate,deviceTested:false,storeUploaded:false,storeReady:false,
 sha256:sha(aabBytes),apkSha256:sha(apkBytes),certificateSha256,permissions,
 pending:['Mağaza hesap doğrulaması','Gerçek cihaz akışları ve kapalı bildirim testi','İnceleme hesabı ve mağaza beyanları',...(!firebaseConfigured?['Firebase bağlantısı']:[])]};
// Publish artifacts only after validation; the signed candidate is never declared store ready.
for(const [source,destination] of [[aab,filename+'.aab'],[apk,filename+'.apk'],[cert,role+'-upload-certificate.der']])await fs.copyFile(source,path.join(target,destination));
await fs.copyFile(apk,path.join(target,releaseFilename(role,'apk')));
await fs.writeFile(path.join(target,'validation.json'),JSON.stringify(validation,null,2));
await fs.writeFile(path.join(target,'OKU.txt'),'Yalnızca test adayıdır; mağazaya yüklenmedi.\nFirebase bağlı: '+(firebaseConfigured?'Evet':'Hayır')+'\nGerçek cihaz ve kapalı bildirim testleri bekliyor.\nÖzel anahtar bu klasöre kopyalanmadı.\n');
console.log(JSON.stringify({role,output:target,version:channel.version,signatureVerified:true,firebaseConfigured,storeReady:false}));

