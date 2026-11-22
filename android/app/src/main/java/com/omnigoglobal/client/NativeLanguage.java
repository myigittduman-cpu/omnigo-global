package com.omnigoglobal.client;

import android.content.Context;
import android.content.SharedPreferences;
import java.io.InputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import org.json.JSONObject;

/** Presentation preference only; no account or marketplace configuration is stored here. */
final class NativeLanguage {
    private static SharedPreferences preferences;
    private static volatile String language = "tr";
    private static JSONObject messages = new JSONObject();
    static void initialize(Context context) {
        preferences = context.getApplicationContext().getSharedPreferences("omnigo-language", Context.MODE_PRIVATE);
        String saved = preferences.getString("language", "tr");
        language = valid(saved) ? saved : "tr";
        try (InputStream input = context.getAssets().open("native-translations.json"); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096]; int length;
            while ((length = input.read(buffer)) != -1) output.write(buffer, 0, length);
            messages = new JSONObject(output.toString(StandardCharsets.UTF_8.name()));
        } catch (Exception ignored) { /* Turkish remains available offline. */ }
    }
    static boolean valid(String value) { return "tr".equals(value) || "en".equals(value) || "de".equals(value); }
    static boolean set(String value) {
        if (!valid(value) || preferences == null) return false;
        if (!value.equals(language)) { preferences.edit().putString("language", value).apply(); language = value; }
        return true;
    }
    static String t(String source, String... values) {
        if (source == null) return null;
        JSONObject entry = messages.optJSONObject(source);
        String result = "tr".equals(language) || entry == null ? source : entry.optString(language, source);
        for (int index = 0; index < values.length; index++) result = result.replace("{" + index + "}", values[index]);
        return result;
    }
}
