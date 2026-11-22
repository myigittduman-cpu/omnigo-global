export const UPDATE_STORAGE_KEY='omnigo.web-update.v1';
export const UPDATE_FINALIZATION_MS=5000;
export function validRelease(value){return typeof value==='string'&&/^\d+\.\d+\.\d+$/.test(value);}
export function readPendingUpdate(storage,now=Date.now()){
 try{const value=JSON.parse(storage.getItem(UPDATE_STORAGE_KEY));return validRelease(value?.version)&&Number.isFinite(value?.createdAt)&&now-value.createdAt>=0&&now-value.createdAt<900000?value:null;}catch{return null;}
}
export function rememberUpdate(storage,version,now=Date.now()){
 if(!validRelease(version))throw Error('Güncelleme sürümü doğrulanamadı.');
 storage.setItem(UPDATE_STORAGE_KEY,JSON.stringify({version,createdAt:now}));
}
export function releaseAtLeast(actual,expected){
 if(!validRelease(actual)||!validRelease(expected))return false;
 const a=actual.split('.').map(Number),b=expected.split('.').map(Number);
 for(let i=0;i<3;i++)if(a[i]!==b[i])return a[i]>b[i];return true;
}
export async function readWebRelease(){
 const response=await fetch('/version.json',{cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(15000)});
 if(!response.ok)throw Error('Güncelleme kontrol edilemedi. Bağlantınızı kontrol edip yeniden deneyin.');
 const release=await response.json();if(!validRelease(release.version))throw Error('Güncelleme sürümü doğrulanamadı.');return release.version;
}
