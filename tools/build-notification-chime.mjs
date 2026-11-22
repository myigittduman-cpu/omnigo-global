import fs from 'node:fs/promises';
// A warm two-note fifth with a soft attack and a gradual, click-free release.
const rate=44100,duration=1.5,count=Math.round(rate*duration),samples=new Float64Array(count);
for(const [delay,frequency,level] of [[0,392,1],[.22,587.33,.72]]){
 for(let i=Math.ceil(delay*rate);i<count;i++){
  const t=i/rate-delay,attack=1-Math.exp(-t/0.025),decay=Math.exp(-t/0.32),remaining=duration-i/rate;
  const tail=remaining>=.28?1:.5-.5*Math.cos(Math.PI*remaining/.28);
  samples[i]+=level*attack*decay*tail*(Math.sin(2*Math.PI*frequency*t)+.09*Math.exp(-t/.16)*Math.sin(2*Math.PI*frequency*2*t)+.015*Math.exp(-t/.08)*Math.sin(2*Math.PI*frequency*3*t));
 }
}
const peak=samples.reduce((max,value)=>Math.max(max,Math.abs(value)),0),wav=Buffer.alloc(44+count*2);
wav.write('RIFF');wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);
wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(rate,24);wav.writeUInt32LE(rate*2,28);
wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(count*2,40);
for(let i=0;i<count;i++)wav.writeInt16LE(Math.round(samples[i]/peak*.24*32767),44+i*2);
await fs.writeFile('public/assets/notification-chime-v2.wav',wav);
await fs.mkdir('android/mobile/app/src/main/res/raw',{recursive:true});
await fs.writeFile('android/mobile/app/src/main/res/raw/omnigo_notification.wav',wav);
console.log('Bildirim sesi hazır: 1,5 saniye, 44,1 kHz, mono PCM.');
