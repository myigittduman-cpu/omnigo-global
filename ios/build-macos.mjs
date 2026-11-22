import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';

const action=process.argv[2]||'verify';
if(!['verify','archive','export'].includes(action)||process.argv.length>3)
 throw new Error('Kullanım: node ios/build-macos.mjs verify|archive|export');
if(process.platform!=='darwin')throw new Error('Mac Catalyst derlemesi macOS ve Xcode 26 veya üzeri gerektirir.');

const root=import.meta.dirname;
const build=path.join(root,'build','macos');
const verify=action==='verify';
const team=process.env.OMNIGO_APPLE_TEAM_ID||'';
const archive=path.join(build,verify?'OmnigoClient-verify.xcarchive':'OmnigoClient.xcarchive');
const bundleId='com.omnigoglobal.client';
const appName='OmnigoClient.app';
const destination='platform=macOS,variant=Mac Catalyst';
const project=['-project',path.join(root,'Omnigo.xcodeproj'),'-scheme','OmnigoClient'];

function run(command,args,{capture=false,timeout=60_000}={}){
 const result=spawnSync(command,args,{
  encoding:'utf8',timeout,env:{...process.env,LC_ALL:'C',LANG:'C'},
  stdio:capture?['ignore','pipe','pipe']:'inherit',
 });
 if(result.error)throw result.error;
 if(result.status!==0){
  if(capture&&result.stderr)process.stderr.write(result.stderr);
  throw new Error(command+' işlemi tamamlanamadı ('+result.status+').');
 }
 const stdout=result.stdout?.trim()||'';
 return capture==='all'?[stdout,result.stderr?.trim()].filter(Boolean).join('\n'):stdout;
}

const plist=(file,key)=>run('/usr/libexec/PlistBuddy',['-c','Print :'+key,file],{capture:true});
const xcode=run('xcodebuild',['-version'],{capture:true});
if(Number(xcode.match(/Xcode (\d+)/)?.[1]||0)<26)throw new Error('Xcode 26 veya üzerini seçin.');
if(!verify&&!/^[A-Z0-9]{10}$/.test(team))throw new Error('OMNIGO_APPLE_TEAM_ID olarak Apple ekip kimliğini girin.');

function manualSigningSettings() {
 const profile=process.env.OMNIGO_MAC_PROFILE_UUID||'';
 const application=process.env.OMNIGO_MAC_APP_CERT_SHA1||'';
 const applicationName=process.env.OMNIGO_MAC_APP_CERT_NAME||'';
 const installer=process.env.OMNIGO_MAC_INSTALLER_CERT_SHA1||'';
 if(!profile&&!application&&!applicationName&&!installer)return null;
 const uuid=/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i;
 const fingerprint=/^[0-9a-f]{40}$/i;
 if(!uuid.test(profile)||!fingerprint.test(application)||!fingerprint.test(installer)
   ||!/^(Apple Distribution|3rd Party Mac Developer Application|Mac App Distribution): [^\r\n]+$/.test(applicationName))
  throw new Error('Manuel Mac imzası için profil UUID’si ve iki sertifikanın kimlik bilgileri gerekli.');
 return {profile,application,applicationName,installer};
}

const manualSigning=verify?null:manualSigningSettings();

function validateApp(app){
 const contents=path.join(app,'Contents');
 const info=path.join(contents,'Info.plist');
 const executableName=plist(info,'CFBundleExecutable');
 if(executableName!=='OmnigoClient'||plist(info,'CFBundleIdentifier')!==bundleId)
  throw new Error('Mac çalıştırılabilir dosyası veya paket kimliği eşleşmiyor.');
 const executable=path.join(contents,'MacOS',executableName);
 const architectures=['arm64','x86_64'];
 const archs=run('xcrun',['lipo','-archs',executable],{capture:true}).split(/\s+/);
 if(archs.length!==architectures.length||!architectures.every(arch=>archs.includes(arch)))
  throw new Error('Mac işlemci dilimleri arm64 ve x86_64 olmalıdır.');
 for(const arch of architectures){
  const target=run('xcrun',['vtool','-arch',arch,'-show-build',executable],{capture:true});
  if(!/^\s*platform MACCATALYST\s*$/m.test(target))
   throw new Error('Çalıştırılabilir dosyanın '+arch+' dilimi Mac Catalyst hedefli değil.');
 }
 if(plist(info,'LSApplicationCategoryType')!=='public.app-category.business')
  throw new Error('Mac App Store kategorisi eksik.');
 const resources=path.join(contents,'Resources');
 for(const file of ['PrivacyInfo.xcprivacy','portal-bridge.js','native-translations.json'])
  fs.accessSync(path.join(resources,file));
 if(!fs.existsSync(path.join(resources,'AppIconMacClient.icns'))&&!fs.existsSync(path.join(resources,'Assets.car')))
  throw new Error('Mac uygulama simgesi bulunamadı.');
 return ['CFBundleShortVersionString','CFBundleVersion'].map(key=>plist(info,key));
}

function validateDistributionApp(app,metadataDir){
 run('codesign',['--verify','--deep','--strict','-R','=anchor apple generic',app]);
 const summary=run('codesign',['--display','--verbose=4',app],{capture:'all'}).split(/\r?\n/);
 const authority=summary.find(line=>line.startsWith('Authority='))||'';
 if(!/^Authority=(Apple Distribution|3rd Party Mac Developer Application|Mac App Distribution): /.test(authority)
   ||!summary.includes('TeamIdentifier='+team)){
  throw new Error('Paketteki uygulamanın Mac App Store dağıtım imzası veya ekip kimliği geçersiz.');
 }
 const entitlements=path.join(metadataDir,'distribution-entitlements.plist');
 fs.writeFileSync(entitlements,run('codesign',['--display','--entitlements','-','--xml',app],{capture:true}));
 for(const key of ['com.apple.security.app-sandbox','com.apple.security.network.client','com.apple.security.files.user-selected.read-write','com.apple.security.device.camera']){
  if(plist(entitlements,key)!=='true')throw new Error('Mac imzasında izin eksik: '+key);
 }
 if(plist(entitlements,'com.apple.developer.team-identifier')!==team)
  throw new Error('Mac imzasındaki ekip yetkisi seçilen Apple ekibiyle eşleşmiyor.');
 if(plist(entitlements,'com.apple.developer.aps-environment')!=='production')
  throw new Error('Mac dağıtım imzasında üretim bildirim yetkisi eksik.');
}

function findPayloadApps(directory,insidePayload=false){
 return fs.readdirSync(directory,{withFileTypes:true}).flatMap(entry=>{
  if(!entry.isDirectory())return [];
  const target=path.join(directory,entry.name);
  if(insidePayload&&entry.name.endsWith('.app'))return [target];
  return findPayloadApps(target,insidePayload||entry.name==='Payload');
 });
}

function validatePackage(pkg,archiveVersion){
 const signature=run('pkgutil',['--check-signature',pkg],{capture:'all'});
 const trusted=/^\s*Status: signed by (a certificate trusted by (macOS|Mac OS X)|a developer certificate issued by Apple(?: for distribution| \(Development\)| \(Distribution\))?)\s*$/m.test(signature);
 const leaf=signature.split(/\r?\n/).find(line=>/^\s*1\.\s/.test(line))?.trim()||'';
 if(!trusted||!/^1\.\s+(3rd Party Mac Developer Installer|Mac Installer Distribution): /.test(leaf)||!leaf.endsWith('('+team+')'))
  throw new Error('Mac App Store kurulum paketi seçilen Apple ekibiyle imzalanmamış.');
 const metadataDir=fs.mkdtempSync(path.join(build,'pkg-check-'));
 try{
  const expanded=path.join(metadataDir,'expanded');
  run('pkgutil',['--expand-full',pkg,expanded],{timeout:120_000});
  const apps=findPayloadApps(expanded);
  if(apps.length!==1||path.basename(apps[0])!==appName)
   throw new Error('Kurulum paketinde yalnızca OmnigoClient.app bulunmalıdır.');
  const version=validateApp(apps[0]);
  if(version.some((value,index)=>value!==archiveVersion[index]))
   throw new Error('Paketteki uygulama sürümü arşivdeki sürümle eşleşmiyor.');
  validateDistributionApp(apps[0],metadataDir);
 }finally{
  fs.rmSync(metadataDir,{recursive:true,force:true});
 }
}

const archiveApp=path.join(archive,'Products','Applications',appName);
fs.mkdirSync(build,{recursive:true});
if(action!=='export'){
 if(verify)run('xcodebuild',[...project,'-destination',destination,
  '-derivedDataPath',path.join(build,'tests'),'CODE_SIGNING_ALLOWED=NO','test'],{timeout:1_800_000});
 const signingArgs=verify?['CODE_SIGNING_ALLOWED=NO']:['DEVELOPMENT_TEAM='+team];
 if(manualSigning)signingArgs.push(
  'CODE_SIGN_STYLE=Manual',
  'CODE_SIGN_IDENTITY='+manualSigning.applicationName,
  'PROVISIONING_PROFILE_SPECIFIER='+manualSigning.profile,
 );
 run('xcodebuild',[...project,'-destination','generic/'+destination,'-archivePath',archive,
  'ARCHS=arm64 x86_64','ONLY_ACTIVE_ARCH=NO',
  ...signingArgs,'archive'],{timeout:1_800_000});
 validateApp(archiveApp);
 console.log((verify?'Mac Catalyst testleri ve çift mimarili doğrulama arşivi tamamlandı. ':'Mac arşivi oluşturuldu. ')+archive);
}else{
 const archiveVersion=validateApp(archiveApp);
 const options=path.join(build,'ExportOptions.plist');
 const manualOptions=manualSigning?`<key>signingStyle</key><string>manual</string>
<key>signingCertificate</key><string>${manualSigning.application}</string>
<key>installerSigningCertificate</key><string>${manualSigning.installer}</string>
<key>provisioningProfiles</key><dict><key>${bundleId}</key><string>${manualSigning.profile}</string></dict>
`:'';
 fs.writeFileSync(options,`<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict>
<key>method</key><string>app-store-connect</string>
<key>destination</key><string>export</string>
<key>teamID</key><string>${team}</string>
<key>manageAppVersionAndBuildNumber</key><false/>
<key>generateAppStoreInformation</key><true/>
${manualOptions}</dict></plist>
`);
 const output=fs.mkdtempSync(path.join(build,'app-store-'));
 run('xcodebuild',['-exportArchive','-archivePath',archive,'-exportPath',output,'-exportOptionsPlist',options],{timeout:900_000});
 const packages=fs.readdirSync(output,{withFileTypes:true})
  .filter(entry=>entry.isFile()&&entry.name.endsWith('.pkg'));
 if(packages.length!==1)throw new Error('App Store Connect için imzalı .pkg bulunamadı.');
 const pkg=path.join(output,packages[0].name);
 validatePackage(pkg,archiveVersion);
 const transporterInfo=path.join(output,'AppStoreInfo.plist');
 if(!fs.existsSync(transporterInfo))throw new Error('Transporter için AppStoreInfo.plist bulunamadı.');
 run('plutil',['-lint',transporterInfo],{capture:true});
 console.log('App Store Connect paketi: '+pkg);
}
