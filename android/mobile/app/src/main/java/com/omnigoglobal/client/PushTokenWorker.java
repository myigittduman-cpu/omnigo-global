package com.omnigoglobal.client;

import android.content.Context;
import androidx.work.*;
import java.net.URL;
import javax.net.ssl.HttpsURLConnection;
import java.util.concurrent.TimeUnit;
import org.json.JSONObject;

public final class PushTokenWorker extends Worker {
    public PushTokenWorker(Context context,WorkerParameters parameters){super(context,parameters);}
    static void schedule(Context context) {
        OneTimeWorkRequest request=new OneTimeWorkRequest.Builder(PushTokenWorker.class)
            .setConstraints(new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL,30,TimeUnit.SECONDS).build();
        WorkManager.getInstance(context).enqueueUniqueWork("omnigo-push-token",ExistingWorkPolicy.REPLACE,request);
    }
    @Override public Result doWork() {
        Context context=getApplicationContext();JSONObject state=PushStore.read(context);
        if(!state.optBoolean("enabled")||state.optString("refreshToken").isEmpty()||state.optString("pendingToken").isEmpty())return Result.success();
        HttpsURLConnection connection=null;
        try {
            connection=(HttpsURLConnection)new URL(PortalOrigin.ORIGIN+"/api/mobile-push/refresh").openConnection();
            connection.setInstanceFollowRedirects(false);connection.setConnectTimeout(5000);connection.setReadTimeout(15000);
            connection.setRequestMethod("POST");connection.setDoOutput(true);
            connection.setRequestProperty("Content-Type","application/json");connection.setRequestProperty("X-Omnigo-Device",state.getString("refreshToken"));
            byte[] body=new JSONObject().put("token",state.getString("pendingToken")).toString().getBytes(java.nio.charset.StandardCharsets.UTF_8);
            connection.setFixedLengthStreamingMode(body.length);try(java.io.OutputStream output=connection.getOutputStream()){output.write(body);}
            int status=connection.getResponseCode();
            if(status==200)return Result.success();
            if(status==401||status==403)return Result.failure();
            return (status==429||status>=500)&&getRunAttemptCount()<8?Result.retry():Result.failure();
        }catch(Exception error){return getRunAttemptCount()<8?Result.retry():Result.failure();}
        finally {if(connection!=null)connection.disconnect();}
    }
}
