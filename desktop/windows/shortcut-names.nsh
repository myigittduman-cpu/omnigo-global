; Upgrade only shortcuts targeting this exact installation; keep both channels isolated.
!macro NSIS_HOOK_PREINSTALL
  !insertmacro OmnigoRenameShortcut "$DESKTOP" "${OMNIGO_LEGACY_NAME}"
  !insertmacro OmnigoRenameShortcut "$SMPROGRAMS" "${OMNIGO_LEGACY_NAME}"
  !insertmacro OmnigoRenameShortcut "$DESKTOP" "${OMNIGO_PREVIOUS_DISPLAY_NAME}"
  !insertmacro OmnigoRenameShortcut "$SMPROGRAMS" "${OMNIGO_PREVIOUS_DISPLAY_NAME}"
  !insertmacro OmnigoRenameShortcut "$DESKTOP" "${OMNIGO_PREVIOUS_SHORT_NAME}"
  !insertmacro OmnigoRenameShortcut "$SMPROGRAMS" "${OMNIGO_PREVIOUS_SHORT_NAME}"
!macroend
!macro NSIS_HOOK_POSTINSTALL
  ; Distinct resource destinations prevent the merged admin/client config from overwriting a role icon.
  CopyFiles /SILENT "$INSTDIR\${OMNIGO_SHORTCUT_ICON}" "$INSTDIR\Simgeler\Omnigo-Klasik.ico"
  ; Refresh the Start shortcut too; preserve its application identity.
  CreateShortCut "$SMPROGRAMS\${PRODUCTNAME}.lnk" "$INSTDIR\${MAINBINARYNAME}.exe" "" "$INSTDIR\${OMNIGO_SHORTCUT_CACHE_ICON}" 0
  !insertmacro SetLnkAppUserModelId "$SMPROGRAMS\${PRODUCTNAME}.lnk"
  System::Call 'shell32::SHChangeNotify(i 0x00002000, i 0x00001005, w "$SMPROGRAMS\${PRODUCTNAME}.lnk", p 0)'
  ; Register both the notification header and the body logo under this channel only.
  WriteRegStr HKCU "Software\Classes\AppUserModelId\${BUNDLEID}" "DisplayName" "${OMNIGO_NOTIFICATION_NAME}"
  WriteRegStr HKCU "Software\Classes\AppUserModelId\${BUNDLEID}" "IconUri" "$INSTDIR\notification-icon.png"
!macroend
!macro OmnigoEnsureDesktopShortcut folder
  ${If} $NoShortcutMode = 1
    Return
  ${EndIf}
  ${If} ${FileExists} "${folder}\${PRODUCTNAME}.lnk"
    !insertmacro IsShortcutTarget "${folder}\${PRODUCTNAME}.lnk" "$INSTDIR\${MAINBINARYNAME}.exe"
    Pop $0
    ${If} $0 != 1
      ${If} $OldMainBinaryName == ""
        Return
      ${EndIf}
      !insertmacro IsShortcutTarget "${folder}\${PRODUCTNAME}.lnk" "$INSTDIR\$OldMainBinaryName"
      Pop $0
      ${If} $0 != 1
        Return
      ${EndIf}
    ${EndIf}
  ${EndIf}
  ClearErrors
  CreateShortcut "${folder}\${PRODUCTNAME}.lnk" "$INSTDIR\${MAINBINARYNAME}.exe" "" "$INSTDIR\${OMNIGO_SHORTCUT_CACHE_ICON}" 0
  ${If} ${Errors}
    DetailPrint "Masaüstü kısayolu oluşturulamadı."
    Return
  ${EndIf}
  !insertmacro SetLnkAppUserModelId "${folder}\${PRODUCTNAME}.lnk"
  System::Call 'shell32::SHChangeNotify(i 0x00002000, i 0x00001005, w "${folder}\${PRODUCTNAME}.lnk", p 0)'
!macroend
!macro NSIS_HOOK_POSTUNINSTALL
  Delete "$INSTDIR\${OMNIGO_SHORTCUT_CACHE_ICON}"
  ReadRegStr $0 HKCU "Software\Classes\AppUserModelId\${BUNDLEID}" "IconUri"
  ${If} $0 == "$INSTDIR\notification-icon.png"
    DeleteRegValue HKCU "Software\Classes\AppUserModelId\${BUNDLEID}" "DisplayName"
    DeleteRegValue HKCU "Software\Classes\AppUserModelId\${BUNDLEID}" "IconUri"
    DeleteRegKey /ifempty HKCU "Software\Classes\AppUserModelId\${BUNDLEID}"
  ${EndIf}
!macroend
!macro OmnigoRenameShortcut folder oldName
  !insertmacro IsShortcutTarget "${folder}\${oldName}.lnk" "$INSTDIR\${MAINBINARYNAME}.exe"
  Pop $0
  ${If} $0 = 1
    ${IfNot} ${FileExists} "${folder}\${PRODUCTNAME}.lnk"
      Rename "${folder}\${oldName}.lnk" "${folder}\${PRODUCTNAME}.lnk"
    ${EndIf}
  ${EndIf}
!macroend
