package com.omnigoglobal.client;

import android.content.Context;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;
import org.json.JSONObject;

final class PushStore {
    private static SecretKey key() throws Exception {
        KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);
        String alias="omnigo-push-binding";
        if(!store.containsAlias(alias)) {
            KeyGenerator generator=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");
            generator.init(new KeyGenParameterSpec.Builder(alias,KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());
            generator.generateKey();
        }
        return (SecretKey)store.getKey(alias,null);
    }
    static synchronized JSONObject read(Context context) {
        try {
            String stored=context.getSharedPreferences("mobile-push",0).getString("binding","");
            if(stored.isEmpty())return new JSONObject();
            String[] parts=stored.split(":",2);Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Base64.decode(parts[0],Base64.NO_WRAP)));
            return new JSONObject(new String(cipher.doFinal(Base64.decode(parts[1],Base64.NO_WRAP)),java.nio.charset.StandardCharsets.UTF_8));
        } catch(Exception error) {return new JSONObject();}
    }
    static synchronized void write(Context context,JSONObject value) throws Exception {
        Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key());
        String encoded=Base64.encodeToString(cipher.getIV(),Base64.NO_WRAP)+":"+Base64.encodeToString(cipher.doFinal(value.toString().getBytes(java.nio.charset.StandardCharsets.UTF_8)),Base64.NO_WRAP);
        if(!context.getSharedPreferences("mobile-push",0).edit().putString("binding",encoded).commit())throw new Exception("Cihaz ayarı kaydedilemedi.");
    }
    static synchronized void patch(Context context,JSONObject values) throws Exception {
        JSONObject current=read(context);java.util.Iterator<String> keys=values.keys();
        while(keys.hasNext()){String key=keys.next();current.put(key,values.get(key));}write(context,current);
    }
}
