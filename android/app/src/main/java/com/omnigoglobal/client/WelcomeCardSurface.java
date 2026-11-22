package com.omnigoglobal.client;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RadialGradient;
import android.graphics.Shader;
import android.graphics.drawable.GradientDrawable;

/** Animated light belongs to the background, underneath the original logo and fixed labels. */
final class WelcomeCardSurface extends GradientDrawable {
    private final Paint paint=new Paint(Paint.ANTI_ALIAS_FLAG);
    private final float radius;
    private float phase;
    WelcomeCardSurface(Context context) {
        super(Orientation.TL_BR,new int[]{0xFF233041,0xFF152134});
        float density=context.getResources().getDisplayMetrics().density;
        radius=20*density;setCornerRadius(radius);setStroke(Math.max(1,Math.round(density)),0x776C6047);
    }
    void setPhase(float value) { phase=value;invalidateSelf(); }
    @Override public void draw(Canvas canvas) {
        super.draw(canvas);int save=canvas.save();Path clip=new Path();
        clip.addRoundRect(getBounds().left,getBounds().top,getBounds().right,getBounds().bottom,radius,radius,Path.Direction.CW);canvas.clipPath(clip);
        float width=getBounds().width(),height=getBounds().height(),shift=(float)Math.sin(phase*Math.PI*2)*width*.16f;
        light(canvas,-width*.12f+shift,height*.05f,width*.9f,0x20C7AA72);
        light(canvas,width*1.05f-shift,height*.94f,width*.9f,0x265E85B3);
        canvas.restoreToCount(save);
    }
    private void light(Canvas canvas,float x,float y,float size,int color) {
        paint.setShader(new RadialGradient(x,y,size,new int[]{color,color&0x00FFFFFF},null,Shader.TileMode.CLAMP));
        canvas.drawRect(getBounds(),paint);
    }
}
