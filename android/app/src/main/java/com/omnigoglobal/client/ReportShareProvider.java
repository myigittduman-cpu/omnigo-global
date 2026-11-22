package com.omnigoglobal.client;

import android.content.ContentProvider;
import android.content.ContentValues;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.net.Uri;
import android.os.ParcelFileDescriptor;
import android.provider.OpenableColumns;
import java.io.File;
import java.io.FileNotFoundException;

/** Only explicitly granted, generated report files can leave the private cache. */
public final class ReportShareProvider extends ContentProvider {
    @Override public boolean onCreate() { return true; }
    private File file(Uri uri) throws FileNotFoundException {
        if (getContext() == null || !"content".equals(uri.getScheme()) ||
            !(getContext().getPackageName()+".reports").equals(uri.getAuthority()) || uri.getPathSegments().size()!=2)
            throw new FileNotFoundException("Belge yolu geçersiz.");
        String folder=uri.getPathSegments().get(0),name=uri.getPathSegments().get(1);
        if (!folder.matches("[a-f0-9-]{36}") || name.contains("/") || name.contains("\\") || name.startsWith("."))
            throw new FileNotFoundException("Belge yolu geçersiz.");
        try {
            File root=new File(getContext().getCacheDir(),"report-shares").getCanonicalFile();
            File result=new File(new File(root,folder),name).getCanonicalFile();
            if (!result.getPath().startsWith(root.getPath()+File.separator) || !result.isFile() || result.length()>ReportPayload.MAX_BYTES)
                throw new FileNotFoundException("Belge bulunamadı.");
            return result;
        } catch (java.io.IOException error) { throw new FileNotFoundException("Belge açılamadı."); }
    }
    @Override public ParcelFileDescriptor openFile(Uri uri,String mode) throws FileNotFoundException {
        if (!"r".equals(mode)) throw new FileNotFoundException("Belge yalnız okunabilir.");
        return ParcelFileDescriptor.open(file(uri),ParcelFileDescriptor.MODE_READ_ONLY);
    }
    @Override public String getType(Uri uri) {
        String name=uri.getLastPathSegment();
        if(name!=null&&name.endsWith(".xlsx"))return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
        if(name!=null&&name.endsWith(".pdf"))return "application/pdf";
        return "image/jpeg";
    }
    @Override public Cursor query(Uri uri,String[] projection,String selection,String[] args,String sortOrder) {
        try {File target=file(uri);String[] columns=projection==null?new String[]{OpenableColumns.DISPLAY_NAME,OpenableColumns.SIZE}:projection;
            MatrixCursor cursor=new MatrixCursor(columns,1);Object[] row=new Object[columns.length];
            for(int i=0;i<columns.length;i++)row[i]=OpenableColumns.DISPLAY_NAME.equals(columns[i])?target.getName():OpenableColumns.SIZE.equals(columns[i])?target.length():null;
            cursor.addRow(row);return cursor;
        } catch(FileNotFoundException error){return null;}
    }
    @Override public Uri insert(Uri uri,ContentValues values){throw new UnsupportedOperationException();}
    @Override public int update(Uri uri,ContentValues values,String selection,String[] args){throw new UnsupportedOperationException();}
    @Override public int delete(Uri uri,String selection,String[] args){throw new UnsupportedOperationException();}
}
