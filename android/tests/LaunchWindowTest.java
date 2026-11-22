package com.omnigoglobal.client;
public final class LaunchWindowTest {
    private static void check(boolean value) { if(!value)throw new AssertionError(); }
    public static void main(String[] args) {
        LaunchWindow cold = new LaunchWindow(10000,0);
        check(cold.remaining(10000)==5000);check(cold.remaining(14999)==1);check(cold.remaining(15000)==0);
        LaunchWindow rotation = new LaunchWindow(12000,cold.deadline);
        check(rotation.remaining(12000)==3000);
        check(rotation.remaining(17000)==0); // Return from background, never replay.
        LaunchWindow restored = new LaunchWindow(18000,rotation.deadline);
        check(restored.remaining(18000)==0); // OS recreation preserves the same task.
        LaunchWindow newLaunch = new LaunchWindow(20000,0);
        check(newLaunch.remaining(20000)==5000); // Fully closed task starts a fresh launch.
        System.out.println("Açılış süresi: 5 saniye, arka plan, yeniden oluşturma ve tamamen yeni açılış kontrolleri geçti.");
    }
}
