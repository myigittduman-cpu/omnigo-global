import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {javaConfiguration} from '../store/configuration.mjs';
import {mobileChannel,firebaseResources,resourceXml} from './configuration.mjs';
export async function prepareRole(role,{requireFirebase=false,directUpdates=false,privateDistribution=false}={}) {
 const channel=mobileChannel(role,{privateDistribution});
 const output=path.join(import.meta.dirname,'app/build/generated/portal',role);
 for(const dir of ['java','assets','res/values'])await fs.mkdir(path.join(output,dir),{recursive:true});
 await fs.writeFile(path.join(output,'java/PortalConfiguration.java'),javaConfiguration(channel,{directUpdates}));
 await fs.writeFile(path.join(output,'java/UpdateChecker.java'),directUpdates?await fs.readFile(new URL('../app/src/main/java/com/omnigoglobal/client/UpdateChecker.java',import.meta.url),'utf8'):'package com.omnigoglobal.client; final class UpdateChecker { static void check(android.app.Activity activity) {} static boolean open(android.app.Activity activity, String url) { return false; } }');
 await fs.writeFile(path.join(output,'assets/portal-bridge.js'),(await fs.readFile(new URL('../app/src/main/assets/portal-bridge.js',import.meta.url),'utf8')).replaceAll('__OMNIGO_PORTAL_ORIGIN__',channel.origin));
 await fs.copyFile(new URL('../app/src/main/assets/native-translations.json',import.meta.url),path.join(output,'assets/native-translations.json'));
 const file=path.resolve(import.meta.dirname,'../../data/firebase',role+(privateDistribution?'-private':'')+'-google-services.json');
 const resources={app_name:channel.launcherName};let firebaseConfigured=false;
 try {
  const config=JSON.parse(await fs.readFile(file,'utf8'));
  Object.assign(resources,firebaseResources(config,channel.applicationId));firebaseConfigured=true;
 } catch(error) { if(error.code!=='ENOENT')throw error; }
 // Always replace previous generated resources, including after a config is removed.
 await fs.writeFile(path.join(output,'res/values/portal.xml'),resourceXml(resources));
 if(requireFirebase&&!firebaseConfigured)throw new Error(role+': Firebase bağlantısı olmadan mağaza adayı hazırlanamaz. Yerel kontrol için --candidate kullanın.');
 return {channel,output,firebaseConfigured};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url))for(const role of ['client','admin']){
 const result=await prepareRole(role);console.log(role+': '+(result.firebaseConfigured?'Firebase yapılandırması doğrulandı.':'Firebase bağlı değil; yalnızca derleme/test yapılabilir.'));
}
