package com.omnigoglobal.client;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.net.Uri;
import org.json.JSONObject;
import java.net.URL;
import javax.net.ssl.HttpsURLConnection;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;

final class UpdateChecker {
    static final int VERSION_CODE = PortalConfiguration.VERSION_CODE;
    static boolean open(Activity activity, String url) {
        if (!PortalOrigin.allowsUpdate(url)) return false;
        try { activity.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); }
        catch (Exception error) { android.widget.Toast.makeText(activity,NativeLanguage.t("Android güncellemesini indirmek için telefonda bir tarayıcı açın."),android.widget.Toast.LENGTH_LONG).show(); }
        return true;
    }
    static void check(Activity activity) {
        new Thread(() -> {
            HttpsURLConnection connection = null;
            try {
                connection = (HttpsURLConnection)new URL(PortalOrigin.ORIGIN + PortalConfiguration.UPDATE_PATH).openConnection();
                connection.setInstanceFollowRedirects(false);
                connection.setConnectTimeout(5000); connection.setReadTimeout(5000); connection.setUseCaches(false);
                if (connection.getResponseCode() != 200) return;
                ByteArrayOutputStream bytes = new ByteArrayOutputStream();
                try (InputStream input = connection.getInputStream()) {
                    byte[] buffer = new byte[2048]; int length;
                    while ((length = input.read(buffer)) != -1) { bytes.write(buffer, 0, length); if (bytes.size() > 16384) return; }
                }
                JSONObject release = new JSONObject(bytes.toString(StandardCharsets.UTF_8.name()));
                String url = release.getString("url");
                if (release.getInt("versionCode") <= VERSION_CODE || !PortalOrigin.allowsUpdate(url)) return;
                activity.runOnUiThread(() -> {
                    if (activity.isFinishing() || activity.isDestroyed()) return;
                    new AlertDialog.Builder(activity).setTitle(NativeLanguage.t("Omnigo güncellemesi hazır"))
                        .setMessage(NativeLanguage.t("Yeni Android sürümü indirilmeye hazır. İndirme sonrasında Android kurulum onayı isteyecek; hesabınız korunur."))
                        .setNegativeButton("Daha sonra", null).setPositiveButton(NativeLanguage.t("İndir"), (dialog, which) -> {
                            open(activity, url);
                        }).show();
                });
            } catch (Exception ignored) { /* Optional checks must not interrupt login or reporting. */ }
            finally { if (connection != null) connection.disconnect(); }
        }, "Omnigo-update-check").start();
    }
}
