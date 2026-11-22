use std::sync::{OnceLock, atomic::{AtomicU8, Ordering}};
use tauri::{AppHandle, Manager};

static LANGUAGE: AtomicU8 = AtomicU8::new(0);
static MESSAGES: OnceLock<serde_json::Value> = OnceLock::new();

fn code(value: &str) -> Option<u8> { match value { "tr" => Some(0), "en" => Some(1), "de" => Some(2), _ => None } }
fn language() -> &'static str { match LANGUAGE.load(Ordering::Acquire) { 1 => "en", 2 => "de", _ => "tr" } }
pub fn initialize(app: &AppHandle) {
    if let Ok(folder) = app.path().app_local_data_dir() {
        if let Ok(value) = std::fs::read_to_string(folder.join("language.txt")) {
            if let Some(code) = code(value.trim()) { LANGUAGE.store(code, Ordering::Release); }
        }
    }
}
pub fn set(app: &AppHandle, value: &str) -> Result<(), String> {
    let next = code(value).ok_or("Unsupported language")?;
    if LANGUAGE.load(Ordering::Acquire) == next { return Ok(()); }
    let folder = app.path().app_local_data_dir().map_err(|_| "Language folder unavailable")?;
    std::fs::create_dir_all(&folder).map_err(|_| "Language preference unavailable")?;
    let mut file = tempfile::NamedTempFile::new_in(&folder).map_err(|_| "Language preference unavailable")?;
    use std::io::Write;
    file.write_all(value.as_bytes()).map_err(|_| "Language preference unavailable")?;
    file.persist(folder.join("language.txt")).map_err(|_| "Language preference unavailable")?;
    LANGUAGE.store(next, Ordering::Release);
    crate::tray_menu::refresh_language(app);
    Ok(())
}
pub fn t(source: &str) -> String {
    let catalog = MESSAGES.get_or_init(|| serde_json::from_str(include_str!("native-translations.json")).expect("Validated translation catalog"));
    catalog.get(source).and_then(|row| row.get(language())).and_then(|text| text.as_str()).unwrap_or(source).to_string()
}
pub fn format(source: &str, values: &[&str]) -> String {
    let mut text = t(source);
    for (index, value) in values.iter().enumerate() { text = text.replace(&format!("{{{index}}}"), value); }
    text
}

#[cfg(test)]
mod tests {
    #[test]
    fn language_codes_are_closed_and_catalog_keeps_placeholders() {
        assert_eq!(super::code("../../bad"), None);
        let catalog: serde_json::Value = serde_json::from_str(include_str!("native-translations.json")).unwrap();
        for lang in ["en", "de"] { assert!(catalog["{0} açılıyor…"][lang].as_str().unwrap().contains("{0}")); }
    }
}
