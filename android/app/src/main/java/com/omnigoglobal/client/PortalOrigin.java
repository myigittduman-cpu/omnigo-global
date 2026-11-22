package com.omnigoglobal.client;

import java.net.URI;

final class PortalOrigin {
    static final String HOME = PortalConfiguration.ORIGIN + "/";
    static final String ORIGIN = PortalConfiguration.ORIGIN;
    static String notificationPath(String value) {
        return PushDestination.path(value, "yonetim.omnigoglobal.com".equals(PortalConfiguration.HOST));
    }
    static boolean allows(String value) {
        if (value == null) return false;
        try {
            URI uri = new URI(value);
            return "https".equals(uri.getScheme()) && PortalConfiguration.HOST.equals(uri.getHost())
                && uri.getRawUserInfo() == null && (uri.getPort() == -1 || uri.getPort() == 443);
        } catch (Exception ignored) { return false; }
    }
    static boolean allowsUpdate(String value) {
        if (PortalConfiguration.ALLOW_DIRECT_UPDATES && allows(value)) {
            try {
                URI uri = new URI(value);
                return uri.getRawQuery() == null && uri.getRawFragment() == null
                    && uri.getRawPath().matches("/downloads/android/" + java.util.regex.Pattern.quote(PortalConfiguration.FILENAME_PREFIX) + "-[0-9]+\\.[0-9]+\\.[0-9]+\\.apk");
            } catch (Exception ignored) { return false; }
        }
        return false;
    }
    private PortalOrigin() {}
}
