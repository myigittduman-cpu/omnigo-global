package android.view;
import android.graphics.Insets;
public final class WindowInsets {
    public static final class Type {
        public static int statusBars() { return 1; }
        public static int navigationBars() { return 2; }
        public static int ime() { return 8; }
        public static int displayCutout() { return 128; }
    }
    public boolean keyboard;
    public int bottom, stableBottom=48, requestedTypes, zeroedTypes;
    public Insets safe=new Insets(0,0,0,0);
    public DisplayCutout cutout;
    public Insets getInsets(int types) { requestedTypes=types;return safe; }
    public boolean isVisible(int types) { return types==Type.ime()&&keyboard; }
    public int getSystemWindowInsetBottom() { return bottom; }
    public int getStableInsetBottom() { return stableBottom; }
    public DisplayCutout getDisplayCutout() { return cutout; }
    public static final class Builder {
        private final WindowInsets output=new WindowInsets();
        public Builder(WindowInsets source) { output.keyboard=source.keyboard; }
        public Builder setInsets(int types,Insets insets) { output.zeroedTypes=types;output.safe=insets;return this; }
        public WindowInsets build() { return output; }
    }
}
