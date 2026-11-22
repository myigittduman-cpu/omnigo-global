// The approved globe remains shared. Only the small approved lower-right role mark differs.
const fs=require('node:fs/promises');const path=require('node:path');
const sharp=require(require.resolve('sharp',{paths:[process.env.OMNIGO_NODE_MODULES||path.join(path.dirname(process.execPath),'../node_modules')]}));
const root=path.resolve(__dirname,'../..'),size=1280;
module.exports=async function buildSymbolIcons(){
 const globe=await sharp(path.join(root,'assets/branding/approved-round-20261103.png')).resize(1114,1114,{fit:'contain',background:'#00000000'}).ensureAlpha().png().toBuffer();
 const symbols={admin:'<path d="M4 6h16M4 12h16M4 18h16M8 3v6M16 9v6M10 15v6"/>',client:'<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>'};
 for(const role of ['admin','client']){
  const target=path.join(root,'assets/app-icons',role+'-windows.png');
  const symbol=Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="278" height="299" viewBox="0 0 24 26"><defs><linearGradient id="g" x2="1" y2="1"><stop stop-color="#fff2b0"/><stop offset=".4" stop-color="#e2ad45"/><stop offset=".7" stop-color="#f4d06e"/><stop offset="1" stop-color="#9e661a"/></linearGradient></defs><g fill="none" stroke="url(#g)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">${symbols[role]}</g></svg>`);
  await sharp({create:{width:size,height:size,channels:4,background:'#00000000'}}).composite([{input:globe,left:83,top:83},{input:symbol,left:978,top:958}]).png().toFile(target);
  await fs.copyFile(target,path.join(root,'assets/app-icons',role+'.png'));
 }
};
if(require.main===module)module.exports().then(()=>console.log('Onaylı OMNIGO / GLOBAL şeffaf simge kaynakları hazır.')).catch(error=>{console.error(error.message);process.exitCode=1;});
