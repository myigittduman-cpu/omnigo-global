function xml(value) {
  return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
}
exports.createManifest = (identity, publisher, publisherName, version, { architecture = 'x64', windows10 = false } = {}) => {
  if (!/^\d+\.\d+\.\d+\.0$/.test(version)) throw new Error('Mağaza sürümü geçersiz.');
  if (!['x86', 'x64', 'arm64'].includes(architecture)) throw new Error('Mağaza mimarisi geçersiz.');
  const launcherName = identity.launcherName || identity.displayName;
  const minVersion = windows10 ? '10.0.17763.0' : '10.0.22000.0';
  const startupParameters = windows10 ? '' : ' uap10:Parameters="--background"';
  const modernNamespaces = windows10 ? '' : '\n xmlns:uap10="http://schemas.microsoft.com/appx/manifest/uap/windows10/10"';
  const shortcut = `
    <desktop7:Extension Category="windows.shortcut" Executable="omnigo-desktop.exe" EntryPoint="Windows.FullTrustApplication">
     <desktop7:Shortcut File="$(Desktop)\\${xml(launcherName)}.lnk" Icon="$(Package)\\Simgeler\\Omnigo-Klasik.ico" Description="${xml(launcherName)}"/>
    </desktop7:Extension>`;
  return `<?xml version="1.0" encoding="utf-8"?>
<Package xmlns="http://schemas.microsoft.com/appx/manifest/foundation/windows10"
 xmlns:uap="http://schemas.microsoft.com/appx/manifest/uap/windows10"
 xmlns:desktop="http://schemas.microsoft.com/appx/manifest/desktop/windows10"${modernNamespaces}
 xmlns:desktop7="http://schemas.microsoft.com/appx/manifest/desktop/windows10/7"
 xmlns:rescap="http://schemas.microsoft.com/appx/manifest/foundation/windows10/restrictedcapabilities"
 IgnorableNamespaces="uap desktop desktop7${windows10 ? '' : ' uap10'} rescap">
 <Identity Name="${xml(identity.name)}" Publisher="${xml(publisher)}" Version="${version}" ProcessorArchitecture="${architecture}" />
 <Properties>
  <DisplayName>${xml(identity.displayName)}</DisplayName>
  <PublisherDisplayName>${xml(publisherName)}</PublisherDisplayName>
  <Logo>Assets\\StoreLogo.png</Logo>
 </Properties>
 <Resources><Resource Language="tr-TR"/><Resource Language="en-US"/><Resource Language="de-DE"/></Resources>
 <Dependencies><TargetDeviceFamily Name="Windows.Desktop" MinVersion="${minVersion}" MaxVersionTested="10.0.26100.0"/></Dependencies>
 <Applications>
  <Application Id="App" Executable="omnigo-desktop.exe" EntryPoint="Windows.FullTrustApplication">
   <uap:VisualElements DisplayName="${xml(launcherName)}" Description="Omnigo Global pazaryeri ve operasyon çalışma alanı"
    Square150x150Logo="Assets\\Square150x150Logo.png" Square44x44Logo="Assets\\Square44x44Logo.png" BackgroundColor="transparent"/>
   <Extensions>${shortcut}
    <desktop:Extension Category="windows.startupTask" Executable="omnigo-desktop.exe" EntryPoint="Windows.FullTrustApplication"${startupParameters}>
     <desktop:StartupTask TaskId="OmnigoBackground" Enabled="false" DisplayName="${xml(launcherName)}"/>
    </desktop:Extension>
   </Extensions>
  </Application>
 </Applications>
 <Capabilities><rescap:Capability Name="runFullTrust"/></Capabilities>
</Package>`;
};
