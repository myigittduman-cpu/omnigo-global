import {storeChannel} from '../store/configuration.mjs';
export const mobileVersion='1.0.34';
export const mobileVersionCode=35;
export const privateAdminApplicationId='com.omnigoglobal.admin.privateapp';
export function mobileChannel(role,{privateDistribution=false}={}){
 if(privateDistribution&&role!=='admin')throw new Error('Özel dağıtım yalnız yönetici uygulamasına uygulanır.');
 return {...storeChannel(role),notificationTitle:role==='admin'?'Omnigo Global Yönetici Portalı':'Omnigo Global İş Ortağım',version:role==='client'?'1.0.35':mobileVersion,versionCode:role==='client'?36:mobileVersionCode,...(privateDistribution?{applicationId:privateAdminApplicationId,title:'OG Yönetici Portalı',filenamePrefix:'OG-Yonetici-Portali'}:{})};
}

export function firebaseResources(config,applicationId){
 const clients=config?.client?.filter(value=>value.client_info?.android_client_info?.package_name===applicationId)||[];
 if(clients.length!==1)throw new Error('Firebase paket kimliği eksik veya birden fazla: '+applicationId);
 const project=config.project_info,client=clients[0];
 const resources={google_app_id:client.client_info.mobilesdk_app_id,gcm_defaultSenderId:project?.project_number,
  google_api_key:client.api_key?.[0]?.current_key,project_id:project?.project_id};
 if(Object.values(resources).some(value=>typeof value!=='string'||!value.trim()))throw new Error('Firebase yapılandırması eksik.');
 if(!/^\d+$/.test(resources.gcm_defaultSenderId)||!resources.google_app_id.startsWith('1:'+resources.gcm_defaultSenderId+':android:'))throw new Error('Firebase uygulama ve proje numaraları eşleşmiyor.');
 return resources;
}

export function resourceXml(resources){
 const escape=value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","\\'");
 return '<resources>'+Object.entries(resources).map(([key,value])=>`<string name="${key}" translatable="false">${escape(value)}</string>`).join('')+'</resources>';
}
