import {spawn} from 'node:child_process';
import {createHash} from 'node:crypto';
import path from 'node:path';

// Windows owns this lock, so a power loss cannot leave a stale pull.lock file.
export async function acquireBackupLock(root) {
 const name='Local\\OmnigoBackup-'+createHash('sha256').update(path.resolve(root).toLowerCase()).digest('hex').slice(0,32);
 const child=spawn('powershell.exe',['-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',path.join(import.meta.dirname,'hold-lock.ps1'),'-Name',name],{windowsHide:true,stdio:['pipe','pipe','ignore']});
 const done=new Promise(resolve=>child.once('exit',resolve));
 child.stdin.on('error',()=>{});
 try{
  await new Promise((resolve,reject)=>{
   let output='';const timer=setTimeout(()=>reject(new Error('Yedek kilidi başlatılamadı.')),15000);
   const finish=(error)=>{clearTimeout(timer);error?reject(error):resolve();};
   child.once('error',()=>finish(new Error('Yedek kilidi başlatılamadı.')));
   child.once('exit',()=>finish(new Error('Yedek kopyalama kilidi alınamadı.')));
   child.stdout.on('data',data=>{output+=data.toString();if(output.includes('\n'))finish(output.trim()==='acquired'?null:new Error('Başka bir yedek kopyalama işlemi sürüyor.'));});
  });
 }catch(error){child.stdin.end();if(child.exitCode===null)child.kill();throw error;}
 let released=false;
 return async()=>{if(released)return;released=true;child.stdin.end('\n');const timer=setTimeout(()=>child.kill(),5000);await done;clearTimeout(timer);};
}
