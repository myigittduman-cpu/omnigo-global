package com.omnigoglobal.client;

import java.nio.charset.StandardCharsets;
import java.util.Base64;

final class ReportPayload {
    static final int MAX_BYTES = 32 * 1024 * 1024;
    static final int MAX_ENCODED = ((MAX_BYTES + 2) / 3) * 4;
    final String filename;
    final String mime;
    final byte[] bytes;
    private ReportPayload(String filename, String mime, byte[] bytes) {
        this.filename = filename; this.mime = mime; this.bytes = bytes;
    }
    static ReportPayload parse(String filename, String contents) {
        if (filename == null || filename.isEmpty() || filename.startsWith(".") || !filename.equals(filename.trim()) || filename.length() > 160 || java.util.regex.Pattern.compile("[\\x00-\\x1F\\x7F<>:\"/\\\\|?*]").matcher(filename).find())
            throw new IllegalArgumentException("Rapor dosyasının adı geçersiz.");
        if (contents == null || contents.length() > MAX_ENCODED)
            throw new IllegalArgumentException("Rapor çok büyük. Daha kısa bir tarih aralığı seçin.");
        byte[] bytes;
        try { bytes = Base64.getDecoder().decode(contents); }
        catch (IllegalArgumentException error) { throw new IllegalArgumentException("Rapor dosyası okunamadı."); }
        if (bytes.length == 0 || bytes.length > MAX_BYTES) throw new IllegalArgumentException("Rapor boyutu uygun değil.");
        String mime;
        if (filename.endsWith(".pdf") && startsWith(bytes, new byte[]{37,80,68,70,45})) mime = "application/pdf";
        else if (filename.endsWith(".xlsx") && startsWith(bytes, new byte[]{80,75,3,4})) mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
        else if ((filename.endsWith(".jpeg") || filename.endsWith(".jpg")) && startsWith(bytes, new byte[]{(byte)255,(byte)216,(byte)255})) mime = "image/jpeg";
        else if (filename.endsWith(".txt")) {
            String text = new String(bytes, StandardCharsets.UTF_8);
            if (text.indexOf('\0') >= 0 || text.indexOf('\uFFFD') >= 0) throw new IllegalArgumentException("Metin raporu geçersiz.");
            mime = "text/plain";
        } else throw new IllegalArgumentException("Yalnızca PDF, Excel, JPEG ve metin belgeleri kaydedilebilir.");
        return new ReportPayload(filename, mime, bytes);
    }
    private static boolean startsWith(byte[] bytes, byte[] prefix) {
        if (bytes.length < prefix.length) return false;
        for (int i = 0; i < prefix.length; i++) if (bytes[i] != prefix[i]) return false;
        return true;
    }
}
