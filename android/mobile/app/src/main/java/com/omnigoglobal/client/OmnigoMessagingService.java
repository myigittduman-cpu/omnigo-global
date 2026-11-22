package com.omnigoglobal.client;

import android.app.Notification;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.graphics.drawable.Icon;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import org.json.JSONObject;

public final class OmnigoMessagingService extends FirebaseMessagingService {
    @Override public void onNewToken(String token) {
        try {
            PushStore.patch(this,new JSONObject().put("pendingToken",token));
            if(PushStore.read(this).optBoolean("enabled"))PushTokenWorker.schedule(this);
        }catch(Exception ignored) { /* Next authenticated app launch also refreshes the token. */ }
    }
    @Override public void onMessageReceived(RemoteMessage message) {
        JSONObject saved=PushStore.read(this);if(!saved.optBoolean("enabled"))return;
        FirebasePushController.channels(this);
        String destination=PortalOrigin.notificationPath(message.getData().get("url"));
        Intent intent=new Intent(this,MainActivity.class).putExtra("url",destination).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP|Intent.FLAG_ACTIVITY_SINGLE_TOP);
        String tag=message.getData().get("tag");if(tag==null||!tag.matches("[a-f0-9]{64}"))return;
        PendingIntent click=PendingIntent.getActivity(this,tag.hashCode(),intent,PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
        String body=NotificationContent.body(message.getData().get("body"),message.getNotification()==null?null:message.getNotification().getBody());
        Notification notice=new Notification.Builder(this,saved.optBoolean("sound",true)?FirebasePushController.SOUND_CHANNEL:"omnigo_updates_silent")
            .setSmallIcon(R.drawable.ic_notification).setContentTitle(PortalConfiguration.NOTIFICATION_TITLE)
            .setLargeIcon(Icon.createWithResource(this,R.drawable.notification_logo))
            .setColor(getColor(R.color.notification_brand))
            .setContentText(body).setStyle(new Notification.BigTextStyle().bigText(body))
            .setContentIntent(click).setAutoCancel(true).setVisibility(Notification.VISIBILITY_PRIVATE).build();
        try {getSystemService(NotificationManager.class).notify(tag,0,notice);}catch(SecurityException ignored) { /* Permission may have been revoked. */ }
    }
}
