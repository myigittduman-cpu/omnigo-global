package com.omnigoglobal.client;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;
public final class LoginCipherTest {
    public static void main(String[] args) throws Exception {
        KeyGenerator generator=KeyGenerator.getInstance("AES"); generator.init(256);
        SecretKey key=generator.generateKey(); byte[] plaintext="yalnız-yerel-test-şifresi".getBytes(StandardCharsets.UTF_8);
        byte[] first=LoginCipher.encrypt(key,plaintext),second=LoginCipher.encrypt(key,plaintext);
        if(Arrays.equals(first,second)||new String(first,StandardCharsets.UTF_8).contains("test-şifresi"))throw new AssertionError("Kayıt şifrelenmeli ve her IV farklı olmalı.");
        if(!Arrays.equals(plaintext,LoginCipher.decrypt(key,first)))throw new AssertionError("Kayıt geri okunmalı.");
        first[first.length-1]^=1;
        try{LoginCipher.decrypt(key,first);throw new AssertionError("Değiştirilmiş kayıt kabul edildi.");}catch(java.security.GeneralSecurityException expected){}
        try{LoginCipher.decrypt(generator.generateKey(),second);throw new AssertionError("Başka anahtar kabul edildi.");}catch(java.security.GeneralSecurityException expected){}
        System.out.println("Giriş kasası: AES-GCM, farklı IV, değiştirilmiş kayıt ve yanlış anahtar kontrolleri geçti.");
    }
}
