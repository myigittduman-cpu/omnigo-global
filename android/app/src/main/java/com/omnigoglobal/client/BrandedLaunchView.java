package com.omnigoglobal.client;

import android.animation.ValueAnimator;
import android.content.Context;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.SystemClock;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import java.util.function.BooleanSupplier;

/** One native welcome while the portal prepares its first usable frame underneath. */
final class BrandedLaunchView extends FrameLayout {
    private final LaunchWindow window;
    private final BooleanSupplier ready;
    private final Runnable completion;
    private final LinearLayout card;
    private final WelcomeLogoView logo;
    private final WelcomeAmbienceView ambience;
    private final WelcomeCardSurface surface;
    private final View fill;
    private final boolean motion = ValueAnimator.areAnimatorsEnabled();
    private boolean active = true;
    private final Runnable finish = this::completeWhenReady;
    private final Runnable frame = new Runnable() {
        @Override public void run() {
            if (!active) return;
            long now = SystemClock.elapsedRealtime();
            fill.setScaleX(window.progress(now));
            if (motion) {
                long elapsed=now-window.deadline+LaunchWindow.DURATION_MS;
                logo.setPhase(elapsed%2000/2000f); ambience.setPhase(elapsed%16000/16000f); surface.setPhase(elapsed%12000/12000f);
            }
            postOnAnimation(this);
        }
    };
    BrandedLaunchView(Context context, LaunchWindow window, BooleanSupplier ready, Runnable completion) {
        super(context); this.window = window; this.ready = ready; this.completion = completion;
        setClickable(true); setFocusable(true);
        setBackground(new GradientDrawable(GradientDrawable.Orientation.TL_BR,
            new int[]{Color.rgb(16,28,44), Color.rgb(25,40,58), Color.rgb(16,28,44)}));
        if (android.os.Build.VERSION.SDK_INT >= 28) setAccessibilityPaneTitle(NativeLanguage.t("Omnigo Global açılış ekranı"));
        ambience=new WelcomeAmbienceView(context); addView(ambience,new FrameLayout.LayoutParams(-1,-1));
        card = new LinearLayout(context); card.setOrientation(LinearLayout.VERTICAL);
        card.setGravity(Gravity.CENTER); card.setPadding(dp(28),dp(26),dp(28),dp(26));
        surface=new WelcomeCardSurface(context); card.setBackground(surface);
        logo = new WelcomeLogoView(context);
        card.addView(logo,new LinearLayout.LayoutParams(dp(252),dp(142)));
        TextView role = text(NativeLanguage.t(PortalConfiguration.HOST.startsWith("yonetim.") ? "Yönetici Portalı" : "İş Ortağım"),14,0xFFC9D1DD);
        addLabel(role,13,18);
        TextView heading = text(NativeLanguage.t("Hoş geldiniz"),21,0xFFE5EAF1);
        heading.setTypeface(Typeface.create("sans-serif-medium",Typeface.NORMAL)); addLabel(heading,0,11);
        TextView slogan = text(NativeLanguage.t("Ticaretinize değer,\noperasyonunuza güç."),14,0xFFB6C1D1);
        slogan.setLineSpacing(dp(3),1f); addLabel(slogan,0,0);
        FrameLayout track = new FrameLayout(context); track.setBackgroundColor(0x2BAC9365);
        fill = new View(context); fill.setBackground(new GradientDrawable(GradientDrawable.Orientation.LEFT_RIGHT,
            new int[]{0xFF947B49,0xFFDEC382})); fill.setPivotX(0); fill.setScaleX(0);
        track.addView(fill,new FrameLayout.LayoutParams(-1,-1));
        LinearLayout.LayoutParams bar = new LinearLayout.LayoutParams(dp(144),dp(2)); bar.topMargin=dp(23); card.addView(track,bar);
        addView(card,new FrameLayout.LayoutParams(dp(380),dp(380),Gravity.CENTER));
    }
    @Override protected void onSizeChanged(int width,int height,int oldWidth,int oldHeight) {
        super.onSizeChanged(width,height,oldWidth,oldHeight);
        float scale=Math.min(1f,Math.max(1,Math.min(width,height)-dp(32))/(float)dp(380));
        card.setScaleX(scale); card.setScaleY(scale);
    }
    void start() { postDelayed(finish,window.remaining(SystemClock.elapsedRealtime())); resume(); }
    boolean isActive() { return active; }
    void pause() { removeCallbacks(frame); }
    void resume() { removeCallbacks(frame); if(active) { postOnAnimation(frame); completeWhenReady(); } }
    private void completeWhenReady() {
        if (!active) return;
        removeCallbacks(finish);
        long now=SystemClock.elapsedRealtime(),remaining=window.remaining(now);
        if (remaining>0) { postDelayed(finish,remaining); return; }
        // Avoid an unstyled WebView flash; an unavailable network still gets a bounded retry screen.
        if (!ready.getAsBoolean() && now<window.deadline+7000) { postDelayed(finish,80); return; }
        fill.setScaleX(1); active=false; dispose();
        if (getParent() instanceof ViewGroup) ((ViewGroup)getParent()).removeView(this);
        completion.run();
    }
    void dispose() { active=false; removeCallbacks(frame); removeCallbacks(finish); }
    private void addLabel(TextView label,int top,int bottom) {
        LinearLayout.LayoutParams params=new LinearLayout.LayoutParams(-1,-2); params.topMargin=dp(top); params.bottomMargin=dp(bottom); card.addView(label,params);
    }
    private int dp(int n) { return Math.round(n*getResources().getDisplayMetrics().density); }
    private TextView text(String value,int size,int color) {
        TextView label=new TextView(getContext()); label.setText(value); label.setTextSize(size);
        label.setTextColor(color); label.setGravity(Gravity.CENTER); return label;
    }
}
