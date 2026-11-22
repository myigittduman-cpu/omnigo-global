package com.omnigoglobal.client;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.PorterDuff;
import android.graphics.PorterDuffXfermode;
import android.graphics.Shader;
import android.widget.ImageView;

/** Original lockup, with a two-second sheen confined to the brand lettering. */
final class WelcomeLogoView extends ImageView {
    private final Paint shine = new Paint(Paint.ANTI_ALIAS_FLAG);
    private float phase;
    WelcomeLogoView(Context context) {
        super(context); setImageResource(R.drawable.brand_logo); setScaleType(ScaleType.FIT_CENTER);
        setContentDescription("Omnigo Global · E-Ticaret Danışmanlığı");
        shine.setXfermode(new PorterDuffXfermode(PorterDuff.Mode.SRC_ATOP));
    }
    void setPhase(float value) { phase=value; invalidate(); }
    @Override protected void onDraw(Canvas canvas) {
        int saved=canvas.saveLayer(0,0,getWidth(),getHeight(),null);
        super.onDraw(canvas);
        if (phase>.28f && phase<.9f) {
            float center=(-.3f+(phase-.28f)/.62f*1.6f)*getWidth(),radius=getWidth()*.13f;
            shine.setShader(new LinearGradient(center-radius,0,center+radius,0,
                new int[]{0x00FFF1C9,0x99FFF8D8,0x00FFF1C9},null,Shader.TileMode.CLAMP));
            canvas.clipRect(0,getHeight()*.72f,getWidth(),getHeight()*.89f);
            canvas.drawRect(0,0,getWidth(),getHeight(),shine);
        }
        canvas.restoreToCount(saved);
    }
}
