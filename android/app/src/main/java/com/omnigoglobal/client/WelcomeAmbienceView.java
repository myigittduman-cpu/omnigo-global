package com.omnigoglobal.client;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.RadialGradient;
import android.graphics.Shader;
import android.view.View;

/** Low-contrast edge lighting only; card and text geometry never animate. */
final class WelcomeAmbienceView extends View {
    private final Paint paint=new Paint(Paint.ANTI_ALIAS_FLAG);
    private float phase;
    WelcomeAmbienceView(Context context) { super(context); setImportantForAccessibility(IMPORTANT_FOR_ACCESSIBILITY_NO); }
    void setPhase(float value) { phase=value; invalidate(); }
    @Override protected void onDraw(Canvas canvas) {
        float width=getWidth(),height=getHeight(),radius=Math.max(width,height)*.66f;
        double angle=phase*Math.PI*2;
        float shift=(float)Math.sin(angle)*width*.06f;
        glow(canvas,width*.03f+shift,height*.18f,radius,0x24C7AA72);
        glow(canvas,width*.98f-shift,height*.82f,radius,0x325477A1);
        paint.setShader(null); paint.setStyle(Paint.Style.STROKE);
        paint.setStrokeWidth(getResources().getDisplayMetrics().density*.6f); paint.setColor(0x14C7AA72);
        canvas.drawCircle(-width*.38f+shift,height*.2f,width*.9f,paint);
        paint.setColor(0x12678CB7);canvas.drawCircle(width*1.4f-shift,height*.78f,width*.9f,paint);
    }
    private void glow(Canvas canvas,float x,float y,float radius,int color) {
        paint.setStyle(Paint.Style.FILL);
        paint.setShader(new RadialGradient(x,y,radius,new int[]{color,color&0x00FFFFFF},null,Shader.TileMode.CLAMP));
        canvas.drawRect(0,0,getWidth(),getHeight(),paint);
    }
}
