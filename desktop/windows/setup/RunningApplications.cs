using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32;

namespace Omnigo.Setup {
    internal static class RunningApplications {
        [DllImport("rstrtmgr.dll",CharSet=CharSet.Unicode)]private static extern int RmStartSession(out uint session,int flags,StringBuilder key);
        [DllImport("rstrtmgr.dll",CharSet=CharSet.Unicode)]private static extern int RmRegisterResources(uint session,uint files,string[] names,uint applications,IntPtr processes,uint services,string[] serviceNames);
        [DllImport("rstrtmgr.dll")]private static extern int RmGetList(uint session,out uint needed,ref uint count,IntPtr processes,ref uint reasons);
        [DllImport("rstrtmgr.dll")]private static extern int RmEndSession(uint session);
        internal static bool Any(SetupConfig config,string destination) {
            var paths=new List<string>{Path.Combine(destination,"omnigo-desktop.exe"),Path.Combine(config.DefaultDirectory,"omnigo-desktop.exe")};
            var legacy=config.Folder=="OGYoneticiPortali"?"Omnigo Yonetim":"Omnigo Musteri";
            using(var key=Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Uninstall\"+legacy)) {
                var location=key==null?null:key.GetValue("InstallLocation") as string;
                var binary=key==null?null:key.GetValue("MainBinaryName") as string;
                if(!String.IsNullOrWhiteSpace(location)) {
                    location=location.Trim('"');paths.Add(Path.Combine(location,"omnigo-desktop.exe"));
                    if(!String.IsNullOrEmpty(binary)&&Path.GetFileName(binary)==binary)paths.Add(Path.Combine(location,binary));
                }
            }
            var files=paths.Where(File.Exists).Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
            if(files.Length==0)return false;
            uint session;
            if(RmStartSession(out session,0,new StringBuilder(33))!=0)throw new IOException("Açık uygulama kontrolü yapılamadı. Uygulamadan tamamen çıkıp yeniden deneyin.");
            try {
                if(RmRegisterResources(session,(uint)files.Length,files,0,IntPtr.Zero,0,null)!=0)throw new IOException("Kurulum dosyaları denetlenemedi.");
                uint needed,count=0,reasons=0;
                var result=RmGetList(session,out needed,ref count,IntPtr.Zero,ref reasons);
                if(result==234)return true;
                if(result!=0)throw new IOException("Açık uygulama kontrolü tamamlanamadı.");
                return needed>0;
            } finally { RmEndSession(session); }
        }
    }
}
