export function validateBadging(text,channel){
 for(const expected of ["name='"+channel.applicationId+"'","versionCode='"+channel.versionCode+"'","versionName='"+channel.version+"'","targetSdkVersion:'36'","sdkVersion:'26'"]){
  if(!text.includes(expected))throw new Error('Android paket kimliği veya sürümü beklenenden farklı.');
 }
 const labels=[...text.matchAll(/application-label(?:-[^:]+)?:'([^']*)'/g)].map(match=>match[1].replaceAll('\u00a0',' '));
 if(!labels.length||labels.some(label=>label!==channel.launcherName.replaceAll('\u00a0',' ')))throw new Error('Android uygulama adı beklenenden farklı.');
 const permissions=[...text.matchAll(/uses-permission(?:-sdk-\d+)?: name='([^']+)'/g)].map(match=>match[1]);
 const forbidden=['android.permission.REQUEST_INSTALL_PACKAGES','android.permission.MANAGE_EXTERNAL_STORAGE','android.permission.READ_EXTERNAL_STORAGE','android.permission.WRITE_EXTERNAL_STORAGE','com.google.android.gms.permission.AD_ID'];
 if(permissions.some(value=>forbidden.includes(value)))throw new Error('Mağaza adayında beklenmeyen izin bulundu.');
 if(!permissions.includes('android.permission.POST_NOTIFICATIONS'))throw new Error('Bildirim izni pakete eklenmemiş.');
 return permissions;
}
export function inspectDex(buffers,{directUpdates=false}={}){
 if(!buffers.length)throw new Error('APK içinde çalıştırılabilir kod bulunamadı.');
 if(!directUpdates)for(const buffer of buffers)if(buffer.includes(Buffer.from('/downloads/android/')))throw new Error('Mağaza paketinde dış APK güncellemesi bulundu.');
 for(const name of ['FirebasePushController','OmnigoMessagingService','PushTokenWorker'])if(!buffers.some(buffer=>buffer.includes(Buffer.from(name))))throw new Error('Bildirim kodu pakette eksik: '+name);
}
