package android.view;
public final class WindowInsetsController {
    public static final int BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE=2;
    public int behavior, hiddenTypes, shownTypes, calls;
    public void setSystemBarsBehavior(int value) { behavior=value; }
    public void hide(int types) { hiddenTypes|=types;calls++; }
    public void show(int types) { shownTypes|=types; }
}
