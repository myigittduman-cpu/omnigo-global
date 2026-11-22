/// Chrome and usable page content must both exist before the first visible frame.
pub fn should_reveal(chrome_ready: bool, content_ready: bool, shown: bool, silent: bool, fallback: bool) -> bool {
    !shown && !silent && (fallback || (chrome_ready && content_ready))
}

#[derive(serde::Deserialize)]
pub struct Viewport { pub width:f64, pub height:f64, pub scale:f64 }

pub fn viewport_matches(viewport:&Viewport, width:u32, height:u32)->bool {
    viewport.width.is_finite()&&viewport.height.is_finite()&&viewport.scale.is_finite()
        &&viewport.width>0.0&&viewport.height>0.0&&(0.1..=16.0).contains(&viewport.scale)
        &&(viewport.width*viewport.scale-width as f64).abs()<=2.0
        &&(viewport.height*viewport.scale-height as f64).abs()<=2.0
}

#[cfg(test)]
mod tests {
    use super::should_reveal;
    #[test]
    fn chrome_alone_does_not_expose_the_intermediate_splash() {
        assert!(!should_reveal(true, false, false, false, false));
        assert!(!should_reveal(false, true, false, false, false));
        assert!(should_reveal(true, true, false, false, false));
    }
    #[test]
    fn reloaded_pages_and_background_start_never_change_user_window_geometry() {
        for fallback in [false, true] {
            assert!(!should_reveal(true, true, true, false, fallback));
            assert!(!should_reveal(true, true, false, true, fallback));
        }
    }
    #[test]
    fn network_failure_has_a_visible_bounded_fallback() {
        assert!(should_reveal(false, false, false, false, true));
    }
    #[test]
    fn only_final_viewports_are_ready_at_normal_and_scaled_dpi() {
        use super::{Viewport,viewport_matches};
        assert!(viewport_matches(&Viewport{width:1440.0,height:864.0,scale:1.25},1800,1080));
        assert!(!viewport_matches(&Viewport{width:1280.0,height:860.0,scale:1.0},1920,1080));
        assert!(!viewport_matches(&Viewport{width:1920.0,height:1049.0,scale:1.0},1920,1080));
        assert!(!viewport_matches(&Viewport{width:f64::NAN,height:1080.0,scale:1.0},1920,1080));
    }
}
