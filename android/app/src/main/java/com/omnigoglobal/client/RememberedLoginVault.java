package com.omnigoglobal.client;

import android.content.Context;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.AtomicFile;
import java.io.File;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.util.Locale;
import java.util.concurrent.ArrayBlockingQueue;
import java.util.concurrent.ThreadPoolExecutor;
import java.util.concurrent.TimeUnit;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import org.json.JSONObject;

/** App-private, backup-excluded encrypted storage. The key never leaves Android Keystore. */
final class RememberedLoginVault {
    interface Callback { void complete(String value, String error); }
    private static final String KEY_ALIAS = "omnigo.remembered-login.v1";
    private final AtomicFile record;
    private final ThreadPoolExecutor queue = new ThreadPoolExecutor(1, 1, 30, TimeUnit.SECONDS, new ArrayBlockingQueue<>(16));
    RememberedLoginVault(Context context) {
        record = new AtomicFile(new File(context.getApplicationContext().getNoBackupFilesDir(), "remembered-login.encrypted"));
        queue.allowCoreThreadTimeOut(true);
    }
    void execute(String command, JSONObject data, Callback callback) {
        final String payload = data == null ? "{}" : data.toString();
        if (payload.length() > 4096) { callback.complete(null, "Giriş kaydı geçersiz."); return; }
        try {
            queue.execute(() -> {
                try { callback.complete(handle(command, new JSONObject(payload)), null); }
                catch (Exception error) { callback.complete(null, "Güvenli giriş kasası kullanılamadı. Bilgilerinizi elle girebilirsiniz."); }
            });
        } catch (java.util.concurrent.RejectedExecutionException error) {
            callback.complete(null, "Giriş kasası meşgul. Yeniden deneyin.");
        }
    }
    private SecretKey key() throws Exception {
        KeyStore keys = KeyStore.getInstance("AndroidKeyStore"); keys.load(null);
        if (keys.containsAlias(KEY_ALIAS)) return (SecretKey) keys.getKey(KEY_ALIAS, null);
        KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
        generator.init(new KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
            .setKeySize(256).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());
        return generator.generateKey();
    }
    private static String identity(JSONObject data) throws Exception {
        String value = data.getString("identity").trim();
        if (value.isEmpty() || value.length() > 200 || value.matches("(?s).*\\p{Cntrl}.*")) throw new IllegalArgumentException();
        return value;
    }
    private String handle(String command, JSONObject data) throws Exception {
        if ("credentials_clear".equals(command)) { record.delete(); return "true"; }
        String identity = identity(data);
        if ("credentials_store".equals(command)) {
            String password = data.getString("password");
            if (password.isEmpty() || password.length() > 128) throw new IllegalArgumentException();
            byte[] plaintext = new JSONObject().put("identity", identity).put("password", password).toString().getBytes(StandardCharsets.UTF_8);
            byte[] encrypted = LoginCipher.encrypt(key(), plaintext); java.util.Arrays.fill(plaintext, (byte)0);
            FileOutputStream stream = record.startWrite();
            try { stream.write(encrypted); record.finishWrite(stream); }
            catch (Exception error) { record.failWrite(stream); throw error; }
            return "true";
        }
        if (!"credentials_get".equals(command)) throw new IllegalArgumentException();
        if (!record.getBaseFile().exists()) return "null";
        if (record.getBaseFile().length() > 8192) throw new IllegalArgumentException();
        byte[] plaintext = LoginCipher.decrypt(key(), record.readFully());
        JSONObject saved;
        try { saved = new JSONObject(new String(plaintext, StandardCharsets.UTF_8)); }
        finally { java.util.Arrays.fill(plaintext, (byte)0); }
        if (!identity.toLowerCase(Locale.ROOT).equals(saved.getString("identity").toLowerCase(Locale.ROOT))) return "null";
        return new JSONObject().put("type", "password").put("id", saved.getString("identity")).put("password", saved.getString("password")).toString();
    }
}
