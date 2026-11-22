package com.omnigoglobal.client;
public final class PushDestinationTest {
    public static void main(String[] args) {
        String[] allowed={"/","/?view=connections","/?view=shipments&orderCompany=1","/?view=orders&orderCompany=1&noticeType=order&noticeId=15","/?view=orders&orderCompany=1&noticeType=operation&noticeId=16","/?view=support&orderCompany=1&noticeType=user&noticeId=12","/?view=shipments&orderCompany=2&noticeType=shipment&noticeId=45"};
        for(String value:allowed)if(!value.equals(PushDestination.path(value)))throw new AssertionError("İzinli bildirim yolu reddedildi");
        if(!"/?chatThread=42".equals(PushDestination.path("/?chatThread=42")))throw new AssertionError("Sohbet bildirimi doğru konuşmayı açmalı");
        if(!"/".equals(PushDestination.path("/?chatThread=42&redirect=https://evil.test")))throw new AssertionError("Sohbet bildirimi dış hedef açmamalı");
        String[] denied={"https://evil.test/","//evil.test/","/?view=admin","/?view=support&orderCompany=-1","/?view=shipments&noticeType=user&noticeId=0","/?view=support&orderCompany=1&redirect=https://evil.test","/?view=shipments#bad",null};
        for(String value:denied)if(!"/".equals(PushDestination.path(value)))throw new AssertionError("Güvensiz bildirim yolu kabul edildi");
        String inventory="/?view=inventory&orderCompany=9&noticeType=operation&noticeId=31";
        String setup="/?view=overview&orderCompany=9&noticeType=operation&noticeId=32";
        if(!inventory.equals(PushDestination.path(inventory,true)) || !"/".equals(PushDestination.path(inventory,false)))throw new AssertionError("Stok bildirimi yöneticiye ait olmalı");
        for(boolean admin:new boolean[]{false,true}){
            if(!setup.equals(PushDestination.path(setup,admin)))throw new AssertionError("Kurulum bildirimi ilgili mağazada açılmalı");
            for(String invalid:new String[]{"/?view=inventory","/?view=overview",setup+"&redirect=https://example.invalid",setup.replace("operation","user"),setup.replace("=9&","=0&")})
                if(!"/".equals(PushDestination.path(invalid,admin)))throw new AssertionError("Bildirim sınırı aşıldı");
        }
        System.out.println("Android bildirim hedefleri: geçerli yollar ve dış yönlendirme engelleri geçti.");
    }
}
