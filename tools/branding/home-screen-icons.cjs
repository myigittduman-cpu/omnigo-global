const fs=require('node:fs/promises');
const path=require('node:path');
const opaquePng=require('./opaque-png.cjs');
const png=require(require.resolve('@pdf-lib/upng',{paths:[process.env.OMNIGO_NODE_MODULES||path.join(path.dirname(process.execPath),'../node_modules')]})).default;
const background=[16,28,44];
module.exports=async function buildHomeIcons({root,role,sizes}){
 const folder=path.join(root,'public/assets');
 // Apple adds a white background to transparent touch icons; only its icon is opaque.
 await fs.writeFile(path.join(folder,`app-${role}-180-v3.png`),opaquePng(await fs.readFile(path.join(sizes,'180x180.png')),{background}));
 for(const size of [192,512])await fs.copyFile(path.join(sizes,`${size}x${size}.png`),path.join(folder,`app-${role}-${size}-v4.png`));
 // Keep the complete circular logo inside Android's maskable safe area.
 const bytes=await fs.readFile(path.join(sizes,'384x384.png'));
 const decoded=png.decode(bytes.buffer.slice(bytes.byteOffset,bytes.byteOffset+bytes.byteLength)),pixels=new Uint8Array(png.toRGBA8(decoded)[0]);
 const canvas=new Uint8Array(512*512*4);
 for(let y=0;y<384;y++)for(let x=0;x<384;x++)canvas.set(pixels.subarray((y*384+x)*4,(y*384+x+1)*4),((y+64)*512+x+64)*4);
 const previous=globalThis.UZIP;globalThis.UZIP=null;
 try{await fs.writeFile(path.join(folder,`app-${role}-maskable-512-v4.png`),Buffer.from(png.encodeLL([canvas.buffer],512,512,3,1,8,[0])));}
 finally{if(previous===undefined)delete globalThis.UZIP;else globalThis.UZIP=previous;}
};
