package com.omnigoglobal.client;

final class NotificationContent {
    private NotificationContent() {}
    static String body(String dataBody,String notificationBody) {
        String value=clean(dataBody);
        if(value.isEmpty())value=clean(notificationBody);
        if(value.isEmpty())return "Yeni bir bildiriminiz var. Ayrıntıları uygulamada görüntüleyebilirsiniz.";
        return value.codePointCount(0,value.length())>500?value.substring(0,value.offsetByCodePoints(0,500)):value;
    }
    private static String clean(String value) {
        return value==null?"":value.replaceAll("[\\p{C}\\p{Z}]+"," ").trim();
    }
}
