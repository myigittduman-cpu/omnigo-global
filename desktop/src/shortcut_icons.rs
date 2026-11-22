// Content addressing is only a Shell cache key, not a security signature.
#[cfg(any(windows,test))]
fn icon_file_name(role:&str,bytes:&[u8])->String {
    let hash=bytes.iter().fold(0xcbf29ce484222325u64,|hash,byte|(hash^u64::from(*byte)).wrapping_mul(0x100000001b3));
    format!("{role}-{hash:016x}.ico")
}

#[cfg(windows)]
pub(crate) mod windows_icons {
    use std::{os::windows::ffi::OsStrExt,path::Path};
    use windows::{core::{Interface,PCWSTR},Win32::{System::Com::{CoCreateInstance,CoInitializeEx,CoUninitialize,IPersistFile,CLSCTX_INPROC_SERVER,COINIT_APARTMENTTHREADED,STGM_READWRITE},UI::Shell::{IShellLinkW,ShellLink,SHChangeNotify,SHCNE_UPDATEITEM,SHCNF_PATHW,SHCNF_FLUSHNOWAIT,SLGP_RAWPATH}}};

    pub struct Apartment;
    impl Apartment {
        pub fn open()->windows::core::Result<Self>{unsafe{CoInitializeEx(None,COINIT_APARTMENTTHREADED).ok()?;}Ok(Self)}
    }
    impl Drop for Apartment {fn drop(&mut self){unsafe{CoUninitialize();}}}
    fn wide(path:&Path)->Vec<u16>{path.as_os_str().encode_wide().chain(Some(0)).collect()}
    fn same_file(a:&Path,b:&Path)->bool{
        match (a.canonicalize(),b.canonicalize()) {
            (Ok(a),Ok(b))=>a.to_string_lossy().eq_ignore_ascii_case(&b.to_string_lossy()),
            _=>false,
        }
    }

    // Preserve target, arguments, AppUserModelId and all other shell-link metadata.
    // Never claim another installation's shortcut or a directory link.
    pub fn repair(link_path:&Path,executable:&Path,icon:&Path)->windows::core::Result<bool>{
        if !link_path.is_file()||link_path.is_symlink()||!icon.is_file(){return Ok(false);}
        unsafe{
            let link:IShellLinkW=CoCreateInstance(&ShellLink,None,CLSCTX_INPROC_SERVER)?;
            let file:IPersistFile=link.cast()?;let name=wide(link_path);
            file.Load(PCWSTR(name.as_ptr()),STGM_READWRITE)?;
            let mut target=vec![0u16;32768];link.GetPath(&mut target,std::ptr::null_mut(),SLGP_RAWPATH.0 as u32)?;
            let end=target.iter().position(|v|*v==0).unwrap_or(target.len());
            if !same_file(Path::new(&String::from_utf16_lossy(&target[..end])),executable){return Ok(false);}
            let mut old=vec![0u16;32768];let mut index=0;link.GetIconLocation(&mut old,&mut index)?;
            let end=old.iter().position(|v|*v==0).unwrap_or(old.len());
            if index==0&&same_file(Path::new(&String::from_utf16_lossy(&old[..end])),icon){return Ok(false);}
            let icon=wide(icon);link.SetIconLocation(PCWSTR(icon.as_ptr()),0)?;
            file.Save(PCWSTR(name.as_ptr()),true)?;
            SHChangeNotify(SHCNE_UPDATEITEM,SHCNF_PATHW|SHCNF_FLUSHNOWAIT,Some(name.as_ptr().cast()),None);
            Ok(true)
        }
    }
}

#[cfg(windows)]
pub fn start(app:&tauri::AppHandle){
    use tauri::Manager;
    let Some(label)=crate::application_name::for_identifier(&app.config().identifier) else{return;};
    let Ok(desktop)=app.path().desktop_dir() else{return;};
    let Ok(executable)=std::env::current_exe() else{return;};
    #[cfg(feature="microsoft-store")]
    {
        // MSIX redirects LocalAppData writes into package-private storage. Explorer cannot
        // resolve that logical path. The package owns this versioned, read-only resource.
        let Some(folder)=executable.parent() else{return;};
        let icon=folder.join("Simgeler").join("Omnigo-Klasik.ico");
        std::thread::spawn(move||{if let Ok(_apartment)=windows_icons::Apartment::open(){let _=windows_icons::repair(&desktop.join(format!("{label}.lnk")),&executable,&icon);}});
        return;
    }
    #[cfg(not(feature="microsoft-store"))]
    let Ok(local)=app.path().app_local_data_dir() else{return;};
    #[cfg(not(feature="microsoft-store"))]
    {
    let admin=app.config().identifier=="com.omnigoglobal.admin";
    let bytes:&'static[u8]=if admin{include_bytes!("../icons/admin/icon.ico")}else{include_bytes!("../icons/client/icon.ico")};
    // A changed image gets a new path, so Explorer cannot retain the old icon by filename.
    let icon=local.join("shortcut-icons").join(icon_file_name(if admin{"admin"}else{"client"},bytes));
    std::thread::spawn(move||{
        let operation=||->Result<(),Box<dyn std::error::Error>>{
            let _apartment=windows_icons::Apartment::open()?;
            std::fs::create_dir_all(icon.parent().ok_or("Simge klasörü bulunamadı.")?)?;
            if std::fs::read(&icon).ok().as_deref()!=Some(bytes){
                use std::io::Write;
                let mut file=tempfile::NamedTempFile::new_in(icon.parent().unwrap())?;
                file.write_all(bytes)?;file.persist(&icon)?;
            }
            windows_icons::repair(&desktop.join(format!("{label}.lnk")),&executable,&icon)?;
            Ok(())
        };
        if operation().is_err(){eprintln!("Uygulama kısayolunun simgesi yenilenemedi.");}
    });
    }
}
#[cfg(not(windows))]
pub fn start(_app:&tauri::AppHandle){}

#[cfg(all(test,windows))]
mod tests{
    use super::windows_icons::{Apartment,repair};
    use windows::{core::{Interface,PCWSTR},Win32::{System::Com::{CoCreateInstance,IPersistFile,CLSCTX_INPROC_SERVER},UI::Shell::{IShellLinkW,ShellLink}}};
    fn wide(path:&std::path::Path)->Vec<u16>{use std::os::windows::ffi::OsStrExt;path.as_os_str().encode_wide().chain(Some(0)).collect()}
    #[test]
    fn changed_artwork_and_roles_get_distinct_cache_paths(){
        assert_eq!(super::icon_file_name("admin",b"one"),super::icon_file_name("admin",b"one"));
        assert_ne!(super::icon_file_name("admin",b"one"),super::icon_file_name("admin",b"two"));
        assert_ne!(super::icon_file_name("admin",b"one"),super::icon_file_name("client",b"one"));
    }
    #[test]
    fn only_owned_shortcuts_are_repaired_and_arguments_are_preserved(){
        let _apartment=Apartment::open().unwrap();let dir=tempfile::tempdir().unwrap();
        let executable=std::env::current_exe().unwrap();
        let link_path=dir.path().join("OG İş Ortağım.lnk");
        let icon=dir.path().join("Örnek simge.ico");
        std::fs::write(&icon,include_bytes!("../icons/client/icon.ico")).unwrap();
        unsafe{
            let link:IShellLinkW=CoCreateInstance(&ShellLink,None,CLSCTX_INPROC_SERVER).unwrap();
            link.SetPath(PCWSTR(wide(&executable).as_ptr())).unwrap();
            link.SetArguments(windows::core::w!("--background")).unwrap();
            let file:IPersistFile=link.cast().unwrap();file.Save(PCWSTR(wide(&link_path).as_ptr()),true).unwrap();
            assert!(!repair(&link_path,&icon,&icon).unwrap());
            assert!(repair(&link_path,&executable,&icon).unwrap());
            assert!(!repair(&link_path,&executable,&icon).unwrap());
            file.Load(PCWSTR(wide(&link_path).as_ptr()),windows::Win32::System::Com::STGM_READ).unwrap();
            let mut args=[0u16;100];link.GetArguments(&mut args).unwrap();
            assert_eq!(String::from_utf16_lossy(&args[..12]),"--background");
        }
    }
}
