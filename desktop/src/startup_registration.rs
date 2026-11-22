// Rename only this installation's existing startup entry; preserve Windows' enabled/disabled bytes.
fn owns_command(command: &str, executable: &str) -> bool {
    command == format!("{executable} --background") || command == format!("\"{executable}\" --background")
}

#[cfg(windows)]
pub fn migrate(old_name: &str, new_name: &str) {
    use winreg::{RegKey, enums::{HKEY_CURRENT_USER,HKEY_LOCAL_MACHINE,KEY_READ,KEY_WRITE}};
    let Ok(executable)=std::env::current_exe() else {return};
    let Some(executable)=executable.to_str() else {return};
    for root in [HKEY_CURRENT_USER,HKEY_LOCAL_MACHINE] {
        let migrate_root=||->std::io::Result<()> {
            let root=RegKey::predef(root);
            let run=root.open_subkey_with_flags(r"SOFTWARE\Microsoft\Windows\CurrentVersion\Run",KEY_READ|KEY_WRITE)?;
            let command:String=run.get_value(old_name)?;
            if !owns_command(&command,executable) {return Ok(())}
            // An already configured new entry keeps its own preference.
            if run.get_raw_value(new_name).is_ok() {return Ok(())}
            match root.open_subkey_with_flags(r"SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\StartupApproved\Run",KEY_READ|KEY_WRITE) {
                Ok(approved)=>match approved.get_raw_value(old_name) {
                    Ok(value)=>approved.set_raw_value(new_name,&value)?,
                    Err(error) if error.kind()==std::io::ErrorKind::NotFound=>{},
                    Err(error)=>return Err(error),
                },
                Err(error) if error.kind()==std::io::ErrorKind::NotFound=>{},
                Err(error)=>return Err(error),
            }
            run.set_raw_value(new_name,&run.get_raw_value(old_name)?)?;
            run.delete_value(old_name)?;
            Ok(())
        };
        // Missing entries or OS-restricted registries do not prevent opening the application.
        let _=migrate_root();
    }
}
#[cfg(not(windows))]
pub fn migrate(_old_name:&str,_new_name:&str){}

#[cfg(test)]
mod tests {
    use super::owns_command;
    #[test]
    fn migration_is_limited_to_this_installation_and_background_command() {
        let own=r"C:\Apps\Omnigo\omnigo-desktop.exe";
        assert!(owns_command(&format!("\"{own}\" --background"),own));
        assert!(owns_command(&format!("{own} --background"),own));
        assert!(!owns_command(r"C:\Other\omnigo-desktop.exe --background",own));
        assert!(!owns_command(&format!("{own} --background --extra"),own));
        assert!(!owns_command(own,own));
    }
}
