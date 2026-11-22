package com.omnigoglobal.client;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebView;
import android.widget.Toast;

/** Opens the system picker for a logo without granting broad storage access. */
final class PortalFileChooser extends WebChromeClient {
    static final int REQUEST_OPEN = 4022;
    private final Activity activity;
    private ValueCallback<Uri[]> pending;
    private WebView requestingView;

    PortalFileChooser(Activity activity) { this.activity = activity; }

    @Override public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
        close();
        if (!PortalOrigin.allows(view.getUrl())) { callback.onReceiveValue(null); return true; }
        pending = callback; requestingView = view;
        Intent intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        intent.addCategory(Intent.CATEGORY_OPENABLE);
        intent.setType("image/*");
        intent.putExtra(Intent.EXTRA_MIME_TYPES, new String[]{"image/png", "image/jpeg", "image/webp"});
        intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, false);
        try { activity.startActivityForResult(intent, REQUEST_OPEN); }
        catch (Exception error) { close(); Toast.makeText(activity,NativeLanguage.t("Dosya seçici açılamadı."),Toast.LENGTH_LONG).show(); }
        return true;
    }

    void onResult(int resultCode, Intent data) {
        if (pending == null) return;
        Uri selected = resultCode == Activity.RESULT_OK && data != null ? data.getData() : null;
        boolean accepted = selected != null && "content".equals(selected.getScheme())
            && requestingView != null && PortalOrigin.allows(requestingView.getUrl());
        ValueCallback<Uri[]> callback = pending; pending = null; requestingView = null;
        callback.onReceiveValue(accepted ? new Uri[]{selected} : null);
    }

    void close() { if (pending != null) { ValueCallback<Uri[]> callback = pending; pending = null; requestingView = null; callback.onReceiveValue(null); } }
}
