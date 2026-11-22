using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Security.Cryptography;

namespace Omnigo.Setup {
    internal static class SetupPayload {
        internal static int Run(SetupConfig config,string arguments) {
            var temporary=Path.Combine(Path.GetTempPath(),"Omnigo.Setup."+Guid.NewGuid().ToString("N"));
            var file=Path.Combine(temporary,"install.exe");
            Directory.CreateDirectory(temporary);
            try {
                using(var input=SetupConfig.Resource("Setup.Payload"))
                using(var output=new FileStream(file,FileMode.CreateNew,FileAccess.Write,FileShare.None))input.CopyTo(output);
                string hash;
                using(var sha=SHA256.Create())using(var input=File.OpenRead(file))hash=BitConverter.ToString(sha.ComputeHash(input)).Replace("-","").ToLowerInvariant();
                if(!String.Equals(hash,config.Hash,StringComparison.Ordinal))throw new InvalidDataException("Kurulum bütünlüğü doğrulanamadı. Güncel kurulum dosyasını yeniden alın.");
                var info=new ProcessStartInfo(file,arguments) { UseShellExecute=false,CreateNoWindow=true,WorkingDirectory=temporary };
                using(var process=Process.Start(info)) { if(process==null)throw new IOException("Kurulum başlatılamadı."); process.WaitForExit(); return process.ExitCode; }
            } finally {
                // Delete only the single generated payload and its now-empty private directory.
                try { File.Delete(file); Directory.Delete(temporary,false); } catch(IOException) {} catch(UnauthorizedAccessException) {}
            }
        }
        internal static string InstallArguments(string directory) {
            var full=Path.GetFullPath(directory);
            if(full.IndexOfAny(new[]{'"','\r','\n'})>=0||String.Equals(full.TrimEnd('\\'),Path.GetPathRoot(full).TrimEnd('\\'),StringComparison.OrdinalIgnoreCase))throw new ArgumentException("Geçerli bir uygulama klasörü seçin.");
            // NSIS requires /D to be last and preserves spaces in its unquoted directory value.
            return "/S /D="+full;
        }
        internal static bool Silent(string[] arguments) { return arguments.Any(value=>value.Equals("/S",StringComparison.OrdinalIgnoreCase)||value.Equals("/P",StringComparison.OrdinalIgnoreCase)); }
        internal static string OriginalArguments() {
            var command=Environment.CommandLine;
            var end=command.StartsWith("\"",StringComparison.Ordinal)?command.IndexOf('"',1)+1:command.IndexOf(' ');
            return end<=0?"":command.Substring(end).TrimStart();
        }
    }
}
