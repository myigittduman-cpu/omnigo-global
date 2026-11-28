//! Keep an opted-in session durable even when Windows terminates the webview before its cookie flush.
use serde::{Deserialize, Serialize};
use std::{path::Path, sync::Mutex, time::{SystemTime, UNIX_EPOCH}};
use tauri::{AppHandle, Manager, WebviewWindow};
use cookie::{Cookie, SameSite, time::OffsetDateTime};

static ACCESS: Mutex<()> = Mutex::new(());
const MAX_AGE: i64 = 400 * 86400;

#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Record { origin: String, token: String, expires: i64 }

fn now() -> i64 { SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs() as i64 }
fn valid(record: &Record, origin: &str) -> bool {
    record.origin == origin && record.token.len() == 64
        && record.token.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
        && record.expires > now() && record.expires <= now() + MAX_AGE + 300
}
fn clear(file: &Path) -> Result<(), String> {
    match std::fs::remove_file(file) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(_) => Err("Bu cihazdaki oturum kaydı kaldırılamadı.".into()),
    }
}
fn read(file: &Path, origin: &str) -> Option<Record> {
    let bytes = std::fs::read(file).ok()?;
    if bytes.len() > 4096 { return None; }
    let plain = crate::device_storage::crypt(&bytes, false).ok()?;
    let record: Record = serde_json::from_slice(&plain).ok()?;
    valid(&record, origin).then_some(record)
}
fn write(file: &Path, record: &Record) -> Result<(), String> {
    if !valid(record, &record.origin) { return Err("Kalıcı oturum bilgisi geçersiz.".into()); }
    let plain = serde_json::to_vec(record).map_err(|_| "Oturum bilgisi hazırlanamadı.")?;
    let encrypted = crate::device_storage::crypt(&plain, true)?;
    let parent = file.parent().ok_or("Oturum klasörü bulunamadı.")?;
    std::fs::create_dir_all(parent).map_err(|_| "Oturum klasörü hazırlanamadı.")?;
    let mut temporary = tempfile::NamedTempFile::new_in(parent).map_err(|_| "Oturum kaydedilemedi.")?;
    use std::io::Write;
    temporary.write_all(&encrypted).map_err(|_| "Oturum kaydedilemedi.")?;
    temporary.as_file().sync_all().map_err(|_| "Oturum kaydedilemedi.")?;
    temporary.persist(file).map_err(|_| "Oturum kaydedilemedi.")?;
    Ok(())
}

/// No cookie or credential is accepted from, or returned to, JavaScript.
#[tauri::command]
pub async fn persistent_session(app: AppHandle, window: WebviewWindow, operation: String) -> Result<bool, String> {
    let url = window.url().map_err(|_| "Oturum kaynağı doğrulanamadı.")?;
    if window.label() != "main" || !crate::reports::allowed_origin(&url, &app.config().identifier) {
        return Err("Oturum kaydına bu sayfadan erişilemiyor.".into());
    }
    let _guard = ACCESS.lock().map_err(|_| "Oturum işlemi tamamlanamadı.")?;
    let origin = url.origin().ascii_serialization();
    let file = app.path().app_local_data_dir().map_err(|_| "Oturum klasörü bulunamadı.")?.join("persistent-session.dpapi");
    if operation == "clear" { clear(&file)?; return Ok(false); }
    if !matches!(operation.as_str(), "capture" | "restore") { return Err("Oturum işlemi desteklenmiyor.".into()); }
    let name = if app.config().identifier == "com.omnigoglobal.admin" { "og_admin_session" } else { "og_client_session" };
    let cookies = window.cookies_for_url(url.clone()).map_err(|_| "Bu cihazdaki oturum okunamadı.")?;
    let current = cookies.iter().find(|cookie| cookie.name() == name && cookie.path() == Some("/")
        && cookie.http_only() == Some(true) && cookie.secure() == Some(true)
        && cookie.domain().is_some_and(|domain| domain.trim_start_matches('.') == url.host_str().unwrap_or("")));
    if operation == "restore" {
        // A newer account/session in the webview always wins over an older saved record.
        if current.is_some() { return Ok(false); }
        let Some(record) = read(&file, &origin) else { clear(&file)?; return Ok(false); };
        let mut cookie = Cookie::new(name, record.token);
        cookie.set_domain(url.host_str().unwrap_or(""));cookie.set_path("/");
        cookie.set_secure(true);cookie.set_http_only(true);cookie.set_same_site(SameSite::Strict);
        cookie.set_expires(OffsetDateTime::from_unix_timestamp(record.expires).map_err(|_| "Oturum süresi geçersiz.")?);
        window.set_cookie(cookie).map_err(|_| "Kayıtlı oturum geri yüklenemedi.")?;
        return Ok(true);
    }
    let record = current.and_then(|cookie| cookie.expires_datetime().map(|expires| Record {
        origin: origin.clone(), token: cookie.value().to_owned(), expires: expires.unix_timestamp(),
    })).filter(|record| valid(record, &origin));
    if let Some(record) = record { write(&file, &record)?; Ok(true) } else { clear(&file)?; Ok(false) }
}

#[cfg(all(test, windows))]
mod tests {
    #[test]
    fn encrypted_record_survives_reopening_and_is_bound_to_its_portal() {
        let folder = tempfile::tempdir().unwrap();let file = folder.path().join("session.dpapi");
        let record = super::Record { origin: "https://portal.omnigoglobal.com".into(), token: "a".repeat(64), expires: super::now() + 3600 };
        super::write(&file, &record).unwrap();
        let bytes = std::fs::read(&file).unwrap();assert!(!bytes.windows(64).any(|x| x == record.token.as_bytes()));
        assert!(super::read(&file, &record.origin).is_some());assert!(super::read(&file, "https://yonetim.omnigoglobal.com").is_none());
        super::clear(&file).unwrap();assert!(super::read(&file, &record.origin).is_none());
    }
    #[test]
    fn invalid_or_expired_credentials_never_restore() {
        let mut record = super::Record { origin: "https://portal.omnigoglobal.com".into(), token: "a".repeat(64), expires: super::now() - 1 };
        assert!(!super::valid(&record, &record.origin));record.expires = super::now() + 3600;record.token = "G".repeat(64);assert!(!super::valid(&record, &record.origin));
        let folder = tempfile::tempdir().unwrap();let file = folder.path().join("session.dpapi");std::fs::write(&file, b"broken").unwrap();assert!(super::read(&file, &record.origin).is_none());
    }
    #[test]
    fn crash_writer() {
        let Some(file) = std::env::var_os("OMNIGO_SESSION_TEST_CHILD_FILE") else { return; };
        super::write(std::path::Path::new(&file), &super::Record { origin: "https://portal.omnigoglobal.com".into(), token: "b".repeat(64), expires: super::now() + 3600 }).unwrap();
        println!("session-saved");
        loop { std::thread::park(); }
    }
    #[test]
    fn forced_process_termination_keeps_the_committed_encrypted_session() {
        use std::io::BufRead;
        let folder = tempfile::tempdir().unwrap();let file = folder.path().join("session.dpapi");
        let mut child = std::process::Command::new(std::env::current_exe().unwrap())
            .args(["--exact", "persistent_session::tests::crash_writer", "--nocapture"])
            .env("OMNIGO_SESSION_TEST_CHILD_FILE", &file).stdout(std::process::Stdio::piped()).spawn().unwrap();
        let reader = std::io::BufReader::new(child.stdout.take().unwrap());
        let saved = reader.lines().map_while(Result::ok).any(|line| line == "session-saved");
        child.kill().unwrap();child.wait().unwrap();assert!(saved);
        assert!(super::read(&file, "https://portal.omnigoglobal.com").is_some());
    }
}
