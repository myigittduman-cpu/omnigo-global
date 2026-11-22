// Window lifetime is independent from notification opt-in and authentication.
// A usable tray is required so a hidden window can always be reopened or quit.
pub fn hide_on_close(label: &str, tray_ready: bool) -> bool {
    label == "main" && tray_ready
}

#[cfg(test)]
mod tests {
    use super::hide_on_close;

    #[test]
    fn main_window_stays_in_tray_without_a_notification_registration() {
        assert!(hide_on_close("main", true));
    }

    #[test]
    fn missing_tray_must_not_leave_an_unreachable_background_window() {
        assert!(!hide_on_close("main", false));
    }

    #[test]
    fn welcome_and_secondary_windows_keep_their_own_close_behavior() {
        for label in ["welcome", "popup", "report"] {
            assert!(!hide_on_close(label, true));
        }
    }
}
