package com.omnigoglobal.client;

/** Per-activity launch deadline; resuming never starts a new window. */
final class LaunchWindow {
    static final long DURATION_MS = 5000;
    final long deadline;
    LaunchWindow(long now, long restoredDeadline) {
        deadline = restoredDeadline > 0 ? restoredDeadline : now + DURATION_MS;
    }
    long remaining(long now) { return Math.max(0, Math.min(DURATION_MS, deadline - now)); }
    float progress(long now) { return 1f - remaining(now) / (float) DURATION_MS; }
}
