package com.omnigoglobal.client;

import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.net.http.SslError;
import android.webkit.SslErrorHandler;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

final class PortalClient extends WebViewClient {
    private final MainActivity activity;
    private final PortalBridge bridge;
    private boolean failed;
    private int navigation;
    PortalClient(MainActivity activity, PortalBridge bridge) { this.activity = activity; this.bridge = bridge; }
    @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
        String url = request.getUrl().toString();
        if (request.isForMainFrame() && UpdateChecker.open(activity, url)) return true;
        if (PortalOrigin.allows(url)) return false;
        if (request.isForMainFrame() && ("https".equals(request.getUrl().getScheme()) || "mailto".equals(request.getUrl().getScheme()) || "tel".equals(request.getUrl().getScheme()))) {
            try { activity.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); }
            catch (Exception error) { Toast.makeText(activity, NativeLanguage.t("Bu bağlantıyı açabilecek uygulama bulunamadı."), Toast.LENGTH_LONG).show(); }
        }
        return true;
    }
    @Override public void onPageStarted(WebView view, String url, Bitmap favicon) {
        navigation++; failed = false; bridge.close(); activity.showLoading();
    }
    @Override public void onPageFinished(WebView view, String url) {
        if (!failed && PortalOrigin.allows(url)) {
            int current=navigation;
            PortalFirstFrame.await(view,()->!failed&&current==navigation&&url.equals(view.getUrl()),ready->{
                if(ready)activity.showPortal();
                else activity.showError(NativeLanguage.t("Ekran hazırlanamadı. Bağlantınızı kontrol edip yeniden deneyin."));
            });
        }
    }
    @Override public void onPageCommitVisible(WebView view, String url) {
        if (!failed && PortalOrigin.allows(url)) bridge.connect();
    }
    @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        if (request.isForMainFrame()) { failed = true; activity.showError(NativeLanguage.t("Bağlantı kurulamadı. İnternet bağlantınızı kontrol edip yeniden deneyin.")); }
    }
    @Override public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
        if (request.isForMainFrame() && response.getStatusCode() >= 400) { failed = true; activity.showError(NativeLanguage.t("Portal şu anda yanıt veremiyor. Biraz sonra yeniden deneyin.")); }
    }
    @Override public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
        handler.cancel(); failed = true;
        activity.showError(NativeLanguage.t("Güvenli bağlantı doğrulanamadı. Telefonun tarihini ve internet bağlantısını kontrol edin."));
    }
}
