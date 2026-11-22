package com.omnigoglobal.client;

final class PushDestination {
    static String path(String value) {
        return path(value, false);
    }

    static String path(String value, boolean admin) {
        if(value == null || value.length() > 180) return "/";
        if(value.matches("/\\?view=overview&orderCompany=[1-9][0-9]{0,9}&noticeType=operation&noticeId=[1-9][0-9]{0,9}")) return value;
        if(admin && value.matches("/\\?view=inventory&orderCompany=[1-9][0-9]{0,9}&noticeType=operation&noticeId=[1-9][0-9]{0,9}")) return value;
        if(value.matches("/\\?chatThread=[1-9][0-9]{0,14}")) return value;
        return value.matches("/\\?view=(orders|shipments|deliveries|connections|support|payments|returns)(&orderCompany=[1-9][0-9]{0,9})?(&noticeType=(order|shipment|operation|support|user)&noticeId=[1-9][0-9]{0,9})?") ? value : "/";
    }
}
