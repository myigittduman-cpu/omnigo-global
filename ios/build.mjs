import {spawnSync} from 'node:child_process';
import path from 'node:path';
const role=process.argv[2],action=process.argv[3]||'verify';
if(!['client','admin'].includes(role)||!['verify','archive'].includes(action))throw new Error('Kullanım: node ios/build.mjs client|admin verify|archive');
if(process.platform!=='darwin')throw new Error('iOS derlemesi macOS ve Xcode 26 veya üzeri gerektirir.');
const version=spawnSync('xcodebuild',['-version'],{encoding:'utf8'});
if(version.status!==0||Number(version.stdout.match(/Xcode (\d+)/)?.[1]||0)<26)throw new Error('Xcode 26 veya üzerini seçin.');
const scheme=role==='admin'?'OmnigoAdmin':'OmnigoClient';
const args=['-project',path.join(import.meta.dirname,'Omnigo.xcodeproj'),'-scheme',scheme];
if(action==='verify'){
 if(!process.env.OMNIGO_IOS_SIMULATOR_ID)throw new Error('xcrun simctl list devices available ile simülatör kimliğini bulup OMNIGO_IOS_SIMULATOR_ID olarak tanımlayın.');
 args.push('-destination','platform=iOS Simulator,id='+process.env.OMNIGO_IOS_SIMULATOR_ID,'-derivedDataPath',path.join(import.meta.dirname,'build',role),'CODE_SIGNING_ALLOWED=NO','test');
}else{
 if(!/^[A-Z0-9]{10}$/.test(process.env.OMNIGO_APPLE_TEAM_ID||''))throw new Error('Apple Team ID değerini OMNIGO_APPLE_TEAM_ID ile tanımlayın. Parola/anahtar gerekmez.');
 args.push('-destination','generic/platform=iOS','-archivePath',path.join(import.meta.dirname,'build',scheme+'.xcarchive'),'DEVELOPMENT_TEAM='+process.env.OMNIGO_APPLE_TEAM_ID,'archive');
}
const result=spawnSync('xcodebuild',args,{stdio:'inherit'});process.exitCode=result.status??1;
