package com.omnigoglobal.client;

import android.app.Activity;
import org.json.JSONObject;

interface MobilePushHandler {
    interface Reply { void complete(String value, String error); }
    void handle(String command, JSONObject data, Reply reply);
    void permissionResult(int requestCode);
    static MobilePushHandler create(Activity activity) {
        try { return (MobilePushHandler)Class.forName("com.omnigoglobal.client.FirebasePushController").getConstructor(Activity.class).newInstance(activity); }
        catch (ClassNotFoundException ignored) { return null; }
        catch (ReflectiveOperationException error) { return null; }
    }
}
