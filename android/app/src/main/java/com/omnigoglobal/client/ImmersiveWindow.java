package com.omnigoglobal.client;

import android.graphics.Insets;
import android.os.Build;
import android.view.DisplayCutout;
import android.view.View;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.view.WindowManager;

/** Owns system bars only; keyboard visibility and Android back navigation remain native. */
final class ImmersiveWindow {
    private final Window window;
    private final View content;
    private final Runnable restoreBars = this::applyBarVisibility;
    private boolean keyboardVisible;

    ImmersiveWindow(Window window, View content) {
        this.window = window;
        this.content = content;
        if (Build.VERSION.SDK_INT >= 28) {
            // Paint the existing root background behind the camera, while keeping safe content insets.
            WindowManager.LayoutParams attributes = window.getAttributes();
            attributes.layoutInDisplayCutoutMode = Build.VERSION.SDK_INT >= 30
                ? WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_ALWAYS
                : WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
            window.setAttributes(attributes);
        }
        if (Build.VERSION.SDK_INT >= 30) window.setDecorFitsSystemWindows(false);
        content.setOnApplyWindowInsetsListener(this::applyInsets);
        restore();
    }

    void restore() {
        applyBarVisibility();
        // A controller may not exist until the initial window is attached.
        content.removeCallbacks(restoreBars);
        content.post(restoreBars);
        content.requestApplyInsets();
    }

    private void applyBarVisibility() {
        if (Build.VERSION.SDK_INT >= 30) {
            WindowInsetsController controller = window.getInsetsController();
            if (controller != null) {
                controller.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
                controller.hide(WindowInsets.Type.statusBars());
                controller.show(WindowInsets.Type.navigationBars());
            }
        } else {
            // Do not set FLAG_FULLSCREEN: it disables adjustResize on older Android releases.
            window.getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                | View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY);
        }
    }

    private WindowInsets applyInsets(View view, WindowInsets insets) {
        boolean visible;
        WindowInsets childInsets = insets;
        if (Build.VERSION.SDK_INT >= 30) {
            // Keep native navigation, including a landscape side bar, clear of the portal.
            int types = WindowInsets.Type.displayCutout()
                | WindowInsets.Type.navigationBars() | WindowInsets.Type.ime();
            Insets safe = insets.getInsets(types);
            view.setPadding(safe.left, safe.top, safe.right, safe.bottom);
            visible = insets.isVisible(WindowInsets.Type.ime());
            // The WebView must not reserve the same space through CSS safe-area insets.
            childInsets = new WindowInsets.Builder(insets).setInsets(types, Insets.NONE).build();
        } else {
            int left = 0, top = 0, right = 0, bottom = 0;
            if (Build.VERSION.SDK_INT >= 28) {
                DisplayCutout cutout = insets.getDisplayCutout();
                if (cutout != null) {
                    left = cutout.getSafeInsetLeft(); top = cutout.getSafeInsetTop();
                    right = cutout.getSafeInsetRight(); bottom = cutout.getSafeInsetBottom();
                }
            }
            int keyboardThreshold = Math.round(80 * view.getResources().getDisplayMetrics().density);
            visible = insets.getSystemWindowInsetBottom() > insets.getStableInsetBottom() + keyboardThreshold;
            if (visible) bottom = Math.max(bottom, insets.getSystemWindowInsetBottom());
            view.setPadding(left, top, right, bottom);
        }
        if (keyboardVisible && !visible) {
            // Restore the status bar policy after the keyboard closes.
            content.removeCallbacks(restoreBars);
            content.post(restoreBars);
        }
        keyboardVisible = visible;
        return childInsets;
    }

    void dispose() {
        content.removeCallbacks(restoreBars);
        content.setOnApplyWindowInsetsListener(null);
    }
}
