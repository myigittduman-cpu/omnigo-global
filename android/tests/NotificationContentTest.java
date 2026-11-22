package com.omnigoglobal.client;
public final class NotificationContentTest {
    public static void main(String[] args) {
        String detail="Vizyona Beyaz Eşya; Hepsiburada’dan yeni bir siparişiniz var.";
        check(NotificationContent.body(detail,"eski metin").equals(detail));
        check(NotificationContent.body(null,detail).equals(detail));
        check(NotificationContent.body(" \n ",detail).equals(detail));
        check(NotificationContent.body(null,null).contains("Yeni bir bildiriminiz"));
        check(NotificationContent.body("Firma\n\r;\tSipariş",null).equals("Firma ; Sipariş"));
        String longText=new String(new char[499]).replace('\0','İ')+"🌍🌍";
        String shortened=NotificationContent.body(longText,null);
        check(shortened.codePointCount(0,shortened.length())==500&&shortened.endsWith("🌍"));
        System.out.println("Android bildirim ayrıntısı, eski yük uyumu ve Türkçe/Unicode kontrolleri geçti.");
    }
    private static void check(boolean value){if(!value)throw new AssertionError();}
}
