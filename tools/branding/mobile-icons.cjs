const fs=require('node:fs/promises');
const path=require('node:path');
const sharp=require(require.resolve('sharp',{paths:[process.env.OMNIGO_NODE_MODULES||path.join(path.dirname(process.execPath),'../node_modules')]}));
const background='#101c2c';
module.exports=async function buildMobileIcons(root){
 const assets=path.join(root,'public/assets');
 for(const role of ['admin','client']){
  const source=path.join(root,'assets/app-icons',role+'-windows.png');
  for(const size of [180,192,512,1024]){
   await sharp(source).resize(size,size).png().toFile(path.join(assets,`app-${role}-${size}-v6.png`));
   const side=Math.round(size*.9),inset=Math.floor((size-side)/2),image=await sharp(source).resize(side,side).png().toBuffer();
   await sharp({create:{width:size,height:size,channels:3,background}}).composite([{input:image,left:inset,top:inset}]).removeAlpha().png().toFile(path.join(assets,`app-${role}-mobile-${size}-v3.png`));
  }
  const foreground=await sharp(source).resize(368,368).png().toBuffer();
  await sharp({create:{width:512,height:512,channels:3,background}}).composite([{input:foreground,left:72,top:72}]).removeAlpha().png().toFile(path.join(assets,`app-${role}-mobile-maskable-512-v3.png`));
  const target=path.join(root,'android/mobile/app/src',role,'res/drawable-nodpi/app_icon.png');
  await fs.mkdir(path.dirname(target),{recursive:true});await fs.copyFile(path.join(assets,`app-${role}-mobile-512-v3.png`),target);
  const ios=path.join(root,'ios/Resources/Assets.xcassets',role==='admin'?'AppIconAdmin.appiconset':'AppIconClient.appiconset','AppIcon.png');
  await fs.copyFile(path.join(assets,`app-${role}-mobile-1024-v3.png`),ios);
  const manifest=JSON.parse(await fs.readFile(path.join(root,'public',role+'.webmanifest'),'utf8'));
  manifest.icons=[192,512,1024].map(size=>({src:`/assets/app-${role}-${size}-v6.png`,sizes:`${size}x${size}`,type:'image/png',purpose:'any'}));
  await fs.writeFile(path.join(root,'public',role+'.webmanifest'),JSON.stringify(manifest)+'\n');
  const mobile={...manifest,icons:[192,512,1024].map(size=>({src:`/assets/app-${role}-mobile-${size}-v3.png`,sizes:`${size}x${size}`,type:'image/png',purpose:'any'}))};
  mobile.icons.push({src:`/assets/app-${role}-mobile-maskable-512-v3.png`,sizes:'512x512',type:'image/png',purpose:'maskable'});
  await fs.writeFile(path.join(root,'public',role+'-mobile-v3.webmanifest'),JSON.stringify(mobile)+'\n');
 }
 await fs.copyFile(path.join(assets,'app-client-mobile-1024-v3.png'),path.join(root,'ios/Resources/Assets.xcassets/AppIcon.appiconset/AppIcon.png'));
};
if(require.main===module)module.exports(path.resolve(__dirname,'../..')).then(()=>console.log('İki rol: şeffaf Windows ve lacivert mobil simgeler hazır.')).catch(error=>{console.error(error.message);process.exitCode=1;});
