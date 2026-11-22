package android.view;
public final class DisplayCutout {
    private final int left, top, right, bottom;
    public DisplayCutout(int l, int t, int r, int b) { left=l; top=t; right=r; bottom=b; }
    public int getSafeInsetLeft() { return left; }
    public int getSafeInsetTop() { return top; }
    public int getSafeInsetRight() { return right; }
    public int getSafeInsetBottom() { return bottom; }
}
