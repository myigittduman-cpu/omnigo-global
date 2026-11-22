package android.view;
public final class Window {
    public boolean decorFits=true;
    public WindowInsetsController controller=new WindowInsetsController();
    public final View decor=new View();
    public WindowManager.LayoutParams attributes=new WindowManager.LayoutParams();
    public WindowManager.LayoutParams getAttributes() { return attributes; }
    public void setAttributes(WindowManager.LayoutParams value) { attributes=value; }
    public void setDecorFitsSystemWindows(boolean value) { decorFits=value; }
    public WindowInsetsController getInsetsController() { return controller; }
    public View getDecorView() { return decor; }
}
