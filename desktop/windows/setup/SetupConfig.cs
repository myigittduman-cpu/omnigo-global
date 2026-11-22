using System;
using System.IO;
using System.Reflection;
using System.Xml.Linq;

namespace Omnigo.Setup {
    internal sealed class SetupConfig {
        internal string Name, Folder, Hash, Version;
        internal static Stream Resource(string name) {
            var stream=Assembly.GetExecutingAssembly().GetManifestResourceStream(name);
            if(stream==null)throw new InvalidDataException("Kurulum dosyası eksik.");
            return stream;
        }
        internal static SetupConfig Load() {
            using(var stream=Resource("Setup.Config")) {
                var xml=XElement.Load(stream);
                return new SetupConfig { Name=(string)xml.Element("Name"), Folder=(string)xml.Element("Folder"), Hash=(string)xml.Element("Hash"), Version=(string)xml.Element("Version") };
            }
        }
        internal string DefaultDirectory { get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),Folder); } }
    }
}
