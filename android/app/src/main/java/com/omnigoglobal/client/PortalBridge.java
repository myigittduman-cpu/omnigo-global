package com.omnigoglobal.client;

import android.net.Uri;
import android.webkit.WebMessage;
import android.webkit.WebMessagePort;
import android.webkit.WebView;
import org.json.JSONObject;

final class PortalBridge {
    private final WebView webView;
    private final ReportSaver saver;
    private final String script;
    private final MobilePushHandler push;
    private final RememberedLoginVault credentials;
    private WebMessagePort channel;
    PortalBridge(WebView webView, ReportSaver saver, String script, MobilePushHandler push) { this.webView = webView; this.saver = saver; this.script = script.replace("__OMNIGO_PUSH_ENABLED__",push==null?"false":"true"); this.push=push; this.credentials=new RememberedLoginVault(webView.getContext()); }
    void connect() {
        if (channel != null) return;
        if (!PortalOrigin.allows(webView.getUrl())) return;
        WebMessagePort[] ports = webView.createWebMessageChannel();
        WebMessagePort current = ports[0]; channel = current;
        String token = java.util.UUID.randomUUID().toString();
        current.setWebMessageCallback(new WebMessagePort.WebMessageCallback() {
            @Override public void onMessage(WebMessagePort port, WebMessage message) {
                if (channel != port || !PortalOrigin.allows(webView.getUrl())) return;
                String data = message.getData();
                if (data == null || data.length() > ReportPayload.MAX_ENCODED + 1024) { close(); return; }
                String requestId = "";
                try {
                    JSONObject request = new JSONObject(data);
                    requestId = request.getString("id");
                    if (!requestId.matches("[0-9]{1,10}")) return;
                    final String id = requestId;
                    String command=request.optString("command");
                    if ("credentials_get".equals(command)||"credentials_store".equals(command)||"credentials_clear".equals(command)) {
                        credentials.execute(command,request.optJSONObject("data"),(value,error)->webView.post(()->reply(port,id,value,error))); return;
                    }
                    if ("set_language".equals(request.optString("command"))) {
                        JSONObject dataObject = request.optJSONObject("data");
                        boolean accepted = dataObject != null && NativeLanguage.set(dataObject.optString("language"));
                        reply(port,id,accepted?"saved":null,accepted?null:"Dil seçimi geçersiz."); return;
                    }
                    if(request.optString("command").startsWith("push_") && push!=null) {
                        push.handle(request.getString("command"),request.optJSONObject("data"),(value,error)->reply(port,id,value,error)); return;
                    }
                    if("share_report".equals(request.getString("command"))){saver.share(request.getString("filename"),request.getString("contents"),(status,error)->reply(port,id,status,error));return;}
                    if(!"save_report".equals(request.getString("command")))return;
                    saver.save(request.getString("filename"), request.getString("contents"), (status, error) -> reply(port, id, status, error));
                } catch (Exception error) { reply(port, requestId, null, "Rapor isteği okunamadı."); }
            }
        });
        // The port is delivered only to the exact HTTPS origin in the top-level frame.
        // No JavaScript interface is exposed to every frame or arbitrary websites.
        webView.evaluateJavascript(script.replace("__OMNIGO_PORT_TOKEN__", token), result -> {
            if (channel != current || !PortalOrigin.allows(webView.getUrl())) return;
            if (JSONObject.quote("omnigo-report-ready:" + token).equals(result))
                webView.postWebMessage(new WebMessage("omnigo-report-port:" + token, new WebMessagePort[]{ports[1]}), Uri.parse(PortalOrigin.ORIGIN));
            else { ports[1].close(); close(); }
        });
    }
    private void reply(WebMessagePort port, String id, String status, String error) {
        if (port != channel || !PortalOrigin.allows(webView.getUrl())) return;
        try {
            JSONObject reply = new JSONObject().put("id", id);
            if (error != null) reply.put("error", NativeLanguage.t(error)); else reply.put("status", status);
            port.postMessage(new WebMessage(reply.toString()));
        } catch (Exception ignored) { /* The page may have been closed while the system dialog was open. */ }
    }
    void close() { if (channel != null) { channel.close(); channel = null; } }
}
