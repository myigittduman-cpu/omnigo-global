package com.omnigoglobal.client;

import android.os.SystemClock;
import android.webkit.WebView;
import java.util.function.BooleanSupplier;
import java.util.function.Consumer;

/** Wait for bound controls, styles/fonts and a committed renderer frame before revealing the portal. */
final class PortalFirstFrame {
    private static final String READY="Boolean(document.readyState==='complete'"
        +"&&(!document.getElementById('app')||document.documentElement.dataset.omnigoApplicationReady==='true')"
        +"&&Array.from(document.querySelectorAll('link[rel=stylesheet]')).every(link=>link.sheet||link.disabled)"
        +"&&(!document.fonts||document.fonts.status==='loaded'))";
    static void await(WebView view,BooleanSupplier current,Consumer<Boolean> completion) {
        long deadline=SystemClock.elapsedRealtime()+6000;
        Runnable probe=new Runnable() {
            @Override public void run() {
                if(!current.getAsBoolean())return;
                view.evaluateJavascript(READY,value->{
                    if(!current.getAsBoolean())return;
                    if("true".equals(value))view.postVisualStateCallback(0,new WebView.VisualStateCallback(){
                        @Override public void onComplete(long requestId){if(current.getAsBoolean())completion.accept(true);}
                    });
                    else if(SystemClock.elapsedRealtime()<deadline)view.postDelayed(this,80);
                    else completion.accept(false);
                });
            }
        };
        probe.run();
    }
}
