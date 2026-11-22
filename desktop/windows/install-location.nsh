; Install the new package beside the old default folder, then switch references.
; Never rename the old directory: Explorer, antivirus or an updater may hold it open.
Var OmnigoPreviousInstallDir
Var OmnigoDefaultInstallDir
Var OmnigoMigrationSource

Function OmnigoChooseInstallLocation
  StrCpy $OmnigoPreviousInstallDir ""
  ${If} $4 == ""
    StrCpy $INSTDIR $OmnigoDefaultInstallDir
  ${ElseIf} $4 == "$LOCALAPPDATA\${OMNIGO_LEGACY_NAME}"
  ${OrIf} $4 == "$LOCALAPPDATA\${OMNIGO_PREVIOUS_DISPLAY_NAME}"
  ${OrIf} $4 == "$LOCALAPPDATA\${PRODUCTNAME}"
  ${OrIf} $4 == "$LOCALAPPDATA\${OMNIGO_PREVIOUS_INSTALL_FOLDER}"
  ${OrIf} $4 == "$LOCALAPPDATA\${OMNIGO_OLDER_INSTALL_FOLDER}"
    StrCpy $INSTDIR $OmnigoDefaultInstallDir
    StrCpy $OmnigoPreviousInstallDir $4
  ${Else}
    StrCpy $INSTDIR $4
  ${EndIf}
FunctionEnd

Function OmnigoPrepareInstallLocation
  StrCpy $OmnigoMigrationSource ""
  ${If} $OmnigoPreviousInstallDir == ""
  ${OrIf} $OmnigoPreviousInstallDir == $INSTDIR
  ${OrIf} $INSTDIR != $OmnigoDefaultInstallDir
    Return
  ${EndIf}
  ${IfNot} ${FileExists} "$OmnigoPreviousInstallDir\${MAINBINARYNAME}.exe"
    Return
  ${EndIf}
  ; Both directories must be direct children of this user's application directory.
  GetFullPathName $0 "$OmnigoPreviousInstallDir"
  ${GetParent} "$0" $2
  ; GetFullPathName requires an existing path: resolve the destination's parent.
  ${GetParent} "$INSTDIR" $1
  GetFullPathName $3 "$1"
  ${If} $2 != $LOCALAPPDATA
  ${OrIf} $3 != $LOCALAPPDATA
    Abort "Kurulum klasörü doğrulanamadı."
  ${EndIf}
  ; Reject directory links; never follow a redirected installation destination.
  System::Call 'kernel32::GetFileAttributesW(w "$OmnigoPreviousInstallDir") i .r0'
  IntOp $0 $0 & 0x400
  ${If} $0 != 0
    Abort "Eski kurulum klasörü doğrulanamadı."
  ${EndIf}
  System::Call 'kernel32::GetFileAttributesW(w "$INSTDIR") i .r0'
  ${If} $0 != -1
    IntOp $0 $0 & 0x400
    ${If} $0 != 0
      Abort "Yeni kurulum klasörü doğrulanamadı."
    ${EndIf}
  ${EndIf}
  ; A previous interrupted attempt can be resumed only for this exact channel.
  ; An unrelated nonempty destination must never be merged into this installation.
  ; RMDir without /r only removes an empty destination left by a cancelled setup.
  RMDir "$INSTDIR"
  ClearErrors
  ${If} ${FileExists} "$INSTDIR\*.*"
    ClearErrors
    FileOpen $0 "$INSTDIR\.omnigo-install-owner" r
    ${If} ${Errors}
      Abort "Yeni kurulum klasörü dolu. Farklı bir kurulum konumu seçin; mevcut dosyalar korunuyor."
    ${EndIf}
    FileRead $0 $1
    FileClose $0
    ${If} $1 != "${BUNDLEID}"
      Abort "Yeni kurulum klasörü başka bir uygulamaya ait; mevcut dosyalar korunuyor."
    ${EndIf}
  ${EndIf}
  !insertmacro CheckIfAppIsRunning "$OmnigoPreviousInstallDir\${MAINBINARYNAME}.exe" "${PRODUCTNAME}"
  ClearErrors
  CreateDirectory "$INSTDIR"
  ${If} ${Errors}
    Abort "Yeni kurulum klasörü oluşturulamadı. Mevcut kurulumunuz korunuyor."
  ${EndIf}
  FileOpen $0 "$INSTDIR\.omnigo-install-owner" w
  ${If} ${Errors}
    Abort "Yeni kurulum klasörüne yazılamadı. Mevcut kurulumunuz korunuyor."
  ${EndIf}
  FileWrite $0 "${BUNDLEID}"
  FileClose $0
  ${If} ${Errors}
    Abort "Kurulum bilgisi yazılamadı. Mevcut kurulumunuz korunuyor."
  ${EndIf}
  ; Package extraction supplies every executable/resource. Keep old files intact.
  ; Only after successful extraction/registration are shortcuts and startup switched.
  StrCpy $OmnigoMigrationSource $OmnigoPreviousInstallDir
FunctionEnd

!macro OmnigoRelocateShortcut folder name
  !insertmacro IsShortcutTarget "${folder}\${name}.lnk" "$OmnigoMigrationSource\${MAINBINARYNAME}.exe"
  Pop $0
  ${If} $0 = 1
    !insertmacro SetShortcutTarget "${folder}\${name}.lnk" "$INSTDIR\${MAINBINARYNAME}.exe"
    ${IfNot} ${FileExists} "${folder}\${PRODUCTNAME}.lnk"
      Rename "${folder}\${name}.lnk" "${folder}\${PRODUCTNAME}.lnk"
    ${EndIf}
  ${EndIf}
!macroend

!macro OmnigoRelocateStartup name
  ReadRegStr $0 HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "${name}"
  ${If} $0 == '$\"$OmnigoMigrationSource\${MAINBINARYNAME}.exe$\" --background'
  ${OrIf} $0 == '$OmnigoMigrationSource\${MAINBINARYNAME}.exe --background'
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "${name}" '$\"$INSTDIR\${MAINBINARYNAME}.exe$\" --background'
  ${EndIf}
!macroend

Function OmnigoRelocateReferences
  ${If} $OmnigoMigrationSource == ""
    Return
  ${EndIf}
  !insertmacro OmnigoRelocateShortcut "$DESKTOP" "${PRODUCTNAME}"
  !insertmacro OmnigoRelocateShortcut "$DESKTOP" "${OMNIGO_LEGACY_NAME}"
  !insertmacro OmnigoRelocateShortcut "$DESKTOP" "${OMNIGO_PREVIOUS_DISPLAY_NAME}"
  !insertmacro OmnigoRelocateShortcut "$DESKTOP" "${OMNIGO_PREVIOUS_SHORT_NAME}"
  !insertmacro OmnigoRelocateShortcut "$SMPROGRAMS" "${PRODUCTNAME}"
  !insertmacro OmnigoRelocateShortcut "$SMPROGRAMS" "${OMNIGO_LEGACY_NAME}"
  !insertmacro OmnigoRelocateShortcut "$SMPROGRAMS" "${OMNIGO_PREVIOUS_DISPLAY_NAME}"
  !insertmacro OmnigoRelocateShortcut "$SMPROGRAMS" "${OMNIGO_PREVIOUS_SHORT_NAME}"
  !insertmacro OmnigoRelocateStartup "${PRODUCTNAME}"
  !insertmacro OmnigoRelocateStartup "${OMNIGO_LEGACY_NAME}"
  !insertmacro OmnigoRelocateStartup "${OMNIGO_PREVIOUS_DISPLAY_NAME}"
  !insertmacro OmnigoRelocateStartup "${OMNIGO_PREVIOUS_SHORT_NAME}"
FunctionEnd
