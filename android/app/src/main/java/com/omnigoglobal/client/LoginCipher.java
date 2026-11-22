package com.omnigoglobal.client;

import java.nio.ByteBuffer;
import java.security.GeneralSecurityException;
import javax.crypto.Cipher;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

final class LoginCipher {
    private static final int IV_BYTES = 12;
    static byte[] encrypt(SecretKey key, byte[] plaintext) throws GeneralSecurityException {
        if (plaintext.length == 0 || plaintext.length > 4096) throw new GeneralSecurityException("Geçersiz kayıt.");
        Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
        cipher.init(Cipher.ENCRYPT_MODE, key);
        byte[] ciphertext = cipher.doFinal(plaintext);
        return ByteBuffer.allocate(IV_BYTES + ciphertext.length).put(cipher.getIV()).put(ciphertext).array();
    }
    static byte[] decrypt(SecretKey key, byte[] record) throws GeneralSecurityException {
        if (record.length < IV_BYTES + 16 || record.length > 8192) throw new GeneralSecurityException("Geçersiz kayıt.");
        Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
        cipher.init(Cipher.DECRYPT_MODE, key, new GCMParameterSpec(128, record, 0, IV_BYTES));
        return cipher.doFinal(record, IV_BYTES, record.length - IV_BYTES);
    }
}
