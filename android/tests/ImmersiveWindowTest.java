package com.omnigoglobal.client;

import android.graphics.Insets;
import android.os.Build;
import android.view.DisplayCutout;
import android.view.View;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;

/** Host-side API contract tests. These do not emulate an Android keyboard/window manager. */
public final class ImmersiveWindowTest {
    private static int checks;
    private static void check(boolean value,String message) { checks++;if(!value)throw new AssertionError(message); }
    public static void main(String[] args) {
        for(int api:new int[]{30,33,36})modern(api);
        for(int api:new int[]{26,28,29})legacy(api);
        Build.VERSION.SDK_INT=30;
        Window pending=new Window();pending.controller=null;
        View content=new View();ImmersiveWindow immersive=new ImmersiveWindow(pending,content);
        pending.controller=new WindowInsetsController();content.drain();
        check(pending.controller.hiddenTypes==WindowInsets.Type.statusBars(),"Geç bağlanan pencerenin üst çubuğu gizlenmeli");
        check(pending.controller.shownTypes==WindowInsets.Type.navigationBars(),"Geç bağlanan pencerenin gezinme çubuğu görünmeli");
        immersive.dispose();
        System.out.println("Tam ekran API sözleşmesi: "+checks+" kontrol geçti. Gerçek cihaz testi ayrıca gerekir.");
    }

    private static void modern(int api) {
        Build.VERSION.SDK_INT=api;
        Window window=new Window();View content=new View();
        ImmersiveWindow immersive=new ImmersiveWindow(window,content);content.drain();
        check(!window.decorFits,"İçerik sistem çubuklarının alanına uzanmalı");
        check(window.attributes.layoutInDisplayCutoutMode==3,"Arka plan kamera alanının arkasına uzanmalı");
        check(window.controller.hiddenTypes==WindowInsets.Type.statusBars(),"Yalnız üst çubuk gizlenmeli");
        check(window.controller.shownTypes==WindowInsets.Type.navigationBars(),"Sistem gezinme çubuğu görünmeli");
        check(window.controller.behavior==2,"Android kenar hareketi kullanılabilmeli");
        WindowInsets keyboard=new WindowInsets();keyboard.keyboard=true;keyboard.safe=new Insets(9,28,11,320);
        WindowInsets child=content.listener.onApplyWindowInsets(content,keyboard);
        check(content.left==9&&content.top==28&&content.right==11&&content.bottom==320,"Klavye ve ekran çentiği korunmalı");
        check(keyboard.requestedTypes==(WindowInsets.Type.ime()|WindowInsets.Type.displayCutout()|WindowInsets.Type.navigationBars()),"Gezinme çubuğu ve klavye için güvenli alan ayrılmalı");
        check(child!=keyboard&&child.zeroedTypes==keyboard.requestedTypes,"WebView aynı güvenli alanı ikinci kez ayırmamalı");
        int before=window.controller.calls;
        check(content.callbacks.isEmpty(),"Klavye açıkken yeniden gizleme döngüsü başlamamalı");
        WindowInsets closed=new WindowInsets();closed.safe=new Insets(0,0,0,48);
        content.listener.onApplyWindowInsets(content,closed);content.drain();
        check(content.bottom==48&&window.controller.calls>before,"Klavye kapanınca gezinme çubuğuna ait boşluk korunmalı");
        closed.safe=new Insets(0,0,48,0);
        content.listener.onApplyWindowInsets(content,closed);
        check(content.right==48&&content.bottom==0,"Yatay ekrandaki yan gezinme çubuğuna boşluk ayrılmalı");
        check(content.callbacks.isEmpty(),"Boşluk olayları sonsuz döngü oluşturmamalı");
        immersive.restore();immersive.restore();
        check(content.callbacks.size()==1,"Odağa dönüşler yinelenen iş bırakmamalı");
        check((window.controller.hiddenTypes&WindowInsets.Type.ime())==0,"Klavye asla gizlenmemeli");
        immersive.dispose();check(content.callbacks.isEmpty()&&content.listener==null,"Kapanan ekran dinleyici bırakmamalı");
    }

    private static void legacy(int api) {
        Build.VERSION.SDK_INT=api;
        Window window=new Window();View content=new View();
        ImmersiveWindow immersive=new ImmersiveWindow(window,content);content.drain();
        check(window.attributes.layoutInDisplayCutoutMode==(api>=28?1:0),"Eski Android'de desteklenen çentik modu kullanılmalı");
        check((window.decor.visibility&View.SYSTEM_UI_FLAG_FULLSCREEN)!=0,"Eski Android'de üst çubuk gizlenmeli");
        check((window.decor.visibility&(View.SYSTEM_UI_FLAG_HIDE_NAVIGATION|View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION))==0,"Eski Android'de alt çubuğu sistem yerleştirmeli");
        check((window.decor.visibility&View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY)!=0,"Kenar hareketinden sonra üst çubuk gizlenmeli");
        WindowInsets inset=new WindowInsets();inset.bottom=48;
        if(api>=28)inset.cutout=new DisplayCutout(12,26,14,0);
        content.listener.onApplyWindowInsets(content,inset);
        check(content.bottom==0,"Sistemin yerleştirdiği gezinme çubuğu için ikinci boşluk oluşmamalı");
        if(api>=28)check(content.left==12&&content.top==26&&content.right==14,"Eski çentikli ekran güvenli alanı korunmalı");
        inset.bottom=360;content.listener.onApplyWindowInsets(content,inset);
        check(content.bottom==360,"Eski Android klavyesi içerik üstünü kapatmamalı");
        window.decor.visibility=0;inset.bottom=0;content.listener.onApplyWindowInsets(content,inset);content.drain();
        check(content.bottom==0&&(window.decor.visibility&View.SYSTEM_UI_FLAG_FULLSCREEN)!=0,"Klavye kapandıktan sonra üst çubuk tekrar gizlenmeli");
        immersive.dispose();
    }
}
