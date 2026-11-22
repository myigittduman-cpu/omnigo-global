package android.view;
import java.util.ArrayList;
import java.util.List;
public final class View {
    public static final int SYSTEM_UI_FLAG_LAYOUT_STABLE=256, SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN=1024,
        SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION=512, SYSTEM_UI_FLAG_FULLSCREEN=4,
        SYSTEM_UI_FLAG_HIDE_NAVIGATION=2, SYSTEM_UI_FLAG_IMMERSIVE_STICKY=4096;
    public interface OnApplyWindowInsetsListener { WindowInsets onApplyWindowInsets(View view, WindowInsets insets); }
    public static final class Metrics { public float density=1; }
    public static final class Resources { public Metrics getDisplayMetrics() { return new Metrics(); } }
    public OnApplyWindowInsetsListener listener;
    public final List<Runnable> callbacks=new ArrayList<>();
    public int visibility, requested, left, top, right, bottom;
    public void setOnApplyWindowInsetsListener(OnApplyWindowInsetsListener value) { listener=value; }
    public void setSystemUiVisibility(int value) { visibility=value; }
    public void removeCallbacks(Runnable value) { callbacks.removeIf(item->item==value); }
    public boolean post(Runnable value) { callbacks.add(value); return true; }
    public void requestApplyInsets() { requested++; }
    public void setPadding(int l,int t,int r,int b) { left=l;top=t;right=r;bottom=b; }
    public Resources getResources() { return new Resources(); }
    public void drain() { List<Runnable> pending=new ArrayList<>(callbacks);callbacks.clear();for(Runnable item:pending)item.run(); }
}
