pub const ADMIN: &str = "OG Yönetici Portalı";
pub const CLIENT: &str = "OG İş Ortağım";

pub fn for_identifier(identifier: &str) -> Option<&'static str> {
    match identifier {
        "com.omnigoglobal.admin" => Some(ADMIN),
        "com.omnigoglobal.client" => Some(CLIENT),
        _ => None,
    }
}

// Notification display names are independent of installed product names.
pub fn for_notification_identifier(identifier: &str) -> Option<&'static str> {
    match identifier {
        "com.omnigoglobal.admin" => Some("Omnigo Global Yönetici Portalı"),
        "com.omnigoglobal.client" => Some("Omnigo Global İş Ortağım"),
        _ => None,
    }
}
pub fn accepts_notification_title(identifier: &str, title: &str) -> bool {
    for_identifier(identifier) == Some(title) || for_notification_identifier(identifier) == Some(title)
        || (identifier == "com.omnigoglobal.client" && matches!(title, "OG Müşteri Portalı" | "Omnigo Global Müşteri Portalı"))
}

#[cfg(test)]
mod tests {
    #[test]
    fn notification_names_preserve_installed_names_and_legacy_clients() {
        for (id,short,full,other) in [
            ("com.omnigoglobal.admin","OG Yönetici Portalı","Omnigo Global Yönetici Portalı","Omnigo Global İş Ortağım"),
            ("com.omnigoglobal.client","OG İş Ortağım","Omnigo Global İş Ortağım","OG Yönetici Portalı"),
        ] {
            assert_eq!(super::for_identifier(id),Some(short));
            assert_eq!(super::for_notification_identifier(id),Some(full));
            assert!(super::accepts_notification_title(id,short));
            assert!(super::accepts_notification_title(id,full));
            assert!(!super::accepts_notification_title(id,other));
        }
        assert!(!super::accepts_notification_title("unknown","OG Yönetici Portalı"));
    }
}
