package com.omnigoglobal.client;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.res.Configuration;
import android.graphics.Color;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;

public final class MainActivity extends Activity {
    private static final int NAVY = Color.rgb(16,28,44);
    private WebView webView;
    private LinearLayout loading;
    private TextView status;
    private ProgressBar progress;
    private Button retry;
    private ReportSaver saver;
    private PortalBridge bridge;
    private PortalFileChooser fileChooser;
    private MobilePushHandler push;
    private BrandedLaunchView launch;
    private LaunchWindow launchWindow;
    private ImmersiveWindow immersiveWindow;
    private boolean portalReady;
    private boolean portalFailed;
    private boolean backPending;
    private AlertDialog exitDialog;

    @Override public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (Build.VERSION.SDK_INT >= 31) getSplashScreen().setOnExitAnimationListener(view->view.remove());
        NativeLanguage.initialize(this);
        FrameLayout root = new FrameLayout(this); root.setBackgroundColor(NAVY);
        setContentView(root);
        immersiveWindow = new ImmersiveWindow(getWindow(), root);
        try { webView = new WebView(this); }
        catch (Exception error) { new AlertDialog.Builder(this).setMessage(NativeLanguage.t("Android System WebView uygulamasını güncelleyip tekrar deneyin.")).setPositiveButton(NativeLanguage.t("Tamam"), (d,w)->finish()).show(); return; }
        webView.setBackgroundColor(NAVY);
        root.addView(webView, new FrameLayout.LayoutParams(-1,-1));
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true); settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false); settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setJavaScriptCanOpenWindowsAutomatically(false); settings.setSupportMultipleWindows(false);
        settings.setMediaPlaybackRequiresUserGesture(true); settings.setSafeBrowsingEnabled(true);
        settings.setUserAgentString(settings.getUserAgentString() + " OmnigoAndroid/" + PortalConfiguration.VERSION + (PortalConfiguration.ALLOW_DIRECT_UPDATES?" DirectAPK":" Store"));
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView,false);
        saver = new ReportSaver(this);
        push=MobilePushHandler.create(this);
        try { bridge = new PortalBridge(webView, saver, asset("portal-bridge.js"),push); }
        catch (Exception error) { Toast.makeText(this,NativeLanguage.t("Uygulama dosyaları okunamadı."),Toast.LENGTH_LONG).show(); finish(); return; }
        webView.setWebViewClient(new PortalClient(this, bridge));
        fileChooser = new PortalFileChooser(this);
        webView.setWebChromeClient(fileChooser);
        webView.setDownloadListener((url, agent, disposition, mime, length) -> {
            if (PortalOrigin.allows(webView.getUrl()) && UpdateChecker.open(this, url)) return;
            Toast.makeText(this,NativeLanguage.t("Bu dosya burada indirilemiyor. Raporlar için PDF veya Excel düğmesini kullanın."),Toast.LENGTH_LONG).show();
        });
        buildLoading(root);
        launchWindow = new LaunchWindow(android.os.SystemClock.elapsedRealtime(),savedInstanceState==null?0:savedInstanceState.getLong("launchDeadline",0));
        launch = new BrandedLaunchView(this,launchWindow,()->portalReady||portalFailed,()->{
            if(portalReady&&bridge!=null)bridge.connect();
            else if(!portalFailed)showError("Bağlantı beklenenden uzun sürdü. İnternet bağlantınızı kontrol edip yeniden deneyin.");
        });
        root.addView(launch,new FrameLayout.LayoutParams(-1,-1));
        launch.start();
        if (Build.VERSION.SDK_INT >= 33) getOnBackInvokedDispatcher().registerOnBackInvokedCallback(android.window.OnBackInvokedDispatcher.PRIORITY_DEFAULT, this::goBack);
        webView.loadUrl(PortalOrigin.ORIGIN+PortalOrigin.notificationPath(getIntent().getStringExtra("url")));
        if (PortalConfiguration.ALLOW_DIRECT_UPDATES) UpdateChecker.check(this);
    }
    private void buildLoading(FrameLayout root) {
        loading = new LinearLayout(this); loading.setOrientation(LinearLayout.VERTICAL); loading.setGravity(Gravity.CENTER); loading.setPadding(dp(28),dp(28),dp(28),dp(28)); loading.setBackgroundColor(NAVY);
        ImageView logo = new ImageView(this); logo.setImageResource(R.drawable.brand_logo); logo.setScaleType(ImageView.ScaleType.FIT_CENTER); logo.setContentDescription(NativeLanguage.t("Omnigo Global"));
        loading.addView(logo,new LinearLayout.LayoutParams(dp(252),dp(142)));
        status = new TextView(this); status.setTextColor(Color.WHITE); status.setTextSize(17); status.setGravity(Gravity.CENTER); status.setPadding(0,dp(18),0,dp(20));
        loading.addView(status,new LinearLayout.LayoutParams(-1,-2));
        progress = new ProgressBar(this); loading.addView(progress,new LinearLayout.LayoutParams(dp(32),dp(32)));
        retry = new Button(this); retry.setText(NativeLanguage.t("Yeniden dene")); retry.setOnClickListener(view->webView.loadUrl(PortalOrigin.HOME)); loading.addView(retry);
        root.addView(loading,new FrameLayout.LayoutParams(-1,-1)); showLoading();
    }
    void showLoading() { portalReady=false;portalFailed=false;if(loading==null)return; status.setText(NativeLanguage.t("{0} açılıyor…", PortalConfiguration.TITLE)); progress.setVisibility(View.VISIBLE); retry.setVisibility(View.GONE); loading.setVisibility(View.VISIBLE); }
    void showPortal() { portalReady=true;portalFailed=false;loading.setVisibility(View.GONE);if(launch==null||!launch.isActive())bridge.connect(); }
    void showError(String message) { portalReady=false;portalFailed=true;status.setText(NativeLanguage.t(message)); retry.setText(NativeLanguage.t("Yeniden dene")); progress.setVisibility(View.GONE); retry.setVisibility(View.VISIBLE); loading.setVisibility(View.VISIBLE); }
    private int dp(int value) { return Math.round(value*getResources().getDisplayMetrics().density); }
    private String asset(String name) throws Exception {
        try(InputStream input=getAssets().open(name);ByteArrayOutputStream bytes=new ByteArrayOutputStream()) {
            byte[] buffer=new byte[4096];int length;while((length=input.read(buffer))!=-1)bytes.write(buffer,0,length);
            return bytes.toString(StandardCharsets.UTF_8.name());
        }
    }
    private void goBack() {
        if(backPending || (exitDialog!=null&&exitDialog.isShowing()))return;
        if(webView==null || !PortalOrigin.allows(webView.getUrl())) { confirmExit(); return; }
        backPending=true;
        webView.evaluateJavascript("typeof window.omnigoHandleBack==='function'?window.omnigoHandleBack():'history'", result->{
            backPending=false;
            if(isFinishing()||isDestroyed())return;
            if("\"handled\"".equals(result))return;
            if("\"history\"".equals(result)&&webView.canGoBack())webView.goBack();
            else confirmExit();
        });
    }
    private void confirmExit() {
        if(isFinishing()||isDestroyed()||(exitDialog!=null&&exitDialog.isShowing()))return;
        exitDialog=new AlertDialog.Builder(this).setTitle(NativeLanguage.t("Çıkmak istiyor musunuz?"))
            .setMessage(NativeLanguage.t("Uygulama kapatılacak."))
            .setPositiveButton(NativeLanguage.t("Evet, çık"), (dialog,which)->finish())
            .setNegativeButton(NativeLanguage.t("Vazgeç"), (dialog,which)->dialog.dismiss()).create();
        exitDialog.show();
    }
    @Override public void onBackPressed() { goBack(); }
    @Override protected void onActivityResult(int requestCode,int resultCode,Intent data) { super.onActivityResult(requestCode,resultCode,data);if(requestCode==ReportSaver.REQUEST_SAVE&&saver!=null)saver.onResult(resultCode,data);if(requestCode==PortalFileChooser.REQUEST_OPEN&&fileChooser!=null)fileChooser.onResult(resultCode,data); }
    @Override public void onConfigurationChanged(Configuration configuration) { super.onConfigurationChanged(configuration);if(immersiveWindow!=null)immersiveWindow.restore(); }
    @Override public void onWindowFocusChanged(boolean hasFocus) { super.onWindowFocusChanged(hasFocus);if(hasFocus&&immersiveWindow!=null)immersiveWindow.restore(); }
    @Override public void onRequestPermissionsResult(int requestCode,String[] permissions,int[] results) { super.onRequestPermissionsResult(requestCode,permissions,results); if(push!=null)push.permissionResult(requestCode); }
    @Override protected void onNewIntent(Intent intent) { super.onNewIntent(intent);setIntent(intent);if(webView!=null)webView.loadUrl(PortalOrigin.ORIGIN+PortalOrigin.notificationPath(intent.getStringExtra("url"))); }
    @Override protected void onSaveInstanceState(Bundle state) { super.onSaveInstanceState(state);if(launchWindow!=null)state.putLong("launchDeadline",launchWindow.deadline); }
    @Override protected void onPause() { if(launch!=null)launch.pause();if(webView!=null)webView.onPause();CookieManager.getInstance().flush();super.onPause(); }
    @Override protected void onResume() { super.onResume();if(immersiveWindow!=null)immersiveWindow.restore();if(launch!=null)launch.resume();if(webView!=null)webView.onResume(); }
    @Override protected void onDestroy() { if(immersiveWindow!=null)immersiveWindow.dispose();if(launch!=null)launch.dispose();if(bridge!=null)bridge.close();if(saver!=null)saver.close();if(fileChooser!=null)fileChooser.close();if(webView!=null)webView.destroy();super.onDestroy(); }
}
