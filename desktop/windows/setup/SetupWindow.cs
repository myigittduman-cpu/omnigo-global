using System;
using System.Diagnostics;
using System.IO;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Markup;
using System.Windows.Media;
using System.Windows.Media.Animation;
using System.Windows.Media.Imaging;
using Forms=System.Windows.Forms;

namespace Omnigo.Setup {
    internal sealed class SetupWindow {
        internal readonly Window Window;
        private readonly SetupConfig config;
        private bool busy,complete;
        private string destination;
        private readonly TextBlock status,heading;
        private readonly TextBox location;
        private readonly Button install,browse,close;
        private readonly ProgressBar progress;
        internal SetupWindow(SetupConfig config) {
            this.config=config; destination=config.DefaultDirectory;
            using(var source=SetupConfig.Resource("Setup.View"))Window=(Window)XamlReader.Load(source);
            Window.Title=config.Name+" Kurulumu";
            ((TextBlock)Window.FindName("ProductName")).Text=config.Name;
            ((TextBlock)Window.FindName("Version")).Text="Windows · "+config.Version;
            status=(TextBlock)Window.FindName("Status"); heading=(TextBlock)Window.FindName("Heading");
            location=(TextBox)Window.FindName("Location");location.Text=destination;
            install=(Button)Window.FindName("Install"); browse=(Button)Window.FindName("Browse"); close=(Button)Window.FindName("Close");
            progress=(ProgressBar)Window.FindName("Progress");
            using(var source=SetupConfig.Resource("Setup.Logo")) {
                var image=new BitmapImage();image.BeginInit();image.CacheOption=BitmapCacheOption.OnLoad;image.StreamSource=source;image.EndInit();image.Freeze();
                ((Image)Window.FindName("Logo")).Source=image;
                ((System.Windows.Shapes.Rectangle)Window.FindName("Shine")).OpacityMask=new ImageBrush(image){Stretch=Stretch.Fill};
            }
            using(var source=SetupConfig.Resource("Setup.Icon"))Window.Icon=BitmapFrame.Create(source,BitmapCreateOptions.None,BitmapCacheOption.OnLoad);
            ((Border)Window.FindName("DragArea")).MouseLeftButtonDown+=(sender,args)=>{if(args.ButtonState==MouseButtonState.Pressed)Window.DragMove();};
            close.Click+=(sender,args)=>Window.Close();
            ((Button)Window.FindName("Minimize")).Click+=(sender,args)=>Window.WindowState=WindowState.Minimized;
            browse.Click+=(sender,args)=>ChooseDirectory();
            install.Click+=async(sender,args)=>await Install();
            Window.Closing+=(sender,args)=>{if(busy){args.Cancel=true;status.Text="Kurulum sürüyor. Tamamlandığında pencereyi kapatabilirsiniz.";}};
            if(SystemParameters.ClientAreaAnimation) {
                var transform=(TranslateTransform)Window.FindName("ShineMove");
                transform.BeginAnimation(TranslateTransform.XProperty,new DoubleAnimation(-1.6,1.6,TimeSpan.FromSeconds(2)){RepeatBehavior=RepeatBehavior.Forever});
            }
        }
        private void ChooseDirectory() {
            using(var dialog=new Forms.FolderBrowserDialog { Description="Uygulamanın kurulacağı klasörü seçin.",SelectedPath=Directory.Exists(destination)?destination:Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),ShowNewFolderButton=true }) {
                if(dialog.ShowDialog()!=Forms.DialogResult.OK)return;
                destination=Path.Combine(dialog.SelectedPath,config.Folder);location.Text=destination;
            }
        }
        private async Task Install() {
            if(complete) {
                try { Process.Start(new ProcessStartInfo(Path.Combine(destination,"omnigo-desktop.exe")){UseShellExecute=true});Window.Close(); }
                catch(Exception){status.Text="Uygulama açılamadı. Masaüstündeki kısayolu kullanabilirsiniz.";}
                return;
            }
            try {
                if(RunningApplications.Any(config,destination)) {
                    heading.Text="Uygulamadan tamamen çıkın";
                    status.Text="Açık işlerinizi kaydedip "+config.Name+" uygulamasından tamamen çıkın; ardından yeniden Kurulumu başlat'a basın.";
                    return;
                }
            } catch(Exception error) { status.Text=error.Message;return; }
            busy=true;install.IsEnabled=browse.IsEnabled=close.IsEnabled=false;progress.Visibility=Visibility.Visible;progress.IsIndeterminate=true;
            heading.Text="Çalışma alanınız kuruluyor";status.Text="Uygulama dosyaları ve kısayollar hazırlanıyor…";
            try {
                var arguments=SetupPayload.InstallArguments(destination);
                var exit=await Task.Run(()=>SetupPayload.Run(config,arguments));
                if(exit!=0&&exit!=3010)throw new IOException("Kurulum tamamlanamadı. Kod: "+exit+". Tekrar deneyebilirsiniz.");
                complete=true;heading.Text="Kurulum tamamlandı";status.Text="Masaüstü ve Başlat kısayollarınız hazır.";
                install.Content="Uygulamayı aç";progress.IsIndeterminate=false;progress.Value=100;
            } catch(Exception error) {
                heading.Text="Kurulum tamamlanamadı";status.Text=error.Message;progress.IsIndeterminate=false;progress.Value=0;
            } finally { busy=false;install.IsEnabled=close.IsEnabled=true;browse.IsEnabled=!complete; }
        }
    }
}
