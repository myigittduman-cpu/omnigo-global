const fs=require('node:fs');
const path=require('node:path');
const {spawnSync}=require('node:child_process');

// Reuse the approved transparent artwork; Windows must also have unplated variants.
exports.prepareIconResources=async function({root,stage,cli,role}){
 if(!['admin','client'].includes(role))throw new Error('Simge rolü geçersiz.');
 const source=path.join(root,'desktop/icons',role,'icon.png'),generated=path.join(path.dirname(stage),'target-icons');
 const sizes=[16,20,24,30,32,36,40,44,48,60,64,72,80,96,256];
 await cli.run(['icon',source,'--output',generated,...sizes.flatMap(size=>['--png',String(size)])],'tauri');
 for(const size of sizes)for(const suffix of ['', '_altform-unplated','_altform-lightunplated']){
  fs.copyFileSync(path.join(generated,`${size}x${size}.png`),path.join(stage,'Assets',`Square44x44Logo.targetsize-${size}${suffix}.png`));
 }
 const makepri='C:/Program Files (x86)/Windows Kits/10/bin/10.0.26100.0/x64/makepri.exe';
 const config=path.join(path.dirname(stage),'priconfig.xml');
 for(const args of [['createconfig','/cf',config,'/dq','tr-TR','/o'],['new','/pr',stage,'/cf',config,'/mn',path.join(stage,'AppxManifest.xml'),'/of',path.join(stage,'resources.pri'),'/o']]){
  const result=spawnSync(makepri,args,{encoding:'utf8',windowsHide:true});if(result.status!==0)throw new Error(result.stdout+result.stderr);
 }
};
