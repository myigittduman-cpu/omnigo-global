package com.omnigoglobal.client;

import android.app.Activity;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.pm.PackageManager;
import android.os.Build;
import com.google.firebase.FirebaseApp;
import com.google.firebase.messaging.FirebaseMessaging;
import org.json.JSONObject;

public final class FirebasePushController implements MobilePushHandler {
    private final Activity activity;
    private Reply permissionReply;
    private static final int PERMISSION_REQUEST=8127;
    static final String SOUND_CHANNEL="omnigo_updates_chime_v1";
    public FirebasePushController(Activity activity) {this.activity=activity;channels(activity);}
    static void channels(android.content.Context context) {
        NotificationManager manager=context.getSystemService(NotificationManager.class);
        NotificationChannel previous=manager.getNotificationChannel("omnigo_updates");
        NotificationChannel normal=new NotificationChannel(SOUND_CHANNEL,"Sipariş ve operasyon bildirimleri",previous==null?NotificationManager.IMPORTANCE_HIGH:previous.getImportance());
        normal.setSound(android.net.Uri.parse("android.resource://"+context.getPackageName()+"/raw/omnigo_notification"),
            new android.media.AudioAttributes.Builder().setUsage(android.media.AudioAttributes.USAGE_NOTIFICATION).setContentType(android.media.AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        normal.setLockscreenVisibility(android.app.Notification.VISIBILITY_PRIVATE);
        NotificationChannel silent=new NotificationChannel("omnigo_updates_silent","Sessiz operasyon bildirimleri",NotificationManager.IMPORTANCE_LOW);
        silent.setSound(null,null);silent.setLockscreenVisibility(android.app.Notification.VISIBILITY_PRIVATE);
        manager.createNotificationChannel(normal);manager.createNotificationChannel(silent);
    }
    private boolean permitted() {return (Build.VERSION.SDK_INT<33||activity.checkSelfPermission("android.permission.POST_NOTIFICATIONS")==PackageManager.PERMISSION_GRANTED)
        && activity.getSystemService(NotificationManager.class).areNotificationsEnabled();}
    private boolean configured() {return !FirebaseApp.getApps(activity).isEmpty();}
    private JSONObject state() throws Exception {
        JSONObject saved=PushStore.read(activity);String installation=saved.optString("installationId");
        if(!installation.matches("[a-f0-9]{64}")) {
            byte[] bytes=new byte[32];new java.security.SecureRandom().nextBytes(bytes);StringBuilder value=new StringBuilder();
            for(byte b:bytes)value.append(String.format(java.util.Locale.ROOT,"%02x",b&255));installation=value.toString();
            saved.put("installationId",installation);PushStore.write(activity,saved);
        }
        return new JSONObject().put("kind","android").put("appId",activity.getPackageName()).put("environment","production")
            .put("installationId",installation).put("configured",configured()).put("permission",permitted()).put("displayVersion",2)
            .put("enabled",saved.optBoolean("enabled")&&permitted()).put("id",saved.optInt("id"))
            .put("userId",saved.optInt("userId")).put("sound",saved.optBoolean("sound",true));
    }
    private void token(Reply reply) {
        if(!configured()){reply.complete(null,"Firebase bağlantısı henüz yapılandırılmadı.");return;}
        FirebaseMessaging.getInstance().setAutoInitEnabled(true);
        FirebaseMessaging.getInstance().getToken().addOnCompleteListener(activity,task->{
            if(!task.isSuccessful()){reply.complete(null,"Bildirim belirteci alınamadı. Yeniden deneyin.");return;}
            try{reply.complete(state().put("token",task.getResult()).toString(),null);}catch(Exception error){reply.complete(null,"Bildirim bilgisi okunamadı.");}
        });
    }
    @Override public void handle(String command,JSONObject data,Reply reply) {
        try {
            if("push_status".equals(command)) {if(PushStore.read(activity).optBoolean("enabled")&&permitted())token(reply);else reply.complete(state().toString(),null);}
            else if("push_enable".equals(command)) {
                if(!configured()){reply.complete(null,"Firebase bağlantısı henüz yapılandırılmadı.");return;}
                if(Build.VERSION.SDK_INT>=33&&!permitted()) {
                    if(permissionReply!=null){reply.complete(null,"Bildirim izin işlemi sürüyor.");return;}
                    permissionReply=reply;activity.requestPermissions(new String[]{"android.permission.POST_NOTIFICATIONS"},PERMISSION_REQUEST);
                }else if(permitted())token(reply);else reply.complete(null,"Bildirimler cihaz ayarlarından kapatılmış.");
            }else if("push_binding".equals(command)) {
                if(data==null||data.optInt("id")<1||data.optInt("userId")<1)throw new Exception();
                JSONObject values=new JSONObject().put("id",data.getInt("id")).put("userId",data.getInt("userId")).put("enabled",true).put("sound",data.optBoolean("sound",true));
                if(!data.isNull("refreshToken"))values.put("refreshToken",data.getString("refreshToken"));
                PushStore.patch(activity,values);reply.complete(state().toString(),null);
            }else if("push_disable".equals(command)) {
                PushStore.patch(activity,new JSONObject().put("enabled",false).put("id",0).put("userId",0).put("refreshToken",""));
                if(configured()){FirebaseMessaging.getInstance().setAutoInitEnabled(false);FirebaseMessaging.getInstance().deleteToken();}
                activity.getSystemService(NotificationManager.class).cancelAll();reply.complete(state().toString(),null);
            }else reply.complete(null,"Bildirim işlemi desteklenmiyor.");
        }catch(Exception error){reply.complete(null,"Bildirim ayarı kaydedilemedi.");}
    }
    @Override public void permissionResult(int requestCode) {
        if(requestCode!=PERMISSION_REQUEST||permissionReply==null)return;
        Reply reply=permissionReply;permissionReply=null;
        if(permitted())token(reply);else reply.complete(null,"Bildirim izni verilmedi. Cihaz ayarlarından değiştirebilirsiniz.");
    }
}
