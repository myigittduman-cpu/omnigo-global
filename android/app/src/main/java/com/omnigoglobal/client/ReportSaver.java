package com.omnigoglobal.client;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import java.io.OutputStream;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

final class ReportSaver {
    static final int REQUEST_SAVE = 4021;
    interface Completion { void finish(String status, String error); }
    private final Activity activity;
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private ReportPayload pending;
    private Completion completion;
    private final ReportSharer sharer;
    ReportSaver(Activity activity) { this.activity = activity; this.sharer=new ReportSharer(activity); }
    void share(String filename,String contents,Completion callback){sharer.share(filename,contents,callback);}
    void save(String filename, String contents, Completion callback) {
        if (completion != null) { callback.finish(null, "Önce açık kaydetme işlemini tamamlayın."); return; }
        completion = callback;
        worker.execute(() -> {
            try {
                ReportPayload report = ReportPayload.parse(filename, contents);
                activity.runOnUiThread(() -> {
                    if (activity.isFinishing() || activity.isDestroyed()) { complete(null, "Pencere kapandı."); return; }
                    pending = report;
                    Intent intent = new Intent(Intent.ACTION_CREATE_DOCUMENT).addCategory(Intent.CATEGORY_OPENABLE)
                        .setType(report.mime).putExtra(Intent.EXTRA_TITLE, report.filename);
                    try { activity.startActivityForResult(intent, REQUEST_SAVE); }
                    catch (Exception error) { complete(null, "Telefonun dosya kaydetme ekranı açılamadı."); }
                });
            } catch (IllegalArgumentException error) { activity.runOnUiThread(() -> complete(null, error.getMessage())); }
        });
    }
    void onResult(int resultCode, Intent intent) {
        if (completion == null) return;
        if (resultCode != Activity.RESULT_OK || intent == null || intent.getData() == null || pending == null) { complete("cancelled", null); return; }
        Uri destination = intent.getData();
        ReportPayload report = pending;
        worker.execute(() -> {
            String error = null;
            try (OutputStream out = activity.getContentResolver().openOutputStream(destination, "w")) {
                if (out == null) throw new java.io.IOException();
                out.write(report.bytes); out.flush();
            } catch (Exception failure) { error = "Dosya kaydedilemedi. Yazılabilir bir klasör seçip yeniden deneyin."; }
            String resultError = error;
            activity.runOnUiThread(() -> complete(resultError == null ? "saved" : null, resultError));
        });
    }
    private void complete(String status, String error) {
        Completion callback = completion; completion = null; pending = null;
        if (callback != null) callback.finish(status, error);
    }
    void close() { complete("cancelled", null); worker.shutdown(); sharer.close(); }
}
