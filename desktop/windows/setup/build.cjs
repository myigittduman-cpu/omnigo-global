// The branded view wraps the unchanged, hash-verified NSIS installer. No server or business data access.
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const {spawnSync}=require('node:child_process');
const desktop=path.resolve(__dirname,'../..'),root=path.dirname(desktop);
const config=require(path.join(desktop,'tauri.conf.json'));
const compiler='C:/Windows/Microsoft.NET/Framework64/v4.0.30319/csc.exe';
const framework=path.dirname(compiler);
const cli=require(path.join(root,'work/tauri-cli/js/package/main.js'));
const output=path.resolve(root,'../outputs/Omnigo-PC-Kurulum');
const digest=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const reference=name=>[path.join(framework,name),path.join(framework,'WPF',name)].find(file=>fs.existsSync(file));
async function build(role) {
 const name=role==='admin'?'OG Yönetici Portalı':'OG İş Ortağım',folder=role==='admin'?'OGYoneticiPortali':'OGMusteriPortali';
 const payload=path.join(root,'deploy',`desktop-${config.version}`,'public/updates',role,`${role}-${config.version}-x64-setup.exe`);
 const stage=path.join(desktop,'target/modern-setup',role);fs.mkdirSync(stage,{recursive:true});
 const metadata=path.join(stage,'config.xml'),assembly=path.join(stage,'AssemblyInfo.cs');
 fs.writeFileSync(metadata,`<Setup><Name>${name}</Name><Folder>${folder}</Folder><Version>${config.version}</Version><Hash>${digest(fs.readFileSync(payload))}</Hash></Setup>`);
 fs.writeFileSync(assembly,`using System.Reflection;[assembly:AssemblyTitle("${name} Kurulumu")][assembly:AssemblyProduct("${name}")][assembly:AssemblyCompany("Omnigo Global")][assembly:AssemblyVersion("${config.version}.0")][assembly:AssemblyFileVersion("${config.version}.0")]`);
 const destination=path.join(output,`${name}.exe`);
 const sources=['Program.cs','SetupConfig.cs','SetupPayload.cs','RunningApplications.cs','SetupWindow.cs'].map(file=>path.join(__dirname,file));
 const references=['System.dll','System.Core.dll','System.Xml.dll','System.Xml.Linq.dll','System.Xaml.dll','System.Windows.Forms.dll','WindowsBase.dll','PresentationCore.dll','PresentationFramework.dll'].map(reference);
 if(references.some(value=>!value))throw new Error('Windows WPF çalışma kitaplıkları bulunamadı.');
 const args=['/nologo','/target:winexe','/platform:x64',`/out:${destination}`,`/win32icon:${path.join(desktop,'icons',role,'icon.ico')}`,`/win32manifest:${path.join(__dirname,'setup.manifest')}`,...references.map(file=>'/reference:'+file),
  `/resource:${metadata},Setup.Config`,`/resource:${payload},Setup.Payload`,`/resource:${path.join(__dirname,'Setup.xaml')},Setup.View`,`/resource:${path.join(root,'public/assets/omnigo-logo.png')},Setup.Logo`,`/resource:${path.join(desktop,'icons',role,'icon.ico')},Setup.Icon`,...sources,assembly];
 const result=spawnSync(compiler,args,{encoding:'utf8',windowsHide:true});
 if(result.status!==0)throw new Error(result.stdout+result.stderr);
 process.env.TAURI_SIGNING_PRIVATE_KEY=fs.readFileSync(path.join(root,'data/desktop-signing',`${role}.key`),'utf8').trim();
 process.env.TAURI_SIGNING_PRIVATE_KEY_PASSWORD='';
 await cli.run(['signer','sign','--app-version',config.version,destination],'tauri');
 fs.writeFileSync(destination+'.sha256',digest(fs.readFileSync(destination))+'  '+name+'.exe\n');
 console.log(JSON.stringify({role,version:config.version,modernSetup:destination,payloadSha256:digest(fs.readFileSync(payload)),sha256:digest(fs.readFileSync(destination))}));
}
module.exports=async(roles=['client','admin'])=>{for(const role of roles)await build(role);};
if(require.main===module)module.exports().catch(error=>{console.error(error.message);process.exitCode=1;});
