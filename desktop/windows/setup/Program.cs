using System;
using System.Globalization;
using System.Windows;

namespace Omnigo.Setup {
    internal static class Program {
        [STAThread]
        private static int Main(string[] arguments) {
            CultureInfo.DefaultThreadCurrentCulture=CultureInfo.GetCultureInfo("tr-TR");
            CultureInfo.DefaultThreadCurrentUICulture=CultureInfo.GetCultureInfo("tr-TR");
            try {
                var config=SetupConfig.Load();
                if(SetupPayload.Silent(arguments))return SetupPayload.Run(config,SetupPayload.OriginalArguments());
                var application=new Application { ShutdownMode=ShutdownMode.OnMainWindowClose };
                return application.Run(new SetupWindow(config).Window);
            } catch(Exception error) {
                MessageBox.Show(error.Message,"Omnigo kurulum",MessageBoxButton.OK,MessageBoxImage.Error);
                return 1;
            }
        }
    }
}
