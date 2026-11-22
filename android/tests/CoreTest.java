package com.omnigoglobal.client;
import java.nio.charset.StandardCharsets;
import java.util.Base64;

public final class CoreTest {
    private static int assertions;
    private static void check(boolean value) { assertions++;if(!value)throw new AssertionError(); }
    private static String encoded(String value) {return Base64.getEncoder().encodeToString(value.getBytes(StandardCharsets.UTF_8));}
    private static void rejected(String filename,String data) {boolean rejected=false;try{ReportPayload.parse(filename,data);}catch(IllegalArgumentException expected){rejected=true;}check(rejected);}
    public static void main(String[] args) {
        String host = PortalConfiguration.HOST;
        String otherHost = host.equals("portal.omnigoglobal.com") ? "yonetim.omnigoglobal.com" : "portal.omnigoglobal.com";
        check(PortalOrigin.allows("https://"+host+"/"));
        check(PortalOrigin.allows("https://"+host+":443/?page=rapor"));
        for(String url:new String[]{"http://"+host+"/","https://"+host+".evil.test/","https://"+otherHost+"/","https://"+host+":444/","https://evil.test@"+host+"/","file:///etc/passwd","javascript:alert(1)",null})check(!PortalOrigin.allows(url));
        String update = "https://"+host+"/downloads/android/"+PortalConfiguration.FILENAME_PREFIX+"-1.0.12.apk";
        check(PortalOrigin.allowsUpdate(update) == PortalConfiguration.ALLOW_DIRECT_UPDATES);
        for(String url:new String[]{update.replace("https:","http:"),update.replace(host,otherHost),update.replace(host,host+".evil.test"),update+"?redirect=evil",update+"#fragment",update.replace(".apk",".pdf"),update.replace("1.0.12","1..12"),update.replace("-1.0.12","%2d1.0.12"),update.replace(host,"user@"+host),null})check(!PortalOrigin.allowsUpdate(url));
        check(ReportPayload.parse("Omnigo-Mağaza.pdf",encoded("%PDF-1.7\n")).mime.equals("application/pdf"));
        check(ReportPayload.parse("Eskişehir Teslimat Evrakları.pdf",encoded("%PDF-1.7\n")).mime.equals("application/pdf"));
        rejected(".hidden.pdf",encoded("%PDF-1.7\n"));
        rejected("report.pdf ",encoded("%PDF-1.7\n"));
        rejected("a\nb\nc.pdf",encoded("%PDF-1.7\n"));
        check(ReportPayload.parse("Omnigo-Fatura-1.jpeg",Base64.getEncoder().encodeToString(new byte[]{(byte)255,(byte)216,(byte)255,0})).mime.equals("image/jpeg"));
        rejected("Omnigo-Fatura-1.jpeg",encoded("MZprogram"));
        check(ReportPayload.parse("Omnigo-Mağaza.xlsx",encoded("PK\3\4test")).mime.contains("spreadsheetml"));
        check(new String(ReportPayload.parse("Omnigo-Mağaza.txt",encoded("Türkçe rapor: ₺10,00")).bytes,StandardCharsets.UTF_8).equals("Türkçe rapor: ₺10,00"));
        for(String name:new String[]{"../Omnigo-test.pdf","Omnigo-evil.exe","Omnigo-a/b.pdf","Omnigo-x:stream.pdf","Omnigo-\0.pdf","Omnigo-\n.pdf"})rejected(name,encoded("%PDF-1.7\n"));
        rejected("Omnigo-test.pdf",encoded("MZprogram"));rejected("Omnigo-test.xlsx",encoded("not a spreadsheet"));rejected("Omnigo-test.txt",encoded("bad\0content"));
        rejected("Omnigo-test.pdf","not base64");rejected("Omnigo-test.pdf","");
        rejected("Omnigo-test.pdf",new String(new char[ReportPayload.MAX_ENCODED+1]).replace('\0','A'));
        System.out.println(assertions+" Android güvenlik ve rapor doğrulama kontrolü geçti.");
    }
}
