package com.omnigoglobal.client;

import android.app.Activity;
import android.content.ClipData;
import android.content.Intent;
import android.net.Uri;
import java.io.File;
import java.nio.file.Files;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

final class ReportSharer {
    private final Activity activity;
    private final ExecutorService worker=Executors.newSingleThreadExecutor();
    private boolean busy;
    ReportSharer(Activity activity){this.activity=activity;}
    void share(String filename,String contents,ReportSaver.Completion callback){
        if(busy){callback.finish(null,"Önce açık paylaşım işlemini tamamlayın.");return;}busy=true;
        worker.execute(()->{
            try{
                ReportPayload report=ReportPayload.parse(filename,contents);
                if(!report.mime.equals("application/pdf")&&!report.filename.endsWith(".xlsx")&&!report.mime.equals("image/jpeg"))throw new IllegalArgumentException("Bu belge biçimi paylaşılamıyor.");
                File root=new File(activity.getCacheDir(),"report-shares");
                if(!root.isDirectory()&&!root.mkdirs())throw new java.io.IOException();
                cleanup(root);
                String token=UUID.randomUUID().toString();File folder=new File(root,token);
                if(!folder.mkdir())throw new java.io.IOException();
                File file=new File(folder,report.filename);Files.write(file.toPath(),report.bytes);
                Uri uri=new Uri.Builder().scheme("content").authority(activity.getPackageName()+".reports").appendPath(token).appendPath(report.filename).build();
                activity.runOnUiThread(()->{
                    try{
                        if(activity.isFinishing()||activity.isDestroyed())throw new IllegalStateException();
                        Intent intent=new Intent(Intent.ACTION_SEND).setType(report.mime).putExtra(Intent.EXTRA_STREAM,uri)
                            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                    intent.setClipData(ClipData.newRawUri(report.filename,uri));
                        Intent selected=null;
                        for(String target:new String[]{"com.whatsapp","com.whatsapp.w4b"}){
                            Intent candidate=new Intent(intent).setPackage(target);
                            if(candidate.resolveActivity(activity.getPackageManager())!=null){selected=candidate;break;}
                        }
                        if(selected==null){callback.finish(null,"WhatsApp bulunamadı. WhatsApp veya WhatsApp Business yükleyip yeniden deneyin.");return;}
                        activity.startActivity(selected);callback.finish("opened",null);
                    }catch(Exception error){callback.finish(null,"WhatsApp paylaşım ekranı açılamadı.");}finally{busy=false;}
                });
            }catch(Exception error){activity.runOnUiThread(()->{busy=false;callback.finish(null,error instanceof IllegalArgumentException?error.getMessage():"Paylaşılacak belge hazırlanamadı.");});}
        });
    }
    private void cleanup(File root) throws java.io.IOException {
        File[] folders=root.listFiles();if(folders==null)return;
        String prefix=root.getCanonicalPath()+File.separator;long cutoff=System.currentTimeMillis()-86400000L;
        for(File folder:folders){
            if(!folder.getName().matches("[a-f0-9-]{36}")||!folder.getCanonicalPath().startsWith(prefix)||folder.lastModified()>=cutoff)continue;
            File[] files=folder.listFiles();if(files!=null)for(File file:files)if(file.isFile()&&file.getCanonicalPath().startsWith(folder.getCanonicalPath()+File.separator))file.delete();
            folder.delete();
        }
    }
    void close(){worker.shutdown();}
}
