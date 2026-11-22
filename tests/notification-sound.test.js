import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {orderNotificationPresentation} from '../public/order-alert-model.js';
import {t as i18nT} from '../public/i18n.js';
const source=fs.readFileSync(new URL('../public/notification-sound.js',import.meta.url),'utf8').replace(/^import[^\n]*\n/gm,'').replaceAll('export ','');
function fixture(){
 const events={},worker={},started=[];let now=2000;
 const context=vm.createContext({window:{AudioContext:class{state='suspended';async resume(){this.state='running';}async decodeAudioData(){return {};}createBufferSource(){return{connect(){},disconnect(){},start(){started.push(now);}};}}},document:{hidden:false,addEventListener:(type,fn)=>events[type]=fn},navigator:{serviceWorker:{addEventListener:(type,fn)=>worker[type]=fn}},fetch:async()=>({ok:true,arrayBuffer:async()=>new ArrayBuffer(1)}),Date:{now:()=>now}});
 context.i18nT=i18nT;vm.runInContext(source,context);
 return {events,worker,started,context,setTime:value=>now=value};
}
test('Ton tam 1,5 saniye; web ve Android aynı ses dosyasını kullanır',()=>{
 const wave=fs.readFileSync(new URL('../public/assets/notification-chime-v2.wav',import.meta.url));
 assert.equal(wave.toString('ascii',0,4),'RIFF');assert.equal(wave.toString('ascii',8,12),'WAVE');
 assert.equal(wave.readUInt16LE(22),1);assert.equal(wave.readUInt16LE(34),16);
 assert.equal(wave.readUInt32LE(40)/wave.readUInt32LE(28),1.5);
 assert.equal(wave.readInt16LE(44),0);assert.equal(wave.readInt16LE(wave.length-2),0);
 let peak=0;for(let i=44;i<wave.length;i+=2)peak=Math.max(peak,Math.abs(wave.readInt16LE(i))/32768);
 assert.ok(peak>.2&&peak<.3);assert.deepEqual(wave,fs.readFileSync(new URL('../android/mobile/app/src/main/res/raw/omnigo_notification.wav',import.meta.url)));
});
test('Kullanıcı etkileşimi öncesinde ses çalınmaz; peş peşe olaylar tek ses olur',async()=>{
 const f=fixture();assert.equal(vm.runInContext('playNotificationSound()',f.context),false);
 assert.equal(await vm.runInContext('unlockNotificationSound()',f.context),true);
 assert.equal(vm.runInContext('playNotificationSound()',f.context),true);
 f.setTime(2300);assert.equal(vm.runInContext('playNotificationSound()',f.context),true);assert.equal(f.started.length,1);
 f.setTime(3600);vm.runInContext('playNotificationSound()',f.context);assert.equal(f.started.length,2);
});
test('Gizli veya geç yanıt veren sayfa sistem sesini susturmaz',async()=>{
 const f=fixture();await vm.runInContext('unlockNotificationSound()',f.context);let result;
 const message={data:{type:'omnigo-notification-sound',deadline:2200},ports:[{postMessage:value=>result=value}]};
 f.context.document.hidden=true;f.worker.message(message);assert.equal(result.played,false);
 f.context.document.hidden=false;f.setTime(2300);f.worker.message(message);assert.equal(result.played,false);assert.equal(f.started.length,0);
 message.data.deadline=2500;f.worker.message(message);assert.equal(result.played,true);assert.equal(f.started.length,1);
});
test('Yerel web bildirimi uygulama adı ile firma ve pazaryeri ayrıntısını ayırır',()=>{
 assert.deepEqual(orderNotificationPresentation({provider:'hepsiburada',company:'Vizyona Beyaz Eşya',count:1}),{title:'Omnigo Global Yönetici Portalı',body:'Vizyona; Hepsiburada’dan yeni bir siparişiniz var.'});
});
