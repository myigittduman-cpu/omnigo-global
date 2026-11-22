import test from 'node:test';
import assert from 'node:assert/strict';
import {firebaseResources,mobileChannel,resourceXml,privateAdminApplicationId} from '../android/mobile/configuration.mjs';
import {validateBadging,inspectDex} from '../android/mobile/artifact-validation.mjs';

const firebase=role=>({project_info:{project_id:'test-only-project',project_number:'123456'},client:[{client_info:{android_client_info:{package_name:mobileChannel(role).applicationId},mobilesdk_app_id:'1:123456:android:abcdef'},api_key:[{current_key:'test-only-not-a-real-key'}]}]});
test('Özel yönetici dağıtımı müşteri ve mevcut yönetici paketlerini değiştirmez',()=>{
 assert.equal(mobileChannel('admin',{privateDistribution:true}).applicationId,privateAdminApplicationId);
 assert.equal(mobileChannel('admin').applicationId,'com.omnigoglobal.admin');
 assert.equal(mobileChannel('client').applicationId,'com.omnigoglobal.client');
 assert.throws(()=>mobileChannel('client',{privateDistribution:true}));
 assert.throws(()=>firebaseResources(firebase('admin'),privateAdminApplicationId),/paket kimliği/);
});
test('Firebase her uygulamanın kesin paket kimliği ve proje numarasıyla eşleşir',()=>{
 for(const role of ['client','admin']){
  const config=firebase(role),channel=mobileChannel(role);
  assert.equal(firebaseResources(config,channel.applicationId).project_id,'test-only-project');
  assert.throws(()=>firebaseResources(config,mobileChannel(role==='admin'?'client':'admin').applicationId),/paket kimliği/);
  assert.throws(()=>firebaseResources({...config,client:[...config.client,...config.client]},channel.applicationId),/birden fazla/);
  const mismatch=structuredClone(config);mismatch.project_info.project_number='999';
  assert.throws(()=>firebaseResources(mismatch,channel.applicationId),/eşleşmiyor/);
  const missing=structuredClone(config);missing.client[0].api_key=[];
  assert.throws(()=>firebaseResources(missing,channel.applicationId),/eksik/);
 }
 assert.throws(()=>mobileChannel('unknown'));
 assert.equal(resourceXml({app_name:'Şirket & <test>'}),'<resources><string name="app_name" translatable="false">Şirket &amp; &lt;test&gt;</string></resources>');
});
test('Mağaza çıktısı yanlış kimlik, eski sürüm ve geniş izinleri reddeder',()=>{
 const channel=mobileChannel('admin');
 const text=`package: name='${channel.applicationId}' versionCode='${channel.versionCode}' versionName='${channel.version}'\napplication-label:'${channel.launcherName}'\nsdkVersion:'26'\ntargetSdkVersion:'36'\nuses-permission: name='android.permission.POST_NOTIFICATIONS'`;
 assert.deepEqual(validateBadging(text,channel),['android.permission.POST_NOTIFICATIONS']);
 assert.throws(()=>validateBadging(text.replace(channel.launcherName,'Eski uygulama adı'),channel),/uygulama adı/);
 for(const bad of [text.replace(channel.applicationId,mobileChannel('client').applicationId),text.replace(`versionCode='${channel.versionCode}'`,`versionCode='${channel.versionCode-1}'`),text.replace(`versionName='${channel.version}'`,"versionName='0.0.0'"),text.replace("targetSdkVersion:'36'","targetSdkVersion:'35'"),text.replace('POST_NOTIFICATIONS','CAMERA')])assert.throws(()=>validateBadging(bad,channel));
 for(const name of ['android.permission.REQUEST_INSTALL_PACKAGES','android.permission.MANAGE_EXTERNAL_STORAGE','com.google.android.gms.permission.AD_ID'])assert.throws(()=>validateBadging(text+`\nuses-permission: name='${name}'`,channel),/beklenmeyen izin/);
});
test('Bütün DEX dosyaları incelenir; bildirim sınıfları bulunmalı, dış güncelleyici bulunmamalı',()=>{
 const buffers=['FirebasePushController','OmnigoMessagingService','PushTokenWorker'].map(s=>Buffer.from(s));
 inspectDex(buffers);
 assert.throws(()=>inspectDex([]),/kod bulunamadı/);
 assert.throws(()=>inspectDex(buffers.slice(0,2)),/eksik/);
 assert.throws(()=>inspectDex([...buffers,Buffer.from('/downloads/android/client/latest.json')]),/dış APK/);
});
